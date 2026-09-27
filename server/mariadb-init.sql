-- Run once in the existing MariaDB, as root (replace the password first):
--   docker exec -it 8190-mariadb mariadb -u root -p -e "$(cat mariadb-init.sql)"
-- The user is only accepted from the vigie-api container's IP.
CREATE DATABASE IF NOT EXISTS VIGIE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'vigie'@'172.18.0.213' IDENTIFIED BY 'CHANGE-ME';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON `VIGIE`.* TO 'vigie'@'172.18.0.213';

-- Optional: read-only account for the "mariadb-dump" task, which connects from inside
-- the MariaDB container ('localhost'). Never reuse the 'vigie' account for this.
-- Grant it the databases you back up, one line per database:
-- CREATE USER IF NOT EXISTS 'backup'@'localhost' IDENTIFIED BY 'CHANGE-ME-TOO';
-- GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON `VIGIE`.* TO 'backup'@'localhost';
