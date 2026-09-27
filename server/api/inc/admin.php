<?php
declare(strict_types=1);

// Administration logic, shared by the web interface and vigie-admin.
// Every function validates its input and throws VigieError with a message code.

/**
 * Every change requests a fast sync window so that the agent reacts quickly.
 * The window only starts when the agent picks it up (see agent_sync): an agent
 * syncing hourly would otherwise always arrive after it had expired.
 */
function admin_speed_up(int $serverId, ?int $minutes = null): void
{
    $minutes ??= intdiv(vigie_fast_window(), 60);
    if ($minutes <= 0) return;
    vigie_db()->prepare('UPDATE servers SET fast_pending = GREATEST(fast_pending, ?) WHERE id = ?')
        ->execute([$minutes, $serverId]);
}

function admin_server_row(int $id): array
{
    $st = vigie_db()->prepare('SELECT * FROM servers WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: throw new VigieError('server_not_found', [], 404);
}

function admin_server_by_name(string $name): array
{
    $st = vigie_db()->prepare('SELECT * FROM servers WHERE name = ?');
    $st->execute([$name]);
    return $st->fetch() ?: throw new VigieError('server_unknown', ['name' => $name], 404);
}

function admin_service_row(int $id): array
{
    $st = vigie_db()->prepare(<<<'SQL'
        SELECT s.*, v.name AS server_name, v.catalog, v.poll_interval, v.fast_until, v.last_seen
        FROM services s JOIN servers v ON v.id = s.server_id WHERE s.id = ?
        SQL);
    $st->execute([$id]);
    return $st->fetch() ?: throw new VigieError('service_not_found', [], 404);
}

function admin_task_def(array $server, string $task): array
{
    $catalog = json_decode($server['catalog'] ?? '{}', true) ?: [];
    if (!$catalog) throw new VigieError('no_catalog', ['server' => $server['name']]);
    $key = vigie_task_key($catalog, $task) ?? throw new VigieError('task_unknown', ['task' => $task, 'server' => $server['name']]);
    return $catalog[$key];
}

function admin_check_interval(int $i): int
{
    if (!in_array($i, VIGIE_INTERVALS, true)) {
        throw new VigieError('interval_invalid', ['allowed' => implode(', ', VIGIE_INTERVALS)]);
    }
    return $i;
}

/** Expected delay before the agent handles a request, for display. */
function admin_expected_delay(array $srv): array
{
    $now = time();
    // Agents that poll every minute (2.9+), or in a fast sync window, react within a minute
    return (int) ($srv['last_poll'] ?? 0) > $now - 180
        || (int) $srv['fast_until'] > $now && (int) $srv['last_seen'] > $now - 120
        ? ['code' => 'fast', 'minutes' => 1]
        : ['code' => 'next_sync', 'minutes' => (int) $srv['poll_interval']];
}

// ── Servers ────────────────────────────────────────────────────────────────

function admin_servers(): array
{
    $now = time();
    $agents = vigie_agents(vigie_db(), $now);
    $counts = vigie_db()->query('SELECT server_id, COUNT(*) n, SUM(enabled) active FROM services GROUP BY server_id')
        ->fetchAll(PDO::FETCH_UNIQUE);
    $rows = [];
    foreach (vigie_db()->query('SELECT id, name, descr, poll_interval, enroll_expires, facts, enrolled_at FROM servers ORDER BY name') as $s) {
        $f = json_decode($s['facts'] ?? '{}', true) ?: [];
        $rows[] = [
            'id'             => (int) $s['id'],
            'name'           => $s['name'],
            'descr'          => $s['descr'],
            'interval'       => (int) $s['poll_interval'],
            'agent'          => $agents[$s['name']],
            'os'             => $f['os'] ?? null,
            'enrolled'       => $s['enrolled_at'] !== null,
            'enroll_pending' => (int) ($s['enroll_expires'] ?? 0) > $now,
            'services'       => (int) ($counts[$s['id']]['n'] ?? 0),
            'active'         => (int) ($counts[$s['id']]['active'] ?? 0),
        ];
    }
    return $rows;
}

function admin_server(int $id): array
{
    $s = admin_server_row($id);
    $now = time();
    $st = vigie_db()->prepare(<<<'SQL'
        SELECT s.id, s.task, s.name, s.position, s.schedule, s.enabled, s.params,
               (SELECT status FROM runs r WHERE r.service_id = s.id ORDER BY started_at DESC LIMIT 1) AS last_status,
               (SELECT started_at FROM runs r WHERE r.service_id = s.id ORDER BY started_at DESC LIMIT 1) AS last_run
        FROM services s WHERE s.server_id = ? ORDER BY s.position, s.id
        SQL);
    $st->execute([$id]);
    $services = array_map(fn($r) => [
        'id' => (int) $r['id'], 'task' => $r['task'], 'name' => $r['name'], 'schedule' => $r['schedule'],
        'enabled' => (bool) $r['enabled'], 'last_status' => $r['last_status'],
        'last_run' => $r['last_run'] !== null ? (int) $r['last_run'] : null,
    ], $st->fetchAll());
    return [
        'id'             => (int) $s['id'],
        'name'           => $s['name'],
        'descr'          => $s['descr'],
        'interval'       => (int) $s['poll_interval'],
        'fast_until'     => (int) $s['fast_until'],
        'fast_pending'   => (int) $s['fast_pending'],
        'enrolled_at'    => $s['enrolled_at'] !== null ? (int) $s['enrolled_at'] : null,
        'enroll_expires' => (int) ($s['enroll_expires'] ?? 0) > $now ? (int) $s['enroll_expires'] : null,
        'revoked'        => $s['enrolled_at'] !== null && $s['token_hash'] === null,
        'last_seen'      => $s['last_seen'] !== null ? (int) $s['last_seen'] : null,
        'agent_version'  => $s['agent_version'],
        'facts'          => json_decode($s['facts'] ?? '{}', true) ?: new stdClass(),
        'catalog'        => array_values(json_decode($s['catalog'] ?? '{}', true) ?: []),
        'sync_errors'    => json_decode($s['sync_errors'] ?? '[]', true) ?: [],
        'services'       => $services,
        'now'            => $now,
        'public_url'     => rtrim((string) (getenv('VIGIE_PUBLIC_URL') ?: ''), '/'),
        'intervals'      => VIGIE_INTERVALS,
    ];
}

/** Creates a server and returns its enrolment code (shown only once). */
function admin_server_create(string $name, string $descr, int $interval, string $actor): array
{
    if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name)) {
        throw new VigieError('server_name_invalid');
    }
    admin_check_interval($interval);
    $st = vigie_db()->prepare('SELECT COUNT(*) FROM servers WHERE name = ?');
    $st->execute([$name]);
    if ($st->fetchColumn()) throw new VigieError('server_exists', ['name' => $name], 409);
    $code = vigie_enroll_code();
    $now = time();
    vigie_db()->prepare(<<<'SQL'
        INSERT INTO servers (name, descr, poll_interval, enroll_hash, enroll_expires, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
        SQL)->execute([$name, mb_substr($descr, 0, 200), $interval, hash('sha256', $code), $now + 1800, $now]);
    $id = (int) vigie_db()->lastInsertId();
    vigie_audit($actor, 'server.add', $name);
    return ['id' => $id, 'code' => $code, 'expires' => $now + 1800];
}

