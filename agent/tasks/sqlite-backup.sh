# Backup of an SQLite database, usually inside an application container.
#
# SQLite makes the copy itself (".backup" or "VACUUM INTO"): a consistent snapshot,
# taken while the application keeps running. The copy is then checked
# (PRAGMA quick_check), compressed and kept with daily/weekly/monthly retention.
# The resulting file is a plain SQLite database, ready to be restored.
#
# The copy uses the container's own tools: the sqlite3 client, or PHP with
# pdo_sqlite, or Python. It runs as the owner of the database file: run as root,
# SQLite could leave root-owned -wal/-shm files the application can no longer write.
# Without CONTAINER, the file is read directly on this server.
TASK_DESC="SQLite database backup"
TASK_MAX_AGE=1560
TASK_MAX_RUNTIME=30
# @param CONTAINER    | name | optional | Application container (empty = file on this server) |
# @param DB_PATH      | path | required | SQLite database file                        |
# @param LABEL        | name | optional | File prefix (default: database file name)   |
# @param TARGET_DIR   | path | required | Backup directory                            |
# @param TARGET_MOUNT | path | optional | Mount point to check                        |
# @param DAILY_DIR    | name | optional | Daily subdirectory                          | daily
# @param WEEKLY_DIR   | name | optional | Weekly subdirectory (Monday)                | weekly
# @param MONTHLY_DIR  | name | optional | Monthly subdirectory (1st of the month)     | monthly
# @param KEEP_DAILY   | int  | optional | Daily copies kept                           | 7
# @param KEEP_WEEKLY  | int  | optional | Weekly copies kept                          | 4
# @param KEEP_MONTHLY | int  | optional | Monthly copies kept (0 = all)               | 12

# Programs passed through the environment (no quoting issues across sh -c).
# Each copies VG_DB to VG_OUT, then prints "<quick_check result> <number of tables>".
VG_SQL_TABLES="SELECT count(*) FROM sqlite_master WHERE type = 'table'"
VG_PHP=$(cat <<'PHP'
$o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$db = new PDO('sqlite:' . getenv('VG_DB'), null, null, $o);
$db->exec('PRAGMA busy_timeout = 30000');
$db->exec('VACUUM INTO ' . $db->quote(getenv('VG_OUT')));
$db = null;
$c = new PDO('sqlite:' . getenv('VG_OUT'), null, null, $o);
echo $c->query('PRAGMA quick_check')->fetchColumn(), ' ', $c->query(getenv('VG_SQL_TABLES'))->fetchColumn(), "\n";
PHP
)
VG_PY=$(cat <<'PY'
import os, sqlite3
src = sqlite3.connect(os.environ['VG_DB'], timeout=30)
dst = sqlite3.connect(os.environ['VG_OUT'])
src.backup(dst); dst.close(); src.close()
c = sqlite3.connect(os.environ['VG_OUT'])
print(c.execute('PRAGMA quick_check').fetchone()[0], c.execute(os.environ['VG_SQL_TABLES']).fetchone()[0])
PY
)
# VG_MODE=probe: only report the tool that would be used. VG_MODE=backup: make the copy.
VG_SCRIPT='
[ -f "$VG_DB" ] || { echo "database file not found: $VG_DB" >&2; exit 2; }
[ -r "$VG_DB" ] || { echo "database file not readable: $VG_DB" >&2; exit 2; }
if command -v sqlite3 >/dev/null 2>&1; then tool=sqlite3
elif command -v php >/dev/null 2>&1 && php -m 2>/dev/null | grep -qi "^pdo_sqlite$"; then tool=php
elif command -v python3 >/dev/null 2>&1 && python3 -c "import sqlite3" 2>/dev/null; then tool=python3
else echo "no SQLite tool here (sqlite3, php with pdo_sqlite, or python3)" >&2; exit 127
fi
[ "$VG_MODE" = probe ] && { echo "$tool"; exit 0; }
rm -f "$VG_OUT"
case $tool in
    sqlite3) sqlite3 "$VG_DB" ".timeout 30000" ".backup $VG_OUT" || exit 1
             echo "$(sqlite3 "$VG_OUT" "PRAGMA quick_check;" | head -n 1) $(sqlite3 "$VG_OUT" "$VG_SQL_TABLES;")" ;;
    php)     php -r "$VG_PHP" || exit 1 ;;
    python3) python3 -c "$VG_PY" || exit 1 ;;
esac
echo "tool: $tool" >&2
'

# Runs VG_SCRIPT where the database lives, as the owner of the database file.
sq_exec() {   # probe|backup  output_path
    export VG_MODE=$1 VG_DB=$DB_PATH VG_OUT=$2 VG_PHP VG_PY VG_SQL_TABLES VG_SCRIPT
    if [ -n "$CONTAINER" ]; then
        docker exec -u "$SQ_OWNER" -e VG_MODE -e VG_DB -e VG_OUT -e VG_PHP -e VG_PY -e VG_SQL_TABLES \
            "$CONTAINER" sh -c "$VG_SCRIPT"
    else
        sh -c "$VG_SCRIPT"
    fi
}

