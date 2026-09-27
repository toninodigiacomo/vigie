<?php
declare(strict_types=1);

// Endpoints used by agents. Always initiated by the agent (pull model).

/** Fast sync window after an enrolment or a change (minutes). */
function vigie_fast_window(): int
{
    return max(0, (int) (getenv('VIGIE_FAST_WINDOW') ?: 15)) * 60;
}

/** Identifies the server from the token sent in the request body. */
function agent_auth(array $d): array
{
    $token = (string) ($d['token'] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) vigie_error('token_missing', [], 401);
    $st = vigie_db()->prepare('SELECT * FROM servers WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    return $st->fetch() ?: vigie_error('token_unknown', [], 401);
}

/** Normalises the catalogue sent by the agent (unknown types are dropped). */
function agent_catalog(mixed $raw): array
{
    $out = [];
    foreach (is_array($raw) ? $raw : [] as $t) {
        if (!is_array($t) || !preg_match('/^[a-z0-9-]{1,64}$/', (string) ($t['name'] ?? ''))) continue;
        $params = [];
        foreach (is_array($t['params'] ?? null) ? $t['params'] : [] as $p) {
            if (!preg_match('/^[A-Z_][A-Z0-9_]{0,63}$/', (string) ($p['name'] ?? ''))) continue;
            if (!isset(VIGIE_PARAM_TYPES[$p['type'] ?? ''])) continue;
            $params[] = [
                'name'     => $p['name'],
                'type'     => $p['type'],
                'required' => (bool) ($p['required'] ?? false),
                'label'    => mb_substr((string) ($p['label'] ?? ''), 0, 200),
                'default'  => mb_substr((string) ($p['default'] ?? ''), 0, 500),
            ];
        }
        $out[$t['name']] = [
            'name'        => $t['name'],
            'descr'       => mb_substr((string) ($t['descr'] ?? $t['name']), 0, 200),
            'max_age'     => (int) ($t['max_age'] ?? 1560),
            'max_runtime' => (int) ($t['max_runtime'] ?? 60),
            'params'      => $params,
        ];
    }
    // A former task file left next to its renamed version must not show up twice
    foreach (VIGIE_TASK_ALIASES as $new => $old) {
        if (isset($out[$new])) unset($out[$old]);
    }
    return $out;
}

function agent_store_facts(int $serverId, array $d): void
{
    vigie_db()->prepare(<<<'SQL'
        UPDATE servers SET facts = ?, catalog = ?, sync_errors = ?, agent_version = ?, last_seen = ?
        WHERE id = ?
        SQL)->execute([
        json_encode(is_array($d['facts'] ?? null) ? $d['facts'] : new stdClass(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        json_encode(agent_catalog($d['catalog'] ?? []), JSON_UNESCAPED_UNICODE),
        json_encode(array_slice(array_map('strval', is_array($d['errors'] ?? null) ? $d['errors'] : []), 0, 50), JSON_UNESCAPED_UNICODE),
        mb_substr((string) ($d['version'] ?? ''), 0, 20),
        time(),
        $serverId,
    ]);
}

// POST /api/agent/enroll  {code, version, facts, catalog}
function agent_enroll(): never
{
    $d = vigie_body();
    $code = strtoupper(trim((string) ($d['code'] ?? '')));
    if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code)) vigie_error('enroll_code_malformed');
    $db = vigie_db();
    $st = $db->prepare('SELECT * FROM servers WHERE enroll_hash = ? AND enroll_expires > ?');
    $st->execute([hash('sha256', $code), time()]);
    $srv = $st->fetch();
    if (!$srv) {
        vigie_audit('anonymous', 'enroll.refused', 'invalid or expired code');
        vigie_error('enroll_code_invalid', [], 403);
    }
    $token = vigie_token();
    $now = time();
    // All or nothing: a code is only consumed if the enrolment fully succeeds
    $db->beginTransaction();
    $db->prepare(<<<'SQL'
        UPDATE servers SET token_hash = ?, enroll_hash = NULL, enroll_expires = NULL,
                           enrolled_at = ?, fast_until = ?
        WHERE id = ? AND enroll_hash IS NOT NULL
        SQL)->execute([hash('sha256', $token), $now, $now + vigie_fast_window(), $srv['id']]);
    agent_store_facts((int) $srv['id'], $d);
    vigie_audit("agent:{$srv['name']}", 'enroll', 'agent enrolled, new token issued');
    $db->commit();
    vigie_text(['token ' . $token, 'server ' . vigie_b64($srv['name'])]);
}

/**
 * POST /api/agent/poll  {token}
 * Quick check, every minute (agent 2.9+): is there anything for this agent?
 * A pending request or change makes the agent run a full sync right away, so
 * tests and changes take effect within a minute whatever the sync interval.
 */
function agent_poll(): never
{
    $srv = agent_auth(vigie_body());
    $db = vigie_db();
    $st = $db->prepare(<<<'SQL'
        SELECT COUNT(*) FROM jobs j JOIN services s ON s.id = j.service_id
        WHERE s.server_id = ? AND j.state = 'pending'
        SQL);
    $st->execute([$srv['id']]);
    $pending = (int) $srv['fast_pending'] > 0 || (int) $st->fetchColumn() > 0;
    $db->prepare('UPDATE servers SET last_poll = ? WHERE id = ?')->execute([time(), $srv['id']]);
    vigie_text(['sync ' . ($pending ? 1 : 0)]);
}

// POST /api/agent/sync  {token, version, facts, catalog, errors}
function agent_sync(): never
{
    $d = vigie_body();
    $srv = agent_auth($d);
    $db = vigie_db();
    $now = time();
    agent_store_facts((int) $srv['id'], $d);

    // A requested fast window starts now, when the agent actually receives it
    if ((int) $srv['fast_pending'] > 0) {
        $srv['fast_until'] = max((int) $srv['fast_until'], $now + (int) $srv['fast_pending'] * 60);
        $db->prepare('UPDATE servers SET fast_until = ?, fast_pending = 0 WHERE id = ?')
           ->execute([$srv['fast_until'], $srv['id']]);
    }

    // Requests that never came back are considered lost after 6 hours
    $db->prepare("UPDATE jobs SET state = 'expired' WHERE state = 'sent' AND sent_at < ?")
       ->execute([$now - 6 * 3600]);

    $lines = [
        'server_time ' . $now,
        'interval ' . (int) $srv['poll_interval'],
        'fast_until ' . (int) $srv['fast_until'],
    ];

    // The order of the lines is the order of the crontab (agent 2.3+)
    $st = $db->prepare('SELECT * FROM services WHERE server_id = ? ORDER BY position, id');
    $st->execute([$srv['id']]);
    // Each agent receives the task name it knows (renamed tasks, see VIGIE_TASK_ALIASES)
    $catalog = json_decode((string) $db->query('SELECT catalog FROM servers WHERE id = ' . (int) $srv['id'])->fetchColumn(), true) ?: [];
    foreach ($st->fetchAll() as $s) {
        $s['task'] = vigie_task_key($catalog, $s['task']) ?? $s['task'];
        $lines[] = sprintf(
            'service %d %d %s %s %s %s %s',
            $s['id'], $s['enabled'] ? 1 : 0,
            vigie_b64($s['task']), vigie_b64($s['name']), vigie_b64($s['schedule']),
            $s['max_age'] ?? '-', $s['max_runtime'] ?? '-'
        );
        foreach (json_decode($s['params'], true) ?: [] as $k => $v) {
            $lines[] = sprintf('param %d %s %s', $s['id'], $k, vigie_b64((string) $v));
        }
    }

    $st = $db->prepare(<<<'SQL'
        SELECT j.id, j.kind, j.service_id FROM jobs j JOIN services s ON s.id = j.service_id
        WHERE s.server_id = ? AND j.state = 'pending' ORDER BY j.id
        SQL);
    $st->execute([$srv['id']]);
    $jobs = $st->fetchAll();
    foreach ($jobs as $j) {
        $lines[] = sprintf('job %d %s %d', $j['id'], $j['kind'], $j['service_id']);
    }
    if ($jobs) {
        $in = implode(',', array_map('intval', array_column($jobs, 'id')));
        $db->exec("UPDATE jobs SET state = 'sent', sent_at = $now WHERE id IN ($in)");
    }
    vigie_text($lines);
}

/** Checks that a service belongs to the authenticated server. */
function agent_service(array $srv, mixed $serviceId): array
{
    $st = vigie_db()->prepare('SELECT * FROM services WHERE id = ? AND server_id = ?');
    $st->execute([(int) $serviceId, $srv['id']]);
    return $st->fetch() ?: vigie_error('service_unknown_here', [], 404);
}

// POST /api/agent/report  run report (running or final)
function agent_report(): never
{
    $d = vigie_body();
    $srv = agent_auth($d);
    $svc = agent_service($srv, $d['service_id'] ?? 0);

    $status = $d['status'] ?? '';
    if (!in_array($status, ['running', 'success', 'warning', 'error'], true)) vigie_error('invalid_status');
    if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', (string) ($d['run_id'] ?? ''))) vigie_error('invalid_run_id');
    $int = static fn(string $k): ?int => isset($d[$k]) && is_numeric($d[$k]) ? (int) $d[$k] : null;
    $str = static fn(string $k, int $max): string => mb_substr(is_string($d[$k] ?? null) ? $d[$k] : '', 0, $max);
    $now = time();
    $db = vigie_db();

    // A final report is never overwritten by a late "running" signal.
    // MariaDB evaluates assignments in order: "status" must stay last.
    $db->prepare(<<<'SQL'
        INSERT INTO runs (service_id, run_id, job_id, status, started_at, finished_at, duration,
                          exit_code, heartbeat, summary, metrics, log_tail, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            finished_at = IF(status = 'running', VALUES(finished_at), finished_at),
            duration    = IF(status = 'running', VALUES(duration), duration),
            exit_code   = IF(status = 'running', VALUES(exit_code), exit_code),
            summary     = IF(status = 'running', VALUES(summary), summary),
            metrics     = IF(status = 'running', VALUES(metrics), metrics),
            log_tail    = IF(status = 'running', VALUES(log_tail), log_tail),
            updated_at  = IF(status = 'running', VALUES(updated_at), updated_at),
            status      = IF(status = 'running', VALUES(status), status)
        SQL)->execute([
        $svc['id'], $d['run_id'], $int('job_id'), $status,
        $int('started_at') ?? $now, $int('finished_at'), $int('duration'), $int('exit_code'),
        max(60, $int('heartbeat') ?? 300), $str('summary', 500),
        json_encode(is_array($d['metrics'] ?? null) ? (object) $d['metrics'] : new stdClass(), JSON_UNESCAPED_UNICODE),
        $str('log_tail', 64000), $now,
    ]);

    if ($status !== 'running' && $int('job_id')) {
        $db->prepare(<<<'SQL'
            UPDATE jobs SET state = 'done', result_ok = ?, done_at = ?, output = ?
            WHERE id = ? AND service_id = ? AND kind = 'run'
            SQL)->execute([$status === 'error' ? 0 : 1, $now, $str('log_tail', 64000), $int('job_id'), $svc['id']]);
    }
    $db->prepare('UPDATE servers SET last_seen = ? WHERE id = ?')->execute([$now, $srv['id']]);

    if (random_int(1, 50) === 1) {
        $db->prepare('DELETE FROM runs WHERE started_at < ?')->execute([$now - 180 * 86400]);
        $db->prepare('DELETE FROM audit WHERE at < ?')->execute([$now - 365 * 86400]);
    }
    vigie_json(['ok' => true]);
}

// POST /api/agent/job  result of a check {token, job_id, ok, output}
function agent_job(): never
{
    $d = vigie_body();
    $srv = agent_auth($d);
    $st = vigie_db()->prepare(<<<'SQL'
        SELECT j.* FROM jobs j JOIN services s ON s.id = j.service_id
        WHERE j.id = ? AND s.server_id = ?
        SQL);
    $st->execute([(int) ($d['job_id'] ?? 0), $srv['id']]);
    $job = $st->fetch() ?: vigie_error('job_unknown_here', [], 404);
    vigie_db()->prepare('UPDATE jobs SET state = \'done\', result_ok = ?, output = ?, done_at = ? WHERE id = ?')->execute([
        !empty($d['ok']) ? 1 : 0,
        mb_substr(is_string($d['output'] ?? null) ? $d['output'] : '', 0, 64000),
        time(), $job['id'],
    ]);
    vigie_json(['ok' => true]);
}
