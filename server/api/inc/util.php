<?php
declare(strict_types=1);

date_default_timezone_set(getenv('TZ') ?: 'Europe/Zurich');

/** Allowed agent sync intervals (minutes): each maps to an exact cron line. */
const VIGIE_INTERVALS = [1, 2, 3, 5, 10, 15, 20, 30, 60, 120, 180, 240, 360, 720, 1440];

/**
 * Task parameter types. The agent applies the very same rules: even a
 * compromised Vigie could not push a value outside these shapes.
 */
const VIGIE_PARAM_TYPES = [
    'path'    => '~^/[A-Za-z0-9._/@+ -]*$~',
    'paths'   => '~^/[A-Za-z0-9._/@+-]*( /[A-Za-z0-9._/@+-]*)*$~',
    'name'    => '~^[A-Za-z0-9._-]{1,64}$~',
    'names'   => '~^[A-Za-z0-9._-]{1,64}( [A-Za-z0-9._-]{1,64})*$~',
    'varname' => '~^[A-Z_][A-Z0-9_]{0,63}$~',
    'int'     => '~^[0-9]{1,9}$~',
    'bool'    => '~^(yes|no)$~',
    'service' => '~^[0-9]{1,9}$~',
];

/** Increase whenever schema.sql changes: the API then applies it on its own. */
const VIGIE_SCHEMA_VERSION = 6;

/**
 * Renamed tasks: new name => former name. Agents that still ship the former
 * file keep working, whatever the order in which Vigie and agents are upgraded.
 */
const VIGIE_TASK_ALIASES = [
    'folder-sync' => 'library-sync',
    'backup-borg' => 'nextcloud-borg',
    'db-dump'     => 'mariadb-dump',
];

/** Name under which $task appears in an agent's catalogue (current or former name), or null. */
function vigie_task_key(array $catalog, string $task): ?string
{
    foreach ([$task, VIGIE_TASK_ALIASES[$task] ?? null, array_search($task, VIGIE_TASK_ALIASES, true) ?: null] as $name) {
        if ($name !== null && isset($catalog[$name])) return $name;
    }
    return null;
}

const VIGIE_CRON_RE = '~^([0-9*,/-]+ ){4}[0-9*,/-]+$~';

// Values allowed by one cron field (star, "5", "1-5", star/15, "1,15", "0-30/10"…), or null if invalid.
function vigie_cron_field(string $field, int $min, int $max): ?array
{
    $values = [];
    foreach (explode(',', $field) as $part) {
        if (!preg_match('~^(\*|(\d+)(?:-(\d+))?)(?:/(\d+))?$~', $part, $m)) return null;
        $from = $m[1] === '*' ? $min : (int) $m[2];
        $to   = $m[1] === '*' ? $max : (isset($m[3]) && $m[3] !== '' ? (int) $m[3] : ((isset($m[4]) && $m[4] !== '') ? $max : $from));
        $step = isset($m[4]) && $m[4] !== '' ? max(1, (int) $m[4]) : 1;
        if ($from < $min || $to > $max || $from > $to) return null;
        for ($v = $from; $v <= $to; $v += $step) $values[$v] = true;
    }
    return $values;
}

/**
 * Longest gap between two consecutive runs of a cron schedule, in minutes,
 * observed over the coming weeks (null when the expression is invalid or never fires).
 * Standard cron rule: when both day of month and day of week are restricted,
 * a day matching either of them fires.
 */
function vigie_cron_gap(string $expr, ?int $from = null): ?int
{
    static $cache = [];
    if (array_key_exists($expr, $cache)) return $cache[$expr];
    $f = preg_split('/\s+/', trim($expr));
    if (count($f) !== 5) return $cache[$expr] = null;
    $min = vigie_cron_field($f[0], 0, 59);
    $hour = vigie_cron_field($f[1], 0, 23);
    $dom = vigie_cron_field($f[2], 1, 31);
    $mon = vigie_cron_field($f[3], 1, 12);
    $dow = vigie_cron_field(str_replace('7', '0', $f[4]), 0, 6);
    if (!$min || !$hour || !$dom || !$mon || !$dow) return $cache[$expr] = null;
    $domAny = $f[2] === '*'; $dowAny = $f[4] === '*';
    $times = [];
    foreach (array_keys($hour) as $h) foreach (array_keys($min) as $m) $times[] = $h * 60 + $m;
    sort($times);

    $day = strtotime('today', $from ?? time());
    $last = null; $gap = 0; $runs = 0;
    for ($i = 0; $i < 800; $i++, $day = strtotime('+1 day', $day)) {
        $d = (int) date('j', $day); $w = (int) date('w', $day);
        if (!isset($mon[(int) date('n', $day)])) continue;
        $dayOk = $domAny && $dowAny ? true
            : ($domAny ? isset($dow[$w]) : ($dowAny ? isset($dom[$d]) : isset($dom[$d]) || isset($dow[$w])));
        if (!$dayOk) continue;
        // Wall-clock minutes (as cron sees them): a daylight saving change does not add an hour
        foreach ($times as $t) {
            $at = $i * 1440 + $t;
            if ($last !== null) $gap = max($gap, $at - $last);
            $last = $at; $runs++;
        }
        // Enough observed: two months (a full year when months are restricted), and three runs
        if ($i >= ($f[3] === '*' ? 62 : 400) && $runs >= 3) break;
    }
    return $cache[$expr] = $runs >= 2 ? $gap : null;
}

