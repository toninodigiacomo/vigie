<?php
declare(strict_types=1);

// Vigie — API front controller (application tier, never exposed directly).
require __DIR__ . '/../inc/util.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/agent.php';
require __DIR__ . '/../inc/state.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/admin.php';

// Never print PHP messages into a response: they would corrupt the JSON.
// Any warning becomes an error, logged and answered with a proper error code.
ini_set('display_errors', '0');
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    throw new ErrorException($msg, 0, $no, $file, $line);
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

// Routes with their own access control: agent token, Homepage read key
$public = [
    'POST /api/agent/enroll' => 'agent_enroll',
    'POST /api/agent/sync'   => 'agent_sync',
    'POST /api/agent/report' => 'agent_report',
    'POST /api/agent/job'    => 'agent_job',
    'POST /api/agent/poll'   => 'agent_poll',
    'GET /api/homepage'      => 'api_homepage',
];
// Interface routes: administrator session, except for signing in
$auth = [
    'GET /api/auth/status'        => 'auth_status',
    'POST /api/auth/setup/start'  => 'auth_setup_start',
    'POST /api/auth/setup/finish' => 'auth_setup_finish',
    'POST /api/auth/login'        => 'auth_login',
    'POST /api/auth/logout'       => 'auth_logout',
    'POST /api/auth/password'     => 'auth_password',
];

try {
    vigie_schema_ensure();
    if (isset($public["$method $path"])) {
        $public["$method $path"]();
    }
    // CSRF protection: the interface always sends this header, which a third-party
    // form or link cannot add (on top of the SameSite=Strict cookie).
    if ($method === 'POST' && ($_SERVER['HTTP_X_VIGIE'] ?? '') !== '1') {
        vigie_error('request_refused', [], 403);
    }
    if (isset($auth["$method $path"])) {
        $auth["$method $path"]();
    }
    vigie_require_admin();
    if ($method === 'GET' && $path === '/api/state') {
        api_state();
    }
    if (str_starts_with($path, '/api/admin/')) {
        admin_http($method, $path);
    }
    vigie_error('unknown_route', [], 404);
} catch (VigieError $e) {
    vigie_error($e->key, $e->params, $e->http);
} catch (PDOException $e) {
    error_log('vigie: database: ' . $e->getMessage());
    vigie_error('db_unavailable', [], 503, ['detail' => vigie_debug($e)]);
} catch (Throwable $e) {
    error_log('vigie: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
    vigie_error('internal_error', [], 500, ['detail' => vigie_debug($e)]);
}

/** Error details are only returned with VIGIE_DEBUG=1; they are always written to "docker logs". */
function vigie_debug(Throwable $e): string
{
    return getenv('VIGIE_DEBUG') === '1'
        ? get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
        : 'see docker logs 8213-vigie-api, or "vigie-admin doctor"';
}