function admin_server_new_code(int $id, string $actor): array
{
    $s = admin_server_row($id);
    $code = vigie_enroll_code();
    $expires = time() + 1800;
    vigie_db()->prepare('UPDATE servers SET enroll_hash = ?, enroll_expires = ? WHERE id = ?')
        ->execute([hash('sha256', $code), $expires, $id]);
    vigie_audit($actor, 'server.enroll', $s['name']);
    return ['id' => $id, 'code' => $code, 'expires' => $expires];
}

function admin_server_update(int $id, ?string $descr, ?int $interval, string $actor): void
{
    $s = admin_server_row($id);
    if ($interval !== null) {
        vigie_db()->prepare('UPDATE servers SET poll_interval = ? WHERE id = ?')->execute([admin_check_interval($interval), $id]);
    }
    if ($descr !== null) {
        vigie_db()->prepare('UPDATE servers SET descr = ? WHERE id = ?')->execute([mb_substr($descr, 0, 200), $id]);
    }
    vigie_audit($actor, 'server.set', $s['name'] . ' ' . json_encode(['descr' => $descr, 'interval' => $interval], JSON_UNESCAPED_UNICODE));
}

function admin_server_fast(int $id, int $minutes, string $actor): void
{
    $s = admin_server_row($id);
    $minutes = max(1, min(240, $minutes));
    admin_speed_up($id, $minutes);
    vigie_audit($actor, 'server.fast', "{$s['name']} $minutes min");
}

