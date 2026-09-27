# Purges an application's temporary files. Deletes files older than MAX_AGE_MIN
# minutes in FILE_DIRS (non-recursive), and abandoned work subdirectories in WORK_DIRS.
TASK_DESC="Temporary files cleanup"
TASK_MAX_AGE=1560
TASK_MAX_RUNTIME=5
# @param FILE_DIRS   | paths | required | Directories whose files are purged          |
# @param WORK_DIRS   | paths | optional | Directories whose subdirectories are purged |
# @param MAX_AGE_MIN | int   | optional | Minimum age before deletion, in minutes     | 1440

task_check() {
    for _d in $FILE_DIRS $WORK_DIRS; do
        v_require_dir "$_d" w && v_ok "$_d accessible"
    done
}

task_run() {
    _files=0; _dirs=0
    for _d in $FILE_DIRS; do
        [ -d "$_d" ] || { v_warn "directory missing: $_d"; continue; }
        _c=$(find "$_d" -maxdepth 1 -type f -mmin +"$MAX_AGE_MIN" -print -exec rm -f {} + | wc -l | tr -d ' ')
        v_log "$_d: $_c file(s) deleted"
        _files=$(( _files + _c ))
    done
    for _d in $WORK_DIRS; do
        [ -d "$_d" ] || continue
        _c=$(find "$_d" -mindepth 1 -maxdepth 1 -type d -mmin +"$MAX_AGE_MIN" -print -exec rm -rf {} + | wc -l | tr -d ' ')
        v_log "$_d: $_c work directory(ies) deleted"
        _dirs=$(( _dirs + _c ))
    done
    v_metric files_removed "$_files"; v_metric dirs_removed "$_dirs"
    v_summary "$_files file(s) and $_dirs work directory(ies) deleted"
}