/**
 * "Late" threshold of a service, in minutes: the value set on the service, or else
 * the longest gap of its schedule plus a margin, or else the task's default.
 */
function vigie_max_age(?int $serviceValue, ?string $schedule, bool $enabled, ?int $taskDefault): int
{
    if ($serviceValue !== null) return $serviceValue;
    $gap = $enabled && $schedule ? vigie_cron_gap($schedule) : null;
    if ($gap !== null) return $gap + max(120, intdiv($gap, 10));
    return $taskDefault ?? 1560;
}

/**
 * Message catalogue. The API never sends sentences on their own: it sends a code
 * and its parameters, which the web interface translates. This English text is
 * the fallback, and what the command line prints.
 */
const VIGIE_MESSAGES = [
    // General
    'request_too_large'       => 'Request too large',
    'invalid_json'            => 'Invalid JSON',
    'unknown_route'           => 'Unknown route',
    'request_refused'         => 'Request refused',
    'db_unavailable'          => 'Database unavailable',
    'internal_error'          => 'Internal API error',
    // Agents
    'token_missing'           => 'Missing or malformed token',
    'token_unknown'           => 'Unknown or revoked token',
    'enroll_code_malformed'   => 'Malformed enrolment code',
    'enroll_code_invalid'     => 'Enrolment code unknown, already used or expired',
    'invalid_status'          => 'Invalid status',
    'invalid_run_id'          => 'Invalid run_id',
    'service_unknown_here'    => 'Unknown service for this server',
    'job_unknown_here'        => 'Unknown request for this server',
    // Authentication
    'app_key_invalid'         => 'VIGIE_APP_KEY missing or invalid (64 hexadecimal characters expected)',
    'totp_undecryptable'      => 'TOTP secret cannot be decrypted: has VIGIE_APP_KEY changed?',
    'session_required'        => 'Session expired or missing',
    'already_configured'      => 'Vigie is already configured',
    'not_configured'          => 'Vigie is not configured yet',
    'setup_too_many_attempts' => 'Too many attempts: a new code has been written to the container log',
    'setup_code_invalid'      => 'Setup code invalid or expired',
    'password_too_short'      => 'The password must be at least {min} characters long',
    'setup_restart'           => 'Setup must be restarted from the beginning',
    'totp_incorrect'          => 'Incorrect code: check the phone\'s clock and try again',
    'login_locked'            => 'Too many attempts, sign-in blocked until {until}',
    'login_invalid'           => 'Incorrect password or code',
    'current_invalid'         => 'Incorrect current password or code',
    // Administration
    'server_not_found'        => 'Server not found',
    'server_unknown'          => 'Unknown server: {name}',
    'service_not_found'       => 'Service not found',
    'job_not_found'           => 'Request not found',
    'no_catalog'              => 'The agent of {server} has not sent its task list yet',
    'task_unknown'            => 'Task "{task}" unknown on {server}',
    'interval_invalid'        => 'Allowed intervals (minutes): {allowed}',
    'server_name_invalid'     => 'Invalid name: letters, digits, dot, dash and underscore only',
    'server_exists'           => 'A server named "{name}" already exists',
    'service_name_required'   => 'Service name required (100 characters at most)',
    'service_exists'          => 'A service named "{name}" already exists on {server}',
    'params_rejected'         => 'Parameters rejected',
    'service_ref_unknown'     => '{param}: no service {id} on {server}',
    'job_kind_invalid'        => 'Invalid request type',
    'cron_invalid'            => 'Invalid cron expression: 5 fields expected, for example "0 2 * * *"',
    'no_successful_check'     => 'No successful check for this service yet: run a test first',
    'last_check_failed'       => 'The last check failed: fix the parameters and test again',
    'action_unknown'          => 'Unknown action',
    'order_invalid'           => 'The order must list every service of the server exactly once',
    // Parameter validation (details of params_rejected)
    'param_unknown'           => 'Unknown parameter for {task}: {name}',
    'param_invalid'           => '{name}: invalid value for type "{type}"',
    'param_required'          => '{name} is required',
];

function vigie_msg(string $code, array $params = []): string
{
    $text = VIGIE_MESSAGES[$code] ?? $code;
    foreach ($params as $k => $v) {
        if (is_scalar($v)) $text = str_replace('{' . $k . '}', (string) $v, $text);
    }
    if ($code === 'params_rejected' && !empty($params['errors'])) {
        $text .= ': ' . implode('; ', array_map(fn($e) => vigie_msg($e['code'], $e['params']), $params['errors']));
    }
    return $text;
}

