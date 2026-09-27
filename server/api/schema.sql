-- Vigie — MariaDB schema (applied by "vigie-admin db:init", idempotent)

CREATE TABLE IF NOT EXISTS servers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(64)  NOT NULL UNIQUE,
    descr           VARCHAR(200) NOT NULL DEFAULT '',
    poll_interval   SMALLINT UNSIGNED NOT NULL DEFAULT 15,  -- minutes between two syncs
    fast_until      INT UNSIGNED NOT NULL DEFAULT 0,        -- sync every minute until this time
    fast_pending    SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- minutes of fast sync starting at the agent's next sync
    token_hash      CHAR(64)     NULL,                      -- SHA-256 of the agent token
    enroll_hash     CHAR(64)     NULL,                      -- SHA-256 of the enrolment code
    enroll_expires  INT UNSIGNED NULL,
    facts           LONGTEXT     NULL,                      -- JSON: OS, commands, mounts, containers…
    catalog         LONGTEXT     NULL,                      -- JSON: available tasks and parameters
    sync_errors     LONGTEXT     NULL,                      -- JSON: services rejected by the agent
    agent_version   VARCHAR(20)  NULL,
    last_seen       INT UNSIGNED NULL,
    enrolled_at     INT UNSIGNED NULL,
    created_at      INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    server_id       INT UNSIGNED NOT NULL,
    task            VARCHAR(64)  NOT NULL,
    name            VARCHAR(100) NOT NULL,
    position        INT UNSIGNED NOT NULL DEFAULT 0,        -- order on the server, and in its crontab
    params          LONGTEXT     NOT NULL,                  -- JSON {NAME: value}
    schedule        VARCHAR(100) NULL,                      -- cron expression, NULL = not scheduled
    enabled         TINYINT(1)   NOT NULL DEFAULT 0,
    max_age         INT UNSIGNED NULL,                      -- minutes, NULL = task default
    max_runtime     INT UNSIGNED NULL,
    created_at      INT UNSIGNED NOT NULL,
    updated_at      INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_service_name (server_id, name),
    CONSTRAINT fk_service_server FOREIGN KEY (server_id) REFERENCES servers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id      INT UNSIGNED NOT NULL,
    kind            ENUM('check', 'run') NOT NULL,
    state           ENUM('pending', 'sent', 'done', 'expired') NOT NULL DEFAULT 'pending',
    result_ok       TINYINT(1)   NULL,
    output          MEDIUMTEXT   NULL,
    created_at      INT UNSIGNED NOT NULL,
    sent_at         INT UNSIGNED NULL,
    done_at         INT UNSIGNED NULL,
    KEY k_job_state (state),
    CONSTRAINT fk_job_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS runs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id      INT UNSIGNED NOT NULL,
    run_id          VARCHAR(64)  NOT NULL,
    job_id          INT UNSIGNED NULL,
    status          ENUM('running', 'success', 'warning', 'error') NOT NULL,
    started_at      INT UNSIGNED NOT NULL,
    finished_at     INT UNSIGNED NULL,
    duration        INT UNSIGNED NULL,
    exit_code       SMALLINT     NULL,
    heartbeat       SMALLINT UNSIGNED NOT NULL DEFAULT 300,
    summary         VARCHAR(500) NULL,
    metrics         TEXT         NULL,
    log_tail        MEDIUMTEXT   NULL,
    updated_at      INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_run (service_id, run_id),
    KEY k_run_service (service_id, started_at),
    CONSTRAINT fk_run_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    at              INT UNSIGNED NOT NULL,
    actor           VARCHAR(100) NOT NULL,
    action          VARCHAR(64)  NOT NULL,
    detail          TEXT         NULL,
    KEY k_audit_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single administrator account (id = 1). The TOTP secret is encrypted with VIGIE_APP_KEY.
CREATE TABLE IF NOT EXISTS admin (
    id              TINYINT UNSIGNED PRIMARY KEY,
    password_hash   VARCHAR(255) NOT NULL,
    totp_secret     VARCHAR(255) NOT NULL,
    totp_last       BIGINT UNSIGNED NOT NULL DEFAULT 0,    -- last accepted TOTP step (replay protection)
    failed_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    INT UNSIGNED NOT NULL DEFAULT 0,
    last_login      INT UNSIGNED NULL,
    created_at      INT UNSIGNED NOT NULL,
    updated_at      INT UNSIGNED NOT NULL,
    CONSTRAINT ck_single_admin CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_state (
    k               VARCHAR(64) PRIMARY KEY,
    v               VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrades from earlier versions (idempotent)
ALTER TABLE servers ADD COLUMN IF NOT EXISTS fast_pending SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER fast_until;
ALTER TABLE services ADD COLUMN IF NOT EXISTS position INT UNSIGNED NOT NULL DEFAULT 0 AFTER name;

-- Version 4: renamed tasks (the API still accepts the former names, see VIGIE_TASK_ALIASES)
UPDATE services SET task = 'folder-sync' WHERE task = 'library-sync';
UPDATE services SET task = 'backup-borg' WHERE task = 'nextcloud-borg';

-- Version 5: mariadb-dump renamed db-dump
UPDATE services SET task = 'db-dump' WHERE task = 'mariadb-dump';

-- Version 6: last "poll" of each agent (quick check for pending work, every minute)
ALTER TABLE servers ADD COLUMN IF NOT EXISTS last_poll INT UNSIGNED NULL AFTER last_seen;
