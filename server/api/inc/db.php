<?php
declare(strict_types=1);

// Connection to the existing MariaDB. Settings come from the container environment.
function vigie_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: '8190-mariadb',
        (int) (getenv('DB_PORT') ?: 3306),
        getenv('DB_NAME') ?: 'VIGIE'
    );
    $pdo = new PDO($dsn, getenv('DB_USER') ?: 'vigie', (string) getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    return $pdo;
}

/**
 * Applies schema.sql when the database is older than this code (idempotent).
 * Called on every request: forgetting "vigie-admin db:init" after an upgrade
 * must not break the application.
 */
function vigie_schema_ensure(bool $force = false): bool
{
    $db = vigie_db();
    if (!$force) {
        try {
            $v = $db->query("SELECT v FROM app_state WHERE k = 'schema_version'")->fetchColumn();
            if ((int) $v >= VIGIE_SCHEMA_VERSION) return false;
        } catch (PDOException $e) {
            // app_state missing: brand new or very old database, apply the schema
        }
    }
    $db->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));
    $db->prepare("INSERT INTO app_state (k, v) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
       ->execute([(string) VIGIE_SCHEMA_VERSION]);
    error_log('vigie: database schema updated to version ' . VIGIE_SCHEMA_VERSION);
    return true;
}

function vigie_audit(string $actor, string $action, string $detail = ''): void
{
    vigie_db()->prepare('INSERT INTO audit (at, actor, action, detail) VALUES (?, ?, ?, ?)')
        ->execute([time(), $actor, $action, $detail]);
}