/** Business error: a message code, its parameters and the matching HTTP status. */
final class VigieError extends RuntimeException
{
    public function __construct(public readonly string $key, public readonly array $params = [], public readonly int $http = 400)
    {
        parent::__construct(vigie_msg($key, $params));
    }
}

function vigie_json(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Error response: English text, plus code and parameters for the interface to translate. */
function vigie_error(string $code, array $params = [], int $http = 400, array $extra = []): never
{
    vigie_json(['error' => vigie_msg($code, $params), 'code' => $code, 'params' => (object) $params] + $extra, $http);
}

/** Line-based text response, readable by the agent without jq. */
function vigie_text(array $lines, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "vigie 1\n", implode("\n", $lines), ($lines ? "\n" : ''), "end\n";
    exit;
}

function vigie_body(): array
{
    $raw = (string) file_get_contents('php://input', false, null, 0, 1_000_001);
    if (strlen($raw) > 1_000_000) vigie_error('request_too_large', [], 413);
    $d = json_decode($raw, true, 16, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_array($d)) vigie_error('invalid_json');
    return $d;
}

/** Field encoding for the text response; "-" stands for an empty value. */
function vigie_b64(?string $v): string
{
    return ($v === null || $v === '') ? '-' : base64_encode($v);
}

function vigie_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/** Human-friendly code: XXXX-XXXX-XXXX, without ambiguous characters. */
function vigie_enroll_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 12; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)] . (($i % 4 === 3 && $i < 11) ? '-' : '');
    }
    return $code;
}

function vigie_client_ip(): string
{
    $fwd = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
    return $fwd !== '' ? $fwd : (string) ($_SERVER['REMOTE_ADDR'] ?? '?');
}

// ── Durations and dates (command line and Homepage widget) ──────────────────
// The web interface formats these itself, in the user's language.

/** Languages supported for server-rendered text (Homepage widget). */
const VIGIE_LANGS = ['en', 'fr'];

function vigie_lang(?string $requested): string
{
    $l = strtolower(substr((string) $requested, 0, 2));
    return in_array($l, VIGIE_LANGS, true) ? $l : 'en';
}

function vigie_duration(int $s, string $lang = 'en'): string
{
    $day = $lang === 'fr' ? 'jours' : 'days';
    if ($s < 90)     return $s . ' s';
    if ($s < 5400)   return round($s / 60) . ' min';
    if ($s < 172800) return intdiv($s, 3600) . ' h' . ($s % 3600 >= 60 ? ' ' . sprintf('%02d', intdiv($s % 3600, 60)) : '');
    return round($s / 86400) . " $day";
}

function vigie_ago(int $ts, int $now, string $lang = 'en'): string
{
    $fr = $lang === 'fr';
    $s = $now - $ts;
    if ($s < 60)       return $fr ? "à l'instant" : 'just now';
    if ($s < 3600)     return $fr ? 'il y a ' . round($s / 60) . ' min' : round($s / 60) . ' min ago';
    $time = date('H:i', $ts);
    if (date('Y-m-d', $ts) === date('Y-m-d', $now))         return $fr ? "aujourd'hui à $time" : "today at $time";
    if (date('Y-m-d', $ts) === date('Y-m-d', $now - 86400)) return $fr ? "hier à $time" : "yesterday at $time";
    $days = $fr ? ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'] : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $months = $fr ? ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']
                  : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return $days[(int) date('w', $ts)] . ' ' . date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1]
         . ($fr ? " à $time" : " at $time");
}

/**
 * Validates a service's parameters against the catalogue declared by the agent.
 * Returns a list of errors [{code, params}], empty when everything is valid.
 */
function vigie_validate_params(array $taskDef, array $params, bool $partial = false): array
{
    $errors = [];
    $declared = [];
    foreach ($taskDef['params'] ?? [] as $p) {
        $declared[$p['name']] = $p;
    }
    foreach ($params as $k => $v) {
        if (!isset($declared[$k])) {
            $errors[] = ['code' => 'param_unknown', 'params' => ['task' => $taskDef['name'], 'name' => $k]];
            continue;
        }
        $v = (string) $v;
        $type = $declared[$k]['type'];
        if ($v !== '' && !preg_match(VIGIE_PARAM_TYPES[$type] ?? '~^$~', $v)) {
            $errors[] = ['code' => 'param_invalid', 'params' => ['name' => $k, 'type' => $type]];
        }
    }
    if (!$partial) {
        foreach ($declared as $name => $p) {
            if ($p['required'] && (string) ($params[$name] ?? '') === '' && (string) $p['default'] === '') {
                $errors[] = ['code' => 'param_required', 'params' => ['name' => $name]];
            }
        }
    }
    return $errors;
}
