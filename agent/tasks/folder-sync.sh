# Copies a directory to the backup disk (rsync, formerly "library-sync"). Potentially long-running:
# the agent sends a regular "running" signal with the latest copied files.
TASK_DESC="Directory sync (rsync)"
TASK_MAX_AGE=11520
TASK_MAX_RUNTIME=720
# @param SOURCE_DIR   | path | required | Source directory                              |
# @param TARGET_DIR   | path | required | Destination directory                         |
# @param TARGET_MOUNT | path | optional | Mount point to check before copying           |
# @param DELETE_EXTRA | bool | optional | Delete at destination what left the source    | no

task_check() {
    v_require_cmd rsync || return 1
    v_require_dir "$SOURCE_DIR" && v_ok "source readable: $SOURCE_DIR"
    v_require_mount "$TARGET_MOUNT" && [ -n "$TARGET_MOUNT" ] && v_ok "$TARGET_MOUNT is mounted"
    _parent=$TARGET_DIR; [ -d "$_parent" ] || _parent=$(dirname "$TARGET_DIR")
    v_require_dir "$_parent" w && v_ok "destination writable: $_parent"
}

task_run() {
    v_require_cmd rsync || return 1
    v_require_dir "$SOURCE_DIR" || return 1
    v_require_mount "$TARGET_MOUNT" || return 1
    mkdir -p "$TARGET_DIR" || { v_fail "cannot create $TARGET_DIR"; return 1; }
    _del=; [ "$DELETE_EXTRA" = yes ] && _del=--delete

    v_log "rsync ${SOURCE_DIR%/}/ to ${TARGET_DIR%/}/ ${_del}"
    rsync -a --partial -h --stats --out-format='%n' $_del "${SOURCE_DIR%/}/" "${TARGET_DIR%/}/"
    _rc=$?

    _files=$(awk -F': ' '/^Number of (regular )?files transferred:/ { gsub(/,/, "", $2); print $2; exit }' "$RUN_LOG")
    _size=$(awk -F': ' '/^Total transferred file size:/ { sub(/ bytes$/, "", $2); print $2; exit }' "$RUN_LOG")
    _total=$(awk -F': ' '/^Number of files:/ { split($2, a, " "); gsub(/,/, "", a[1]); print a[1]; exit }' "$RUN_LOG")
    [ -n "$_files" ] && v_metric files_transferred "$_files"
    [ -n "$_size" ]  && v_metric size_transferred "$_size"
    [ -n "$_total" ] && v_metric files_total "$_total"

    case $_rc in
        0)  ;;
        24) v_warn "some source files vanished during the copy (rsync 24)" ;;
        *)  v_fail "rsync failed (exit code $_rc)"; return 1 ;;
    esac
    v_summary "${_files:-?} file(s) copied, ${_size:-?} transferred, ${_total:-?} in total"
}
