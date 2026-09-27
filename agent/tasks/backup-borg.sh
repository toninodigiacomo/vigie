# Borg archive of one or more directories (formerly "nextcloud-borg").
#
# For a consistent archive, the applications writing to these directories can be
# paused for the duration of the backup, in this order:
#   1. CONTAINER       Nextcloud maintenance mode (optional). Better than stopping
#                      Nextcloud: users see a maintenance page, background jobs pause.
#   2. DUMP_SERVICE    a "db-dump" service on this server, run while writes are paused
#                      (before any container is stopped: the database is still up).
#   3. STOP_CONTAINERS containers stopped (optional), for example an application
#                      container, or a database whose files are archived directly.
# Whatever happens (error, interruption), stopped containers are started again,
# in reverse order, and maintenance mode is switched off.
TASK_DESC="Borg backup"
TASK_MAX_AGE=1560
TASK_MAX_RUNTIME=240
# @param SOURCE_DIR      | paths   | required | Directories to archive                          |
# @param REPO_DIR        | path    | required | Borg repository (where archives are written)    |
# @param TARGET_MOUNT    | path    | optional | Repository mount point                          |
# @param PASSPHRASE_VAR  | varname | optional | Variable holding the Borg passphrase            | BORG_PASSPHRASE
# @param CONTAINER       | name    | optional | Nextcloud: container put in maintenance mode     |
# @param OCC_PATH        | path    | optional | Nextcloud: path to occ inside the container       | /app/www/public/occ
# @param OCC_USER        | name    | optional | Nextcloud: user running occ                     | abc
# @param DUMP_SERVICE    | service | optional | Database dump service to run during the pause    |
# @param STOP_CONTAINERS | names   | optional | Containers to stop during the backup             |
# @param COMPRESSION     | name    | optional | Borg compression                                | lz4
# @param KEEP_DAILY      | int     | optional | Daily archives kept                             | 7
# @param KEEP_WEEKLY     | int     | optional | Weekly archives kept                            | 4
# @param KEEP_MONTHLY    | int     | optional | Monthly archives kept                           | 6

occ() { docker exec -u "$OCC_USER" "$CONTAINER" php "$OCC_PATH" "$@"; }

borg_env() {
    v_load_secrets || return 1
    BORG_PASSPHRASE=$(v_secret "$PASSPHRASE_VAR")
    [ -n "$BORG_PASSPHRASE" ] || { v_fail "$PASSPHRASE_VAR missing from $SECRETS_FILE"; return 1; }
    export BORG_PASSPHRASE BORG_REPO="$REPO_DIR"
}

task_check() {
    v_require_cmd borg || return 1
    for _d in $SOURCE_DIR; do v_require_dir "$_d" && v_ok "source readable: $_d"; done
    v_require_mount "$TARGET_MOUNT" || return 1
    if [ -f "$REPO_DIR" ]; then
        v_fail "REPO_DIR is a file ($REPO_DIR), not a Borg repository: give the repository directory, where archives are written"
        return 1
    fi
    borg_env || return 1
    borg list --last 1 >/dev/null 2>"$RUN_TMP/borg.err" && v_ok "Borg repository accessible with the passphrase" ||
        v_fail "Borg repository not accessible: $(head -n 1 "$RUN_TMP/borg.err")"
    if [ -n "$CONTAINER$STOP_CONTAINERS" ]; then v_require_cmd docker || return 1; fi
    if [ -n "$CONTAINER" ]; then
        v_require_container "$CONTAINER" && v_ok "Nextcloud container $CONTAINER running" || return 1
        occ status >/dev/null 2>&1 && v_ok "occ responds" || v_fail "occ does not respond ($OCC_USER, $OCC_PATH)"
    fi
    for _c in $STOP_CONTAINERS; do
        if [ "$_c" = "$CONTAINER" ]; then v_fail "$_c is both put in maintenance mode and stopped: choose one"; continue; fi
        _st=$(docker inspect -f '{{.State.Running}}' "$_c" 2>/dev/null) || { v_fail "container $_c not found"; continue; }
        [ "$_st" = true ] && v_ok "container $_c will be stopped then restarted" ||
            v_warn "container $_c is not running: it will be left as is"
    done
    if [ -n "$DUMP_SERVICE" ]; then
        [ -f "$SVC_DIR/$DUMP_SERVICE.svc" ] && v_ok "dump service $DUMP_SERVICE present" ||
            v_fail "dump service $DUMP_SERVICE unknown on this server"
    fi
}

