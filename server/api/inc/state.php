<?php
declare(strict_types=1);

// Service and agent health, shared by the dashboard and the Homepage widget.
// Health and reasons are returned as codes: the web interface translates them.

const VIGIE_HEALTH = [
    //  code        level  display order
    'error'   => ['err',  0],
    'lost'    => ['err',  0],
    'late'    => ['err',  0],
    'overrun' => ['warn', 1],
    'warning' => ['warn', 1],
    'unknown' => ['warn', 1],
    'running' => ['run',  2],
    'ok'      => ['ok',   3],
];

/** Agent state: an agent is silent once it has missed three syncs. */
function vigie_agents(PDO $db, int $now): array
{
    $out = [];
    foreach ($db->query('SELECT * FROM servers ORDER BY name') as $s) {
        $interval = (int) $s['poll_interval'] * 60;
        if ($s['token_hash'] === null) {
            $state = 'pending';
        } elseif ($s['last_seen'] === null || $now - (int) $s['last_seen'] > 3 * $interval + 120) {
            $state = 'silent';
        } else {
            $state = 'ok';
        }
        $out[$s['name']] = [
            'id'        => (int) $s['id'],
            'name'      => $s['name'],
            'descr'     => $s['descr'],
            'state'     => $state,
            'last_seen' => $s['last_seen'] !== null ? (int) $s['last_seen'] : null,
            'interval'  => (int) $s['poll_interval'],
            'fast'      => (int) $s['fast_until'] > $now,
            'version'   => $s['agent_version'],
            'errors'    => json_decode($s['sync_errors'] ?? '[]', true) ?: [],
        ];
    }
    return $out;
}

/**
 * Enabled services, plus those started by another service (a dump run by Borg)
 * or manually in the last 30 days, with their computed health.
 */
function vigie_tasks(PDO $db, int $now): array
{
    $history = $db->prepare(<<<'SQL'
        SELECT id, job_id, status, started_at, finished_at, duration, exit_code, heartbeat, summary, updated_at
        FROM runs WHERE service_id = ? ORDER BY started_at DESC, id DESC LIMIT 24
        SQL);
    $lastOk = $db->prepare(<<<'SQL'
        SELECT MAX(finished_at) FROM runs WHERE service_id = ? AND status IN ('success', 'warning')
        SQL);
    $typical = $db->prepare(<<<'SQL'
        SELECT AVG(duration) FROM (
            SELECT duration FROM runs
            WHERE service_id = ? AND status IN ('success', 'warning') AND duration IS NOT NULL
            ORDER BY started_at DESC LIMIT 5) AS recent
        SQL);

    $services = $db->query(<<<'SQL'
        SELECT s.*, v.name AS server_name, v.catalog
        FROM services s JOIN servers v ON v.id = s.server_id
        WHERE s.enabled = 1
           OR EXISTS (SELECT 1 FROM runs r WHERE r.service_id = s.id AND r.started_at > UNIX_TIMESTAMP() - 30 * 86400)
        ORDER BY v.name, s.position, s.id
        SQL)->fetchAll();

    $tasks = [];
    foreach ($services as $s) {
        $catalog = json_decode($s['catalog'] ?? '{}', true) ?: [];
        $def = ($k = vigie_task_key($catalog, $s['task'])) !== null ? $catalog[$k] : [];
        $history->execute([$s['id']]);
        $runs = $history->fetchAll();
        $lastOk->execute([$s['id']]);
        $okAt = $lastOk->fetchColumn() ?: null;
        $typical->execute([$s['id']]);
        $avg = $typical->fetchColumn();

        $last = $runs[0] ?? null;
        $previous = null;
        foreach ($runs as $r) {
            if ($r['status'] !== 'running') { $previous = $r; break; }
        }

        $maxAge = vigie_max_age($s['max_age'] !== null ? (int) $s['max_age'] : null, $s['schedule'], (bool) $s['enabled'],
                                isset($def['max_age']) ? (int) $def['max_age'] : null) * 60;
        $maxRun = (int) ($s['max_runtime'] ?? $def['max_runtime'] ?? 60) * 60;
        $reason = null;

        if ($last === null) {
            $health = 'unknown';
        } elseif ($last['status'] === 'running') {
            $beat = max(60, (int) $last['heartbeat']);
            if ($now - $last['updated_at'] > 3 * $beat) {
                $health = 'lost';
                $reason = ['code' => 'lost', 'params' => ['since' => $now - (int) $last['updated_at']]];
            } elseif ($now - $last['started_at'] > $maxRun) {
                $health = 'overrun';
                $reason = ['code' => 'overrun', 'params' => ['elapsed' => $now - (int) $last['started_at'], 'limit' => $maxRun]];
            } else {
                $health = 'running';
            }
        } elseif ($last['status'] === 'error') {
            $health = 'error';
            $reason = $last['summary'] ? ['code' => 'summary', 'params' => ['text' => $last['summary']]]
                                       : ['code' => 'failed_silent', 'params' => []];
        } elseif ($okAt === null || $now - $okAt > $maxAge) {
            $health = 'late';
            $reason = $okAt === null
                ? ['code' => 'never_succeeded', 'params' => []]
                : ['code' => 'late', 'params' => ['since' => $now - (int) $okAt, 'limit' => $maxAge]];
        } elseif ($last['status'] === 'warning') {
            $health = 'warning';
            $reason = ['code' => 'summary', 'params' => ['text' => (string) $last['summary']]];
        } else {
            $health = 'ok';
        }

        $tasks[] = [
            'id'          => (int) $s['id'],
            'enabled'     => (bool) $s['enabled'],
            'host'        => $s['server_name'],
            'task'        => $s['task'],
            'anchor'      => 't-' . $s['id'],
            'descr'       => $s['name'],
            'schedule'    => $s['schedule'],
            'health'      => $health,
            'level'       => VIGIE_HEALTH[$health][0],
            'reason'      => $reason,
            'last'        => $last,
            'previous'    => $previous,
            'last_ok'     => $okAt ? (int) $okAt : null,
            'typical'     => $avg !== false && $avg !== null ? (int) round((float) $avg) : null,
            'max_age'     => $maxAge,
            'max_runtime' => $maxRun,
            'history'     => array_reverse($runs),
        ];
    }
    return $tasks;
}

