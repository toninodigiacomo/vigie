# Dump of a database running in a container (formerly "mariadb-dump"): a MariaDB or MySQL server, or an
# application container with its own embedded database. One service = one database.
# The dump runs inside the container with its own client (mariadb-dump or mysqldump).
# Password, either:
#   PASSWORD_VAR  a variable of this server's secrets file (Vigie only knows its name)
#   PASSWORD_ENV  a variable of the container's own environment (MYSQL_ROOT_PASSWORD…)
TASK_DESC="MariaDB / MySQL database dump"
TASK_MAX_AGE=1560
TASK_MAX_RUNTIME=30
# @param CONTAINER    | name    | required | Container running the database              |
# @param DB_USER      | name    | required | Database user                               |
# @param DB_NAME      | name    | required | Database to back up                         |
# @param PASSWORD_VAR | varname | optional | Password: variable in the secrets file      |
# @param PASSWORD_ENV | varname | optional | Password: variable in the container         |
# @param DB_HOST      | name    | optional | Host inside the container (empty = local socket) |
# @param LABEL        | name    | optional | File prefix (default: database name)        |
# @param TARGET_DIR   | path    | required | Backup directory                            |
# @param TARGET_MOUNT | path    | optional | Mount point to check                        |
# @param DAILY_DIR    | name    | optional | Daily subdirectory                          | daily
# @param WEEKLY_DIR   | name    | optional | Weekly subdirectory (Monday)                | weekly
# @param MONTHLY_DIR  | name    | optional | Monthly subdirectory (1st of the month)     | monthly
# @param KEEP_DAILY   | int     | optional | Daily copies kept                           | 7
# @param KEEP_WEEKLY  | int     | optional | Weekly copies kept                          | 4
# @param KEEP_MONTHLY | int     | optional | Monthly copies kept (0 = all)               | 12

# Checks that exactly one password source is set and, for the secrets file, that it exists.
sql_password_ok() {
    if [ -n "$PASSWORD_VAR" ] && [ -n "$PASSWORD_ENV" ]; then
        v_fail "set PASSWORD_VAR or PASSWORD_ENV, not both"; return 1
    fi
    if [ -n "$PASSWORD_ENV" ]; then return 0; fi
    [ -n "$PASSWORD_VAR" ] || { v_fail "no password: set PASSWORD_VAR or PASSWORD_ENV"; return 1; }
    v_load_secrets || return 1
    [ -n "$(v_secret "$PASSWORD_VAR")" ] || { v_fail "$PASSWORD_VAR missing from $SECRETS_FILE"; return 1; }
}

# Runs the database client inside the container: sql_exec ping|dump
# The password never appears on a command line ("ps", "docker inspect"): it is
# either sent on stdin, or read from the container's environment inside it.
sql_exec() {
    _pw=
    [ -n "$PASSWORD_VAR" ] && _pw=$(v_secret "$PASSWORD_VAR")
    export VG_KIND=$1 VG_USER=$DB_USER VG_DB=$DB_NAME VG_HOST=${DB_HOST:-} VG_PWENV=${PASSWORD_ENV:-}
    printf '%s\n' "$_pw" | docker exec -e VG_KIND -e VG_USER -e VG_DB -e VG_HOST -e VG_PWENV -i "$CONTAINER" sh -c '
        IFS= read -r MYSQL_PWD
        [ -n "$VG_PWENV" ] && eval "MYSQL_PWD=\${$VG_PWENV:-}"
        [ -n "$MYSQL_PWD" ] || { echo "empty password (variable ${VG_PWENV:-from the secrets file} not set?)" >&2; exit 1; }
        export MYSQL_PWD
        if [ "$VG_KIND" = dump ]; then
            c=mariadb-dump; command -v $c >/dev/null 2>&1 || c=mysqldump
            set -- --single-transaction --no-tablespaces
        else
            c=mariadb; command -v $c >/dev/null 2>&1 || c=mysql
            set -- -e "SELECT 1"
        fi
        command -v $c >/dev/null 2>&1 || { echo "no MariaDB/MySQL client in this container" >&2; exit 127; }
        exec $c ${VG_HOST:+-h "$VG_HOST"} -u "$VG_USER" "$@" "$VG_DB"'
}

task_check() {
    v_require_cmd docker gzip || return 1
    case $PASSWORD_ENV in ''|[A-Z_]*) ;; *) v_fail "invalid PASSWORD_ENV"; return 1 ;; esac
    sql_password_ok || return 1
    v_require_container "$CONTAINER" && v_ok "container $CONTAINER running" || return 1
    if sql_exec ping >/dev/null 2>"$RUN_TMP/ping.err"; then
        v_ok "connected to $DB_NAME as $DB_USER"
    else
        v_fail "connection to $DB_NAME refused: $(grep -v '^mysql: \[Warning\]' "$RUN_TMP/ping.err" | head -n 1)"
    fi
    v_require_mount "$TARGET_MOUNT" && [ -n "$TARGET_MOUNT" ] && v_ok "$TARGET_MOUNT is mounted"
    _parent=$TARGET_DIR; [ -d "$_parent" ] || _parent=$(dirname "$TARGET_DIR")
    v_require_dir "$_parent" w && v_ok "destination writable: $_parent"
}

task_run() {
    v_require_cmd docker gzip || return 1
    v_require_mount "$TARGET_MOUNT" || return 1
    sql_password_ok || return 1
    _label=${LABEL:-$DB_NAME}
    _daily="$TARGET_DIR/${DAILY_DIR:-daily}"
    mkdir -p "$_daily" || { v_fail "cannot create $_daily"; return 1; }

    _ts=$(date +%Y-%m-%d_%Hh%M)
    _name="${_label}_$_ts.sql.gz"
    _tmp="$_daily/.${_label}_$_ts.sql"
    v_log "dumping $DB_NAME from $CONTAINER"
    sql_exec dump > "$_tmp" 2> "$RUN_TMP/dump.err"
    _rc=$?
    # MySQL's client warns when a password is given through the environment: not an error
    grep -v 'Using a password on the command line\|^mysqldump: \[Warning\]' "$RUN_TMP/dump.err" | sed 's/^/  /'
    if [ $_rc -ne 0 ]; then
        rm -f "$_tmp"; v_fail "dump failed (exit code $_rc)"; return 1
    fi
    # A complete dump ends with "-- Dump completed on …"
    if ! tail -n 1 "$_tmp" | grep -q '^-- Dump completed'; then
        rm -f "$_tmp"; v_fail "incomplete dump (end marker missing)"; return 1
    fi
    if ! gzip -c "$_tmp" > "$_daily/.$_name" || ! mv "$_daily/.$_name" "$_daily/$_name"; then
        rm -f "$_tmp" "$_daily/.$_name"; v_fail "compression failed"; return 1
    fi
    rm -f "$_tmp"
    _sz=$(v_fsize "$_daily/$_name")
    v_metric bytes "$_sz"
    v_log "OK: $_name ($(v_hsize "$_sz"))"
    v_gfs "$TARGET_DIR" "$_name" "${_label}_"
    v_summary "$DB_NAME backed up: $_name, $(v_hsize "$_sz")"
}