task_run() {
    v_require_cmd borg || return 1
    v_require_mount "$TARGET_MOUNT" || return 1
    for _d in $SOURCE_DIR; do v_require_dir "$_d" || return 1; done
    if [ -n "$CONTAINER$STOP_CONTAINERS" ]; then v_require_cmd docker || return 1; fi
    borg_env || return 1

    _maint=0 _stopped=
    resume() {
        # Restart in reverse order: the last container stopped is the first started
        for _c in $_stopped; do
            if docker start "$_c" >/dev/null; then v_log "container $_c started"
            else v_fail "could not start container $_c again"
            fi
        done
        _stopped=
        if [ "$_maint" = 1 ]; then
            _maint=0
            if occ maintenance:mode --off; then v_log "maintenance mode disabled"
            else v_fail "could not disable maintenance mode, Nextcloud remains unavailable"
            fi
        fi
    }
    # Whatever happens (error, kill), nothing must stay stopped or in maintenance
    trap resume EXIT
    trap 'resume; exit 143' INT TERM HUP

    if [ -n "$CONTAINER" ]; then
        v_log "enabling Nextcloud maintenance mode"
        occ maintenance:mode --on || { v_fail "could not enable maintenance mode"; return 1; }
        _maint=1
    fi

    if [ -n "$DUMP_SERVICE" ]; then
        v_log "dumping the database (service $DUMP_SERVICE)"
        "$VIGIE_BIN" run "$DUMP_SERVICE" </dev/null >/dev/null 2>&1 ||
            v_fail "database dump (service $DUMP_SERVICE) failed, see its own log"
    fi

    for _c in $STOP_CONTAINERS; do
        [ "$(docker inspect -f '{{.State.Running}}' "$_c" 2>/dev/null)" = true ] || { v_warn "container $_c is not running, left as is"; continue; }
        if docker stop "$_c" >/dev/null; then
            _stopped="$_c $_stopped"            # prepended: restarted in reverse order
            v_log "container $_c stopped"
        else
            v_fail "could not stop container $_c: backup cancelled"; return 1
        fi
    done

    # Nextcloud's updater leaves large temporary copies that are not worth archiving
    _excl=
    [ -n "$CONTAINER" ] && for _d in $SOURCE_DIR; do _excl="$_excl --exclude $_d/nextcloud/data/updater-*"; done

    v_log "creating the Borg archive of: $SOURCE_DIR"
    borg create --stats --compression "$COMPRESSION" $_excl "::archive-{now:%Y-%m-%d_%Hh%M}" $SOURCE_DIR
    _rc=$?
    resume                                      # hand the applications back as early as possible

    case $_rc in
        0) ;;
        1) v_warn "borg create finished with warnings" ;;
        *) v_fail "borg create failed (exit code $_rc)"; return 1 ;;
    esac
    _orig=$(awk '/^This archive:/ { print $3 " " $4; exit }' "$RUN_LOG")
    _dedup=$(awk '/^This archive:/ { print $7 " " $8; exit }' "$RUN_LOG")
    [ -n "$_orig" ]  && v_metric archive_size "$_orig"
    [ -n "$_dedup" ] && v_metric archive_new_data "$_dedup"

    v_log "pruning old archives"
    borg prune --list --keep-daily="$KEEP_DAILY" --keep-weekly="$KEEP_WEEKLY" --keep-monthly="$KEEP_MONTHLY"
    _rc=$?
    [ $_rc -ge 2 ] && { v_fail "borg prune failed (exit code $_rc)"; return 1; }
    [ $_rc -eq 1 ] && v_warn "borg prune finished with warnings"
    # Since Borg 1.2, prune only frees space once the repository is compacted
    if borg help compact >/dev/null 2>&1; then
        v_log "compacting the repository"
        borg compact || v_warn "borg compact failed"
    fi
    v_summary "Archive of ${_orig:-?}, ${_dedup:-?} of new data after deduplication"
}
