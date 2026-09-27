# OpenWRT configuration backup (sysupgrade -b).
TASK_DESC="OpenWRT configuration"
TASK_MAX_AGE=1560
TASK_MAX_RUNTIME=10
# @param TARGET_DIR   | path | required | Backup directory                        |
# @param TARGET_MOUNT | path | optional | Mount point to check                    |
# @param DAILY_DIR    | name | optional | Daily subdirectory                      | daily
# @param WEEKLY_DIR   | name | optional | Weekly subdirectory (Monday)            | weekly
# @param MONTHLY_DIR  | name | optional | Monthly subdirectory (1st of the month) | monthly
# @param KEEP_DAILY   | int  | optional | Daily copies kept                       | 7
# @param KEEP_WEEKLY  | int  | optional | Weekly copies kept                      | 4
# @param KEEP_MONTHLY | int  | optional | Monthly copies kept (0 = all)           | 12

task_check() {
    v_require_cmd sysupgrade tar && v_ok "sysupgrade available"
    v_require_mount "$TARGET_MOUNT" && [ -n "$TARGET_MOUNT" ] && v_ok "$TARGET_MOUNT is mounted"
    _parent=$TARGET_DIR; [ -d "$_parent" ] || _parent=$(dirname "$TARGET_DIR")
    v_require_dir "$_parent" w && v_ok "destination writable: $_parent"
}

task_run() {
    v_require_cmd sysupgrade tar || return 1
    v_require_mount "$TARGET_MOUNT" || return 1
    _daily="$TARGET_DIR/${DAILY_DIR:-daily}"
    mkdir -p "$_daily" || { v_fail "cannot create $_daily"; return 1; }
    _name="openwrt_$(date +%Y-%m-%d_%Hh%M).tar.gz"
    _f="$_daily/$_name"
    if ! sysupgrade -b "$_f"; then rm -f "$_f"; v_fail "sysupgrade -b failed"; return 1; fi
    tar -tzf "$_f" >/dev/null 2>&1 || { v_fail "unreadable archive: $_name"; return 1; }
    _n=$(tar -tzf "$_f" 2>/dev/null | wc -l | tr -d ' ')
    _sz=$(v_fsize "$_f")
    v_metric bytes "$_sz"; v_metric files "$_n"
    v_log "archive created: $_name ($(v_hsize "$_sz"), $_n files)"
    v_gfs "$TARGET_DIR" "$_name" "openwrt_"
    v_summary "$_name, $(v_hsize "$_sz"), $_n files"
}