// GET /api/state          full state for the dashboard
// GET /api/state?run=ID   details of one run
function api_state(): never
{
    $db  = vigie_db();
    $now = time();
    if (isset($_GET['run'])) {
        $st = $db->prepare(<<<'SQL'
            SELECT r.*, s.name AS service_name, v.name AS server_name
            FROM runs r JOIN services s ON s.id = r.service_id JOIN servers v ON v.id = s.server_id
            WHERE r.id = ?
            SQL);
        $st->execute([(int) $_GET['run']]);
        $run = $st->fetch() ?: vigie_error('job_not_found', [], 404);
        $run['metrics'] = json_decode($run['metrics'] ?: '{}') ?: new stdClass();
        vigie_json($run);
    }

    $agents = vigie_agents($db, $now);
    $hosts = [];
    foreach ($agents as $name => $a) {
        if ($a['state'] !== 'pending') $hosts[$name] = ['host' => $name, 'agent' => $a, 'tasks' => []];
    }
    foreach (vigie_tasks($db, $now) as $t) {
        $hosts[$t['host']]['tasks'][] = $t;
    }
    vigie_json(['now' => $now, 'hosts' => array_values($hosts)]);
}

// ── Homepage widget ─────────────────────────────────────────────────────────
// Homepage cannot translate: the text is rendered here, in the ?lang= language.

