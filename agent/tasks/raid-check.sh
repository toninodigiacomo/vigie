# Software RAID status: a degraded array is an error, a rebuild is a warning.
TASK_DESC="Software RAID status"
TASK_MAX_AGE=1560
TASK_MAX_RUNTIME=5
# @param DEVICES | paths | optional | Arrays to check (empty = all those in /proc/mdstat) |

md_field() { printf '%s\n' "$2" | awk -F' : ' -v k="$1" '{ sub(/^ +/, "", $1) } $1 == k { sub(/ +$/, "", $2); print $2; exit }'; }
md_devices() { [ -n "$DEVICES" ] && echo "$DEVICES" || awk '/^md[0-9]+ :/ { print "/dev/" $1 }' /proc/mdstat; }

task_check() {
    v_require_cmd mdadm || return 1
    [ -r /proc/mdstat ] || { v_fail "/proc/mdstat not found"; return 1; }
    _devs=$(md_devices)
    [ -n "$_devs" ] && v_ok "arrays found: $(echo $_devs)" || v_fail "no RAID array found"
}

task_run() {
    v_require_cmd mdadm || return 1
    [ -r /proc/mdstat ] || { v_fail "/proc/mdstat not found"; return 1; }
    echo "--- /proc/mdstat"; cat /proc/mdstat; echo
    _devs=$(md_devices)
    [ -n "$_devs" ] || { v_fail "no RAID array found"; return 1; }
    _sum=
    for _d in $_devs; do
        _n=${_d##*/}
        if ! _out=$(mdadm --detail "$_d" 2>&1); then
            echo "$_out"; v_fail "[$_n] mdadm --detail failed"; continue
        fi
        echo "--- mdadm --detail $_d"; echo "$_out"; echo
        _state=$(md_field State "$_out")
        _total=$(md_field "Raid Devices" "$_out")
        _active=$(md_field "Active Devices" "$_out")
        _failed=$(md_field "Failed Devices" "$_out")
        v_metric "${_n}_state" "$_state"
        v_metric "${_n}_disks" "${_active:-?}/${_total:-?}"
        _mm=$(cat "/sys/block/$_n/md/mismatch_cnt" 2>/dev/null) && v_metric "${_n}_mismatch_cnt" "$_mm"
        case $_state in
            *degraded*|*[Ff]ailed*|*inactive*)
                v_fail "[$_n] degraded array: $_state (${_active:-?}/${_total:-?} active disks)" ;;
            *resync*|*recover*|*reshap*)
                v_warn "[$_n] rebuild in progress: $_state" ;;
        esac
        [ "${_failed:-0}" -gt 0 ] 2>/dev/null && v_warn "[$_n] $_failed disk(s) marked as failed"
        _sum="$_sum, $_n ${_state:-?} (${_active:-?}/${_total:-?})"
    done
    grep -q '\[U*_[U_]*]' /proc/mdstat && v_fail "/proc/mdstat reports a missing disk"
    v_summary "${_sum#, }"
}