function admin_server_revoke(int $id, string $actor): void
{
    $s = admin_server_row($id);
    vigie_db()->prepare('UPDATE servers SET token_hash = NULL, enroll_hash = NULL, enroll_expires = NULL WHERE id = ?')->execute([$id]);
    vigie_audit($actor, 'server.revoke', $s['name']);
}

function admin_server_delete(int $id, string $actor): void
{
    $s = admin_server_row($id);
    vigie_db()->prepare('DELETE FROM servers WHERE id = ?')->execute([$id]);
    vigie_audit($actor, 'server.delete', $s['name']);
}

// ── Services ────────────────────────────────────────────────────────────────

/** Keeps only the parameters that are set, as strings. */
function admin_clean_params(mixed $params): array
{
    $out = [];
    foreach (is_array($params) ? $params : [] as $k => $v) {
        if (!is_string($k) || !preg_match('/^[A-Z_][A-Z0-9_]{0,63}$/', $k)) continue;
        $v = trim(is_scalar($v) ? (string) $v : '');
        if ($v !== '') $out[$k] = $v;
    }
    return $out;
}

function admin_validate_service(array $server, string $task, array $params): void
{
    $def = admin_task_def($server, $task);
    if ($errors = vigie_validate_params($def, $params)) {
        throw new VigieError('params_rejected', ['errors' => $errors]);
    }
    // A "service" parameter must point to a service on the same server
    foreach ($def['params'] as $p) {
        if ($p['type'] === 'service' && ($params[$p['name']] ?? '') !== '') {
            $st = vigie_db()->prepare('SELECT COUNT(*) FROM services WHERE id = ? AND server_id = ?');
            $st->execute([(int) $params[$p['name']], $server['id']]);
            if (!$st->fetchColumn()) {
                throw new VigieError('service_ref_unknown', ['param' => $p['name'], 'id' => $params[$p['name']], 'server' => $server['name']]);
            }
        }
    }
}

function admin_service_create(int $serverId, string $task, string $name, array $params, string $actor): int
{
    $srv = admin_server_row($serverId);
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 100) throw new VigieError('service_name_required');
    $params = admin_clean_params($params);
    admin_validate_service($srv, $task, $params);
    $now = time();
    try {
        vigie_db()->prepare(<<<'SQL'
            INSERT INTO services (server_id, task, name, position, params, created_at, updated_at)
            SELECT ?, ?, ?, COALESCE(MAX(position), 0) + 1, ?, ?, ? FROM services WHERE server_id = ?
            SQL)->execute([$serverId, $task, $name, json_encode((object) $params, JSON_UNESCAPED_UNICODE), $now, $now, $serverId]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new VigieError('service_exists', ['name' => $name, 'server' => $srv['name']], 409);
        throw $e;
    }
    $id = (int) vigie_db()->lastInsertId();
    admin_speed_up($serverId);
    vigie_audit($actor, 'service.add', "$id {$srv['name']}/$task \"$name\"");
    return $id;
}

/**
 * Sets the order of a server's services: dashboard, server page, and the order
 * of the lines in the server's crontab. $ids must list every service exactly once.
 */
function admin_server_order(int $serverId, array $ids, string $actor): void
{
    $srv = admin_server_row($serverId);
    $ids = array_map('intval', $ids);
    $st = vigie_db()->prepare('SELECT id FROM services WHERE server_id = ?');
    $st->execute([$serverId]);
    $current = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $sorted = $ids; sort($sorted); sort($current);
    if ($sorted !== $current) throw new VigieError('order_invalid');
    $db = vigie_db();
    $db->beginTransaction();
    $up = $db->prepare('UPDATE services SET position = ? WHERE id = ? AND server_id = ?');
    foreach ($ids as $i => $id) $up->execute([$i + 1, $id, $serverId]);
    $db->commit();
    admin_speed_up($serverId);
    vigie_audit($actor, 'server.order', "{$srv['name']}: " . implode(', ', $ids));
}