const VIGIE_HOMEPAGE_TEXT = [
    'en' => [
        'error' => 'Error', 'lost' => 'No news', 'late' => 'Late', 'overrun' => 'Too long', 'warning' => 'Warning',
        'unknown' => 'Never run', 'running' => 'Running', 'ok' => 'OK',
        'running_for' => 'Running for {d}', 'overrun_for' => 'Too long, {d}', 'lost_for' => 'No news for {d}',
        'late_success' => 'Late, last success {ago}', 'late_never' => 'Late, never succeeded',
        'agent' => 'Agent {name}', 'agent_silent' => 'Silent agent', 'silent_for' => 'Silent for {d}',
        'never_connected' => 'Never connected', 'agent_detail' => 'The agent no longer syncs with Vigie.',
        'all_good' => 'All good', 'no_issue' => 'No issue', 'issues_one' => '{n} item to check', 'issues_other' => '{n} items to check',
        'reason_lost' => 'No signal for {d}: the task or the server stopped midway.',
        'reason_overrun' => 'Running for {d}, beyond the planned {limit}.',
        'reason_failed_silent' => 'Failed without a message, see the log.',
        'reason_never_succeeded' => 'No successful run recorded.',
        'reason_late' => 'Last success {d} ago, expected at least every {limit}.',
    ],
    'fr' => [
        'error' => 'Erreur', 'lost' => 'Sans nouvelles', 'late' => 'En retard', 'overrun' => 'Trop longue', 'warning' => 'Attention',
        'unknown' => 'Jamais lancée', 'running' => 'En cours', 'ok' => 'OK',
        'running_for' => 'En cours depuis {d}', 'overrun_for' => 'Trop longue, {d}', 'lost_for' => 'Sans nouvelles depuis {d}',
        'late_success' => 'En retard, dernier succès {ago}', 'late_never' => 'En retard, aucun succès',
        'agent' => 'Agent {name}', 'agent_silent' => 'Agent muet', 'silent_for' => 'Muet depuis {d}',
        'never_connected' => 'Jamais connecté', 'agent_detail' => 'L\'agent ne se synchronise plus avec Vigie.',
        'all_good' => 'Tout est en ordre', 'no_issue' => 'Aucun problème', 'issues_one' => '{n} point à vérifier', 'issues_other' => '{n} points à vérifier',
        'reason_lost' => 'Plus aucun signal depuis {d} : la tâche ou le serveur s\'est arrêté en cours de route.',
        'reason_overrun' => 'Tourne depuis {d}, au-delà des {limit} prévues.',
        'reason_failed_silent' => 'Échec sans message, voir le journal.',
        'reason_never_succeeded' => 'Aucune exécution réussie enregistrée.',
        'reason_late' => 'Dernier succès il y a {d}, attendu au moins toutes les {limit}.',
    ],
];

function vigie_hp(string $lang, string $key, array $p = []): string
{
    $s = VIGIE_HOMEPAGE_TEXT[$lang][$key] ?? VIGIE_HOMEPAGE_TEXT['en'][$key] ?? $key;
    foreach ($p as $k => $v) $s = str_replace('{' . $k . '}', (string) $v, $s);
    return $s;
}

function vigie_reason_text(?array $reason, string $lang): string
{
    if ($reason === null) return '';
    $p = $reason['params'];
    return match ($reason['code']) {
        'summary'         => (string) $p['text'],
        'lost'            => vigie_hp($lang, 'reason_lost', ['d' => vigie_duration($p['since'], $lang)]),
        'overrun'         => vigie_hp($lang, 'reason_overrun', ['d' => vigie_duration($p['elapsed'], $lang), 'limit' => vigie_duration($p['limit'], $lang)]),
        'late'            => vigie_hp($lang, 'reason_late', ['d' => vigie_duration($p['since'], $lang), 'limit' => vigie_duration($p['limit'], $lang)]),
        'failed_silent'   => vigie_hp($lang, 'reason_failed_silent'),
        'never_succeeded' => vigie_hp($lang, 'reason_never_succeeded'),
        default           => '',
    };
}