# Owner (uid:gid) of the database file, as seen where it lives.
sq_owner() {
    if [ -n "$CONTAINER" ]; then
        SQ_OWNER=$(docker exec "$CONTAINER" stat -c '%u:%g' "$DB_PATH" 2>/dev/null)
    else
        SQ_OWNER=$(ls -ln "$DB_PATH" 2>/dev/null | awk '{ print $3 ":" $4 }')
    fi
    [ -n "$SQ_OWNER" ] || { v_fail "database file not found: $DB_PATH${CONTAINER:+ (in $CONTAINER)}"; return 1; }
}

# On this server, the copy runs as root: give back any -wal/-shm file SQLite created.
sq_fix_owner() {
    [ -z "$CONTAINER" ] || return 0
    for _f in "$DB_PATH-wal" "$DB_PATH-shm"; do
        [ -e "$_f" ] && chown "$SQ_OWNER" "$_f" 2>/dev/null
    done
    return 0
}

task_check() {
    if [ -n "$CONTAINER" ]; then
        v_require_cmd docker || return 1
        v_require_container "$CONTAINER" && v_ok "container $CONTAINER running" || return 1
    fi
    sq_owner || return 1
    v_ok "database file found: $DB_PATH (owner $SQ_OWNER)"
    if _tool=$(sq_exec probe "" 2>"$RUN_TMP/probe.err"); then
        v_ok "copy made with: $_tool"
    else
        v_fail "$(head -n 1 "$RUN_TMP/probe.err")"
    fi
    v_require_cmd gzip
    v_require_mount "$TARGET_MOUNT" && [ -n "$TARGET_MOUNT" ] && v_ok "$TARGET_MOUNT is mounted"
    _parent=$TARGET_DIR; [ -d "$_parent" ] || _parent=$(dirname "$TARGET_DIR")
    v_require_dir "$_parent" w && v_ok "destination writable: $_parent"
}

task_run() {
    v_require_cmd gzip || return 1
    [ -z "$CONTAINER" ] || v_require_cmd docker || return 1
    v_require_mount "$TARGET_MOUNT" || return 1
    sq_owner || return 1
    _label=${LABEL:-$(basename "$DB_PATH" | sed 's/\.[^.]*$//')}
    _daily="$TARGET_DIR/${DAILY_DIR:-daily}"
    mkdir -p "$_daily" || { v_fail "cannot create $_daily"; return 1; }
    _name="${_label}_$(date +%Y-%m-%d_%Hh%M).db.gz"

    if [ -n "$CONTAINER" ]; then _out="/tmp/vigie-sqlite-$$.db"; else _out="$RUN_TMP/copy.db"; fi
    v_log "copying $DB_PATH${CONTAINER:+ in $CONTAINER}"
    _res=$(sq_exec backup "$_out" 2>"$RUN_TMP/copy.err")
    _rc=$?
    sq_fix_owner
    sed 's/^/  /' "$RUN_TMP/copy.err"
    _cleanup_out() { [ -n "$CONTAINER" ] && docker exec -u "$SQ_OWNER" "$CONTAINER" rm -f "$_out" 2>/dev/null; }
    if [ $_rc -ne 0 ]; then _cleanup_out; v_fail "copy failed (exit code $_rc)"; return 1; fi
    set -- $_res
    if [ "${1:-}" != ok ]; then
        _cleanup_out; v_fail "the copy failed its integrity check: ${_res:-no result}"; return 1
    fi
    v_log "integrity check: ok, ${2:-?} table(s)"

    if [ -n "$CONTAINER" ]; then
        docker exec -u "$SQ_OWNER" "$CONTAINER" cat "$_out" | gzip -c > "$_daily/.$_name"
        _rc=$?
        _cleanup_out
    else
        gzip -c "$_out" > "$_daily/.$_name"; _rc=$?
        rm -f "$_out"
    fi
    if [ $_rc -ne 0 ] || ! mv "$_daily/.$_name" "$_daily/$_name"; then
        rm -f "$_daily/.$_name"; v_fail "could not write the backup file"; return 1
    fi
    _sz=$(v_fsize "$_daily/$_name")
    v_metric bytes "$_sz"; v_metric tables "${2:-0}"
    v_log "OK: $_name ($(v_hsize "$_sz"))"
    v_gfs "$TARGET_DIR" "$_name" "${_label}_"
    v_summary "$(basename "$DB_PATH") backed up: $_name, $(v_hsize "$_sz"), ${2:-?} table(s)"
}