/** Replaces the service's parameters (missing values are cleared). */
function admin_service_update(int $id, ?string $name, ?array $params, ?int $maxAge, ?int $maxRuntime, string $actor, bool $resetLimits = false): void
{
    $svc = admin_service_row($id);
    $name = $name !== null ? trim($name) : $svc['name'];
    if ($name === '' || mb_strlen($name) > 100) throw new VigieError('service_name_required');
    $newParams = $params !== null ? admin_clean_params($params) : (json_decode($svc['params'], true) ?: []);
    admin_validate_service(['id' => $svc['server_id'], 'name' => $svc['server_name'], 'catalog' => $svc['catalog']], $svc['task'], $newParams);
    vigie_db()->prepare(<<<'SQL'
        UPDATE services SET name = ?, params = ?, max_age = ?, max_runtime = ?, updated_at = ? WHERE id = ?
        SQL)->execute([
        $name, json_encode((object) $newParams, JSON_UNESCAPED_UNICODE),
        $resetLimits ? $maxAge : ($maxAge ?? $svc['max_age']),
        $resetLimits ? $maxRuntime : ($maxRuntime ?? $svc['max_runtime']),
        time(), $id,
    ]);
    admin_speed_up((int) $svc['server_id']);
    vigie_audit($actor, 'service.set', "$id \"$name\"");
}

function admin_job_create(int $serviceId, string $kind, string $actor): array
{
    if (!in_array($kind, ['check', 'run'], true)) throw new VigieError('job_kind_invalid');
    $svc = admin_service_row($serviceId);
    vigie_db()->prepare('INSERT INTO jobs (service_id, kind, created_at) VALUES (?, ?, ?)')->execute([$serviceId, $kind, time()]);
    $id = (int) vigie_db()->lastInsertId();
    admin_speed_up((int) $svc['server_id']);
    vigie_audit($actor, "job.$kind", "service $serviceId ({$svc['name']}), request $id");
    return ['id' => $id, 'delay' => admin_expected_delay(admin_server_row((int) $svc['server_id']))];
}

/** Schedules and enables, or disables when $expr is null. Refused without a successful check, unless $force. */
function admin_service_schedule(int $id, ?string $expr, bool $force, string $actor): void
{
    $svc = admin_service_row($id);
    if ($expr === null) {
        vigie_db()->prepare('UPDATE services SET enabled = 0, updated_at = ? WHERE id = ?')->execute([time(), $id]);
    } else {
        $expr = trim(preg_replace('/\s+/', ' ', $expr));
        if (!preg_match(VIGIE_CRON_RE, $expr)) throw new VigieError('cron_invalid');
        $st = vigie_db()->prepare("SELECT result_ok FROM jobs WHERE service_id = ? AND kind = 'check' AND state = 'done' ORDER BY id DESC LIMIT 1");
        $st->execute([$id]);
        $check = $st->fetchColumn();
        if (!$force && $check === false) throw new VigieError('no_successful_check', [], 409);
        if (!$force && !(int) $check) throw new VigieError('last_check_failed', [], 409);
        vigie_db()->prepare('UPDATE services SET schedule = ?, enabled = 1, updated_at = ? WHERE id = ?')->execute([$expr, time(), $id]);
    }
    admin_speed_up((int) $svc['server_id']);
    vigie_audit($actor, 'service.schedule', "$id " . ($expr ?? 'disabled') . ($force ? ' (forced)' : ''));
}

function admin_service_delete(int $id, string $actor): void
{
    $svc = admin_service_row($id);
    vigie_db()->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
    admin_speed_up((int) $svc['server_id']);
    vigie_audit($actor, 'service.delete', "$id {$svc['name']}");
}

function admin_service(int $id): array
{
    $s = admin_service_row($id);
    $catalog = json_decode($s['catalog'] ?? '{}', true) ?: [];
    $st = vigie_db()->prepare('SELECT id, kind, state, result_ok, created_at, done_at FROM jobs WHERE service_id = ? ORDER BY id DESC LIMIT 10');
    $st->execute([$id]);
    return [
        'id'          => (int) $s['id'],
        'server_id'   => (int) $s['server_id'],
        'server_name' => $s['server_name'],
        'task'        => $s['task'],
        'task_def'    => ($k = vigie_task_key($catalog, $s['task'])) !== null ? $catalog[$k] : null,
        'name'        => $s['name'],
        'params'      => json_decode($s['params'], true) ?: new stdClass(),
        'schedule'    => $s['schedule'],
        'enabled'     => (bool) $s['enabled'],
        'max_age'     => $s['max_age'] !== null ? (int) $s['max_age'] : null,
        // Threshold applied when max_age is empty: derived from the schedule
        'auto_max_age' => vigie_max_age(null, $s['schedule'], (bool) $s['enabled'],
                              ($k = vigie_task_key($catalog, $s['task'])) !== null ? (int) $catalog[$k]['max_age'] : null),
        'max_runtime' => $s['max_runtime'] !== null ? (int) $s['max_runtime'] : null,
        'jobs'        => $st->fetchAll(),
    ];
}

