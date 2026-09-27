# Disk usage monitoring.
TASK_DESC="Disk space"
TASK_MAX_AGE=180
TASK_MAX_RUNTIME=5
# @param PATHS       | paths | required | Mount points to monitor, space separated | /
# @param WARN_PCT    | int   | optional | Warning threshold (%)                    | 85
# @param CRIT_PCT    | int   | optional | Error threshold (%)                      | 95
# @param COMPAT_PATH | path  | optional | Volume described in the legacy text file |
# @param COMPAT_FILE | path  | optional | Legacy "total used free" file to maintain |

task_check() {
    for _p in $PATHS; do
        if [ "$_p" = / ] || v_is_mounted "$_p"; then v_ok "$_p is mounted"; else v_fail "$_p is not mounted"; fi
    done
    [ -z "$COMPAT_FILE" ] || v_require_dir "$(dirname "$COMPAT_FILE")" w
}

task_run() {
    _sum=
    for _p in $PATHS; do
        if [ "$_p" != / ] && ! v_is_mounted "$_p"; then v_fail "$_p is not mounted"; continue; fi
        _line=$(df -Pk "$_p" 2>/dev/null | awk 'NR == 2 { print $2, $3, $4 }')
        [ -n "$_line" ] || { v_fail "df failed on $_p"; continue; }
        set -- $_line
        [ "$1" -gt 0 ] || continue
        _pct=$(( $2 * 100 / $1 ))
        _name=$(printf '%s' "$_p" | sed 's#^/$#root#; s#^/##; s#/#_#g')
        v_metric "${_name}_used_pct" "$_pct"
        v_metric "${_name}_free_kb" "$3"
        v_log "$_p: $_pct% used, $(v_hsize $(( $3 * 1024 ))) free"
        if   [ "$_pct" -ge "$CRIT_PCT" ]; then v_fail "$_p is $_pct% full"
        elif [ "$_pct" -ge "$WARN_PCT" ]; then v_warn "$_p is $_pct% full"
        fi
        _sum="$_sum, $_p $_pct%"
    done
    if [ -n "$COMPAT_FILE" ] && [ -n "$COMPAT_PATH" ]; then
        df -Pk "$COMPAT_PATH" | awk 'NR == 2 { print $2, $3, $4 }' > "$COMPAT_FILE"
    fi
    v_summary "${_sum#, }"
}
