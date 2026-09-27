# Scheduled reboot of the server (OpenWRT, Debian, or any Linux with a "reboot" command).
#
# The reboot is started in the background after DELAY_SEC seconds, once this run
# has finished: its report reaches Vigie before the server goes down.
# If other Vigie services are still running (a backup, a copy…), the task waits
# up to WAIT_MAX_MIN minutes for them to finish; after that, it gives up without
# rebooting and reports an error.
# ONLY_IF_NEEDED=yes: reboot only when the system asks for it (/var/run/reboot-required,
# written by Debian and Ubuntu package upgrades). Other systems never ask.
TASK_DESC="Server reboot"
TASK_MAX_AGE=46080
TASK_MAX_RUNTIME=90
# @param DELAY_SEC      | int  | optional | Delay before rebooting, in seconds             | 60
# @param WAIT_MAX_MIN   | int  | optional | Maximum wait for running services, in minutes  | 60
# @param ONLY_IF_NEEDED | bool | optional | Only when the system requires it (Debian, Ubuntu) | no

REBOOT_FLAG=/var/run/reboot-required

# Other Vigie services currently running on this server (live locks).
busy_services() {
    for _l in "$STATE_DIR"/lock/svc-*; do
        [ -d "$_l" ] || continue
        _id=${_l##*/svc-}
        [ "$_id" = "$SVC_ID" ] && continue
        _pid=$(cat "$_l/pid" 2>/dev/null)
        [ -n "$_pid" ] && kill -0 "$_pid" 2>/dev/null &&
            printf '%s ' "$(sed -n 's/^@name=//p' "$SVC_DIR/$_id.svc" 2>/dev/null || echo "service $_id")"
    done
}

reboot_cmd() {
    command -v reboot 2>/dev/null || { [ -x /sbin/reboot ] && echo /sbin/reboot; }
}

task_check() {
    [ "$(id -u)" = 0 ] && v_ok "running as root" || v_fail "the agent must run as root to reboot"
    [ -n "$(reboot_cmd)" ] && v_ok "reboot command: $(reboot_cmd)" || v_fail "no reboot command found"
    _up=$(awk '{ print int($1) }' /proc/uptime 2>/dev/null)
    [ -n "$_up" ] && v_log "up for $(( _up / 86400 )) day(s) $(( _up % 86400 / 3600 )) h"
    if [ "$ONLY_IF_NEEDED" = yes ]; then
        if [ -f "$REBOOT_FLAG" ]; then v_log "a reboot is currently required"
        else v_log "no reboot currently required: the server would not be rebooted now"
        fi
        [ -d /var/run ] && command -v apt-get >/dev/null 2>&1 ||
            v_warn "this system does not seem to report required reboots: it will never be rebooted"
    fi
    _busy=$(busy_services)
    [ -z "$_busy" ] || v_log "running now: $_busy(a reboot would wait for them)"
    v_log "this check never reboots the server"
}

task_run() {
    [ "$(id -u)" = 0 ] || { v_fail "the agent must run as root to reboot"; return 1; }
    _cmd=$(reboot_cmd)
    [ -n "$_cmd" ] || { v_fail "no reboot command found"; return 1; }
    if [ "$ONLY_IF_NEEDED" = yes ] && [ ! -f "$REBOOT_FLAG" ]; then
        v_log "no reboot required ($REBOOT_FLAG absent)"
        v_summary "No reboot required"
        return 0
    fi

    # Wait for the other services, so that no backup is cut off midway
    _deadline=$(( $(date +%s) + WAIT_MAX_MIN * 60 ))
    while _busy=$(busy_services); [ -n "$_busy" ]; do
        if [ "$(date +%s)" -ge "$_deadline" ]; then
            v_fail "still running after $WAIT_MAX_MIN min: $_busy- reboot cancelled"
            return 1
        fi
        v_log "waiting for: $_busy"
        sleep 30
    done

    _up=$(awk '{ print int($1) }' /proc/uptime 2>/dev/null)
    [ -n "$_up" ] && v_metric uptime_days "$(( _up / 86400 ))"
    v_log "reboot in $DELAY_SEC s ($_cmd)"
    # Detached from this run: the agent sends its report, then the server reboots
    if command -v setsid >/dev/null 2>&1; then
        setsid sh -c "sleep $DELAY_SEC; sync; exec $_cmd" </dev/null >/dev/null 2>&1 &
    else
        nohup sh -c "sleep $DELAY_SEC; sync; exec $_cmd" </dev/null >/dev/null 2>&1 &
    fi
    v_summary "Reboot started (in $DELAY_SEC s), after $(( ${_up:-0} / 86400 )) day(s) of uptime"
}