function admin_job(int $id): array
{
    $st = vigie_db()->prepare('SELECT j.*, s.name AS service_name FROM jobs j JOIN services s ON s.id = j.service_id WHERE j.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: throw new VigieError('job_not_found', [], 404);
}

function admin_audit(int $limit = 100): array
{
    $st = vigie_db()->prepare('SELECT at, actor, action, detail FROM audit ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

// ── HTTP endpoints (administrator session required, see index.php) ──────────

function http_int(array $d, string $k): ?int
{
    return isset($d[$k]) && $d[$k] !== '' && is_numeric($d[$k]) ? (int) $d[$k] : null;
}

function admin_http(string $method, string $path): never
{
    $m = [];
    $d = $method === 'POST' ? vigie_body() : [];
    $actor = 'admin';

    if ($method === 'GET' && $path === '/api/admin/servers')      vigie_json(admin_servers());
    if ($method === 'POST' && $path === '/api/admin/servers') {
        vigie_json(admin_server_create((string) ($d['name'] ?? ''), (string) ($d['descr'] ?? ''),
            http_int($d, 'interval') ?? (int) (getenv('VIGIE_DEFAULT_INTERVAL') ?: 15), $actor));
    }
    if (preg_match('#^/api/admin/servers/(\d+)(?:/([a-z-]+))?$#', $path, $m)) {
        $id = (int) $m[1];
        $action = $m[2] ?? '';
        if ($method === 'GET' && $action === '') vigie_json(admin_server($id));
        if ($method === 'POST') {
            match ($action) {
                ''       => admin_server_update($id, isset($d['descr']) ? (string) $d['descr'] : null, http_int($d, 'interval'), $actor),
                'enroll' => vigie_json(admin_server_new_code($id, $actor)),
                'fast'   => admin_server_fast($id, http_int($d, 'minutes') ?? 15, $actor),
                'revoke' => admin_server_revoke($id, $actor),
                'order'  => admin_server_order($id, is_array($d['ids'] ?? null) ? $d['ids'] : [], $actor),
                'delete' => admin_server_delete($id, $actor),
                default  => throw new VigieError('action_unknown', [], 404),
            };
            vigie_json(['ok' => true]);
        }
    }
    if ($method === 'POST' && $path === '/api/admin/services') {
        vigie_json(['id' => admin_service_create((int) ($d['server_id'] ?? 0), (string) ($d['task'] ?? ''),
            (string) ($d['name'] ?? ''), is_array($d['params'] ?? null) ? $d['params'] : [], $actor)]);
    }
    if (preg_match('#^/api/admin/services/(\d+)(?:/([a-z-]+))?$#', $path, $m)) {
        $id = (int) $m[1];
        $action = $m[2] ?? '';
        if ($method === 'GET' && $action === '') vigie_json(admin_service($id));
        if ($method === 'POST') {
            match ($action) {
                ''         => admin_service_update($id, isset($d['name']) ? (string) $d['name'] : null,
                                  is_array($d['params'] ?? null) ? $d['params'] : null,
                                  http_int($d, 'max_age'), http_int($d, 'max_runtime'), $actor, true),
                'test'     => vigie_json(admin_job_create($id, 'check', $actor)),
                'run'      => vigie_json(admin_job_create($id, 'run', $actor)),
                'schedule' => admin_service_schedule($id, isset($d['schedule']) && $d['schedule'] !== '' ? (string) $d['schedule'] : null,
                                  !empty($d['force']), $actor),
                'delete'   => admin_service_delete($id, $actor),
                default    => throw new VigieError('action_unknown', [], 404),
            };
            vigie_json(['ok' => true]);
        }
    }
    if ($method === 'GET' && preg_match('#^/api/admin/jobs/(\d+)$#', $path, $m)) vigie_json(admin_job((int) $m[1]));
    if ($method === 'GET' && $path === '/api/admin/audit') vigie_json(admin_audit((int) ($_GET['limit'] ?? 100)));
    vigie_error('unknown_route', [], 404);
}