// GET /api/homepage[?filter=active|problems][&host=name][&lang=en|fr]
function api_homepage(): never
{
    $readToken = (string) getenv('VIGIE_READ_TOKEN');
    if ($readToken !== '' && !hash_equals($readToken, (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''))) {
        vigie_error('request_refused', [], 403);
    }
    $lang = vigie_lang($_GET['lang'] ?? 'en');
    $db = vigie_db();
    $now = time();
    $tasks = vigie_tasks($db, $now);
    $agents = vigie_agents($db, $now);

    $host = (string) ($_GET['host'] ?? '');
    if ($host !== '') {
        $tasks  = array_values(array_filter($tasks, fn($t) => $t['host'] === $host));
        $agents = array_filter($agents, fn($a) => $a['name'] === $host);
    }

    $count = ['ok' => 0, 'warn' => 0, 'err' => 0, 'run' => 0];
    $lastActivity = 0;
    foreach ($tasks as $t) {
        $count[$t['level']]++;
        if ($t['last']) {
            $lastActivity = max($lastActivity, (int) ($t['last']['finished_at'] ?? $t['last']['updated_at']));
        }
    }
    $silent = array_values(array_filter($agents, fn($a) => $a['state'] === 'silent'));
    $issues = $count['err'] + $count['warn'] + count($silent);

    $label = static function (array $t) use ($now, $lang): string {
        $last = $t['last'];
        $d = fn(int $s) => vigie_duration($s, $lang);
        return match ($t['health']) {
            'unknown' => vigie_hp($lang, 'unknown'),
            'running' => vigie_hp($lang, 'running_for', ['d' => $d($now - (int) $last['started_at'])]),
            'overrun' => vigie_hp($lang, 'overrun_for', ['d' => $d($now - (int) $last['started_at'])]),
            'lost'    => vigie_hp($lang, 'lost_for', ['d' => $d($now - (int) $last['updated_at'])]),
            'late'    => $t['last_ok'] ? vigie_hp($lang, 'late_success', ['ago' => vigie_ago($t['last_ok'], $now, $lang)])
                                       : vigie_hp($lang, 'late_never'),
            default   => vigie_hp($lang, $t['health']) . ', ' . vigie_ago((int) ($last['finished_at'] ?? $last['started_at']), $now, $lang),
        };
    };

    $seen = array_count_values(array_column($tasks, 'descr'));
    usort($tasks, fn($a, $b) => [VIGIE_HEALTH[$a['health']][1], $a['descr'], $a['host']]
                            <=> [VIGIE_HEALTH[$b['health']][1], $b['descr'], $b['host']]);

    $filter = (string) ($_GET['filter'] ?? '');
    $items = [];
    foreach ($silent as $a) {
        $items[] = [
            'name'   => vigie_hp($lang, 'agent', ['name' => $a['name']]),
            'label'  => $a['last_seen'] ? vigie_hp($lang, 'silent_for', ['d' => vigie_duration($now - $a['last_seen'], $lang)])
                                        : vigie_hp($lang, 'never_connected'),
            'status' => vigie_hp($lang, 'agent_silent'), 'level' => 'err', 'host' => $a['name'], 'task' => '',
            'anchor' => 'h-' . $a['id'], 'detail' => vigie_hp($lang, 'agent_detail'),
        ];
    }
    foreach ($tasks as $t) {
        if ($filter === 'problems' && !in_array($t['level'], ['err', 'warn'], true)) continue;
        if ($filter === 'active' && $t['level'] === 'ok') continue;
        $items[] = [
            'name'   => $seen[$t['descr']] > 1 ? "{$t['descr']} ({$t['host']})" : $t['descr'],
            'label'  => $label($t),
            'status' => vigie_hp($lang, $t['health']),
            'level'  => $t['level'],
            'host'   => $t['host'],
            'task'   => $t['task'],
            'anchor' => $t['anchor'],
            'detail' => vigie_reason_text($t['reason'], $lang) ?: (string) ($t['last']['summary'] ?? ''),
        ];
    }
    if ($items === [] && $filter !== '') {
        $items[] = ['name' => vigie_hp($lang, 'all_good'), 'label' => vigie_hp($lang, 'no_issue'), 'status' => 'OK',
                    'level' => 'ok', 'host' => '', 'task' => '', 'anchor' => '', 'detail' => ''];
    }

    vigie_json([
        'status'        => vigie_hp($lang, ($count['err'] || $silent) ? 'error' : ($count['warn'] ? 'warning' : 'ok')),
        'summary'       => $issues === 0 ? vigie_hp($lang, 'all_good')
                           : vigie_hp($lang, $issues > 1 ? 'issues_other' : 'issues_one', ['n' => $issues]),
        'total'         => count($tasks),
        'ok'            => $count['ok'],
        'running'       => $count['run'],
        'attention'     => $count['warn'],
        'errors'        => $count['err'],
        'agents_silent' => count($silent),
        'issues'        => $issues,
        // For Homepage's "adaptive" colour: negative (red) when there are issues
        'indicator'     => $issues > 0 ? -$issues : count($tasks),
        'last_activity' => $lastActivity ? date('c', $lastActivity) : null,
        'items'         => $items,
    ]);
}
