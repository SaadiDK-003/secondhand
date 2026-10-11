#!/usr/bin/env bash
# Runs locally with mocked service commands; never installs packages or changes /etc.
# Overrides/globals are invoked through functions in the sourced deployment script.
# shellcheck disable=SC2034,SC2329
set -Eeuo pipefail
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
# shellcheck source=../../deploy.sh
source "$ROOT/deploy.sh"
TEST_DIR=$(mktemp -d)
trap 'rm -rf -- "$TEST_DIR"' EXIT
if [[ -n "${PYTHON_BINARY:-}" ]]; then
    python3() { "$PYTHON_BINARY" "$@"; }
fi
passed=0
pass() { passed=$((passed + 1)); printf 'PASS %s\n' "$1"; }
expect_failure() {
    local label=$1
    shift
    if ("$@") > "$TEST_DIR/output" 2>&1; then
        printf 'FAIL %s unexpectedly succeeded\n' "$label" >&2; exit 1
    fi
    pass "$label"
}

for hostname in secondhand.webostics.com secondhand.om.pk example.com; do valid_domain "$hostname"; done
pass 'valid hostnames'
for hostname in 'https://a.com' 'a.com:443' 'a.com;id' '*.a.com' 'a..com' 'a-.com' 'a.com/' '127.0.0.1'; do
    expect_failure 'reject malformed/injected hostname' valid_domain "$hostname"
done
expect_failure 'unknown argument' bash "$ROOT/deploy.sh" --unknown
expect_failure 'missing domain' bash "$ROOT/deploy.sh" --domain
expect_failure 'duplicate domain' bash "$ROOT/deploy.sh" --domain a.com --domain b.com
expect_failure 'removed rollback option' bash "$ROOT/deploy.sh" --rollback --domain a.com
expect_failure 'removed recovery option' bash "$ROOT/deploy.sh" --recover --check
expect_failure 'certificate email injection' bash "$ROOT/deploy.sh" --email 'a@example.com --other'
bash "$ROOT/deploy.sh" --help > "$TEST_DIR/output"
grep -q 'secondhand.webostics.com' "$TEST_DIR/output"
pass 'help and initial domain'

(
    CHECK=false; DOMAIN=''; ENV_FILE=''; CONTACT_EMAIL=''
    CONFIG="$TEST_DIR/dry-config"; STATE="$CONFIG/state.json"
    check_os() { :; }; check_public_ports() { :; }; check_saved_ports() { :; }
    check_nginx_domain() { :; }; validate_input() { :; }
    main --check --domain secondhand.om.pk > "$TEST_DIR/dry-output"
    [[ ! -e "$CONFIG" ]]
    grep -q 'No packages, files, services or certificates changed' "$TEST_DIR/dry-output"
)
pass 'check mode does not create deployment state or install packages'

(
    CHECK=true; DOMAIN=''; ENV_FILE=''; CONTACT_EMAIL=''
    BASE="$TEST_DIR/in-place-support"; APP="$TEST_DIR/in-place-checkout"; SOURCE="$APP"
    CONFIG="$TEST_DIR/in-place-config"; STATE="$CONFIG/state.json"; DATA="$TEST_DIR/in-place-data"
    mkdir -p "$APP/.git" "$APP/storage/app/private"
    touch "$APP/artisan" "$APP/composer.lock" "$APP/package-lock.json" "$APP/.env"
    printf 'keep Git metadata\n' > "$APP/.git/config"
    printf 'keep private uploads\n' > "$APP/storage/app/private/image"
    before=$(sha256sum "$APP/.git/config" "$APP/storage/app/private/image")
    check_os() { :; }; check_public_ports() { :; }; check_saved_ports() { :; }
    check_nginx_domain() { :; }; validate_input() { :; }; inspect_requirements() { :; }
    state_get() { printf ''; }
    preflight >/dev/null
    [[ $(sha256sum "$APP/.git/config" "$APP/storage/app/private/image") == "$before" ]]
    [[ ! -e "$CONFIG" && "$DOMAIN" == secondhand.webostics.com ]]
)
pass 'first deployment accepts its root .env without moving Git or uploads'

(
    CHECK=true; DOMAIN=''; ENV_FILE=''; CONTACT_EMAIL=''
    APP="$TEST_DIR/in-place-checkout"; SOURCE="$APP"
    CONFIG="$TEST_DIR/unmanaged-config"; STATE="$CONFIG/state.json"; DATA="$TEST_DIR/unmanaged-data"
    mkdir -p "$DATA/postgres"
    touch "$DATA/postgres/PG_VERSION"
    check_os() { :; }
    expect_failure 'existing service data without deployment credentials is protected' preflight
)
passed=$((passed + 1))

(
    ENV_FILE=''; DOMAIN=example.com; APP="$TEST_DIR/root-env"; STATE="$TEST_DIR/missing-state"
    mkdir -p "$APP"; touch "$APP/.env"
    stat() {
        case "$2" in '%u') printf '0';; '%a') printf '640';; '%G') printf 'secondhand';; esac
    }
    python3() { printf '%s\n' "$*" > "$TEST_DIR/env-args"; }
    validate_input
    [[ "$ENV_FILE" == "$APP/.env" ]]
    grep -q -- '--input' "$TEST_DIR/env-args"
)
pass 'protected root .env is read automatically on repeat deployments'

(
    ENV_FILE="$TEST_DIR/root-env/.env"; DOMAIN=example.com
    stat() {
        case "$2" in '%u') printf '0';; '%a') printf '644';; '%G') printf 'root';; esac
    }
    expect_failure 'world-readable production environment is rejected' validate_input
)
passed=$((passed + 1))

(
    ENV_FILE=''; DOMAIN=example.com; APP="$TEST_DIR/auto-env"; STATE="$TEST_DIR/missing-state"
    getent() { return 1; }
    chown() { printf 'chown %s\n' "$*" >> "$TEST_DIR/auto-env-actions"; }
    # This case exercises only direct chmod calls in prepare_environment.
    # shellcheck disable=SC2032
    chmod() { printf 'chmod %s\n' "$*" >> "$TEST_DIR/auto-env-actions"; }
    python3() {
        printf '%s\n' "$*" >> "$TEST_DIR/auto-env-actions"
        if [[ "$2" == bootstrap ]]; then touch "$ENV_FILE"; fi
    }
    validate_input() { printf 'validated\n' >> "$TEST_DIR/auto-env-actions"; }
    prepare_environment
    [[ -f "$APP/.env" && "$ENV_FILE" == "$APP/.env" ]]
    grep -q -- '--template' "$TEST_DIR/auto-env-actions"
    grep -q 'chown root:root' "$TEST_DIR/auto-env-actions"
    grep -q 'chmod 600' "$TEST_DIR/auto-env-actions"
    grep -q 'validated' "$TEST_DIR/auto-env-actions"
)
pass 'normal deployment automatically bootstraps and protects the root environment'

(
    APP="$TEST_DIR/auto-env"; ENV_FILE="$APP/.env"; DOMAIN=example.com
    stat() { printf '2'; }
    expect_failure 'automatic permission changes reject hard-linked environment files' prepare_environment
)
passed=$((passed + 1))

for saved in '' saved.example.com; do
    (
        DOMAIN=''; CHECK=true
        CONFIG="$TEST_DIR/domain-config"; STATE="$CONFIG/state.json"
        BASE="$TEST_DIR/domain-app"; APP="$BASE/app"; DATA="$TEST_DIR/domain-data"; RUNTIME="$TEST_DIR/domain-runtime"
        check_os() { :; }; check_public_ports() { :; }; check_saved_ports() { :; }
        check_nginx_domain() { :; }; validate_input() { :; }; inspect_requirements() { :; }
        state_get() { printf '%s\n' "$saved"; }
        preflight >/dev/null
        [[ "$DOMAIN" == "${saved:-secondhand.webostics.com}" ]]
        [[ ! -e "$CONFIG" ]]
    )
    pass "hostname is retained or defaults correctly (saved=${saved:-none})"
done


(
    ss() {
        case "${*: -1}" in
            'sport = :5432'|'sport = :20000') printf 'LISTEN ::1:occupied\n' ;;
        esac
    }
    grep() { return 1; }
    [[ $(select_port 5432 '20001') == 20002 ]]
)
pass 'IPv6 listener and reserved ports force a separate private port'

(
    DOMAIN=secondhand.om.pk
    CERT_LINEAGE=/etc/letsencrypt/live/example
    nginx_https > "$TEST_DIR/nginx.conf"
    grep -q 'server_name secondhand.om.pk;' "$TEST_DIR/nginx.conf"
    grep -q 'fastcgi_pass unix:/run/php/secondhand.sock;' "$TEST_DIR/nginx.conf"
    grep -q 'client_max_body_size 128m;' "$TEST_DIR/nginx.conf"
    grep -q 'fastcgi_param HTTPS on;' "$TEST_DIR/nginx.conf"
    grep -q 'root /var/www/html/secondhand/public;' "$TEST_DIR/nginx.conf"
    if grep -q ':3000' "$TEST_DIR/nginx.conf"; then exit 1; fi
)
pass 'Nginx uses the requested domain and dedicated FPM socket'

(
    id() { printf 'www-data\n'; }
    php_fpm_pool www-data > "$TEST_DIR/fpm.conf"
    grep -q '^; Managed by secondhand deploy.sh$' "$TEST_DIR/fpm.conf"
    if grep -q '^#' "$TEST_DIR/fpm.conf"; then exit 1; fi
    grep -q '^listen = /run/php/secondhand.sock$' "$TEST_DIR/fpm.conf"
    grep -q '^listen.owner = www-data$' "$TEST_DIR/fpm.conf"
    grep -q '^user = secondhand$' "$TEST_DIR/fpm.conf"
    grep -q '^php_admin_flag\[display_errors\] = off$' "$TEST_DIR/fpm.conf"
)
pass 'PHP-FPM pool uses INI comments and retains private socket and error settings'

for marker in ';' '#'; do
    (
        FPM_POOL="$TEST_DIR/managed-fpm.conf"; NGINX_SITE="$TEST_DIR/missing-site"; NGINX_ACME="$TEST_DIR/missing-acme"
        printf '%s Managed by secondhand deploy.sh\n' "$marker" > "$FPM_POOL"
        check_nginx_domain
    )
    pass "preflight recognizes the app's PHP-FPM ownership marker ($marker)"
done

(
    STATE="$TEST_DIR/exists"; touch "$STATE"
    state_get() { printf '20001\n'; }
    systemctl() { printf '123\n'; }
    ss() { printf 'LISTEN 127.0.0.1:20001 users:(("node",pid=124,fd=1))\n'; }
    expect_failure 'saved port owned by another process is rejected' check_saved_ports
)
passed=$((passed + 1))

(
    ss() { printf 'LISTEN *:80 users:(("apache2",pid=1,fd=1))\n'; }
    expect_failure 'foreign public web server is not terminated' check_public_ports
)
passed=$((passed + 1))


for migration in false true; do
    case_dir="$TEST_DIR/failure-$migration"
    mkdir -p "$case_dir/config/run.test" "$case_dir/app"
    touch "$case_dir/app/artisan"
    (
        trap - EXIT
        CONFIG="$case_dir/config"; STATE="$CONFIG/state.json"; WORK="$CONFIG/run.test"
        APP="$case_dir/app"; STAGING=''
        SUCCESS=false; MAINTENANCE=true; SNAPSHOT_READY=true; MIGRATIONS_STARTED=$migration
        systemctl() { printf '%s\n' "$*" >> "$case_dir/service-actions"; }
        artisan() { printf '%s\n' "$*" >> "$case_dir/artisan-actions"; }
        restore_configs() { touch "$case_dir/config-restored"; }
        set +e
        false
        finish
    ) > "$TEST_DIR/failure-output" 2>&1 && { printf 'Failure status was lost\n' >&2; exit 1; }
    [[ -f "$case_dir/config-restored" && -d "$case_dir/config/run.test" ]]
    if $migration; then
        grep -q 'down --retry=60' "$case_dir/artisan-actions"
        if grep -q ' up' "$case_dir/artisan-actions"; then exit 1; fi
        grep -q '^stop ' "$case_dir/service-actions"
    else
        grep -q ' up' "$case_dir/artisan-actions"
        grep -q '^start ' "$case_dir/service-actions"
    fi
    pass "failure handling keeps schema changes safe (migrations started=$migration)"
done

(
    # A completed deploy reuses installed packages; apt must never be invoked here.
    dpkg-query() { printf 'installed'; }
    run_quiet() { printf 'Unexpected package installation\n' >&2; exit 1; }
    apt_missing nginx php8.4-cli redis-server
)
pass 'already-installed packages are not reinstalled'

(
    CHECK=true
    init_display
    stage 1 'Read-only server checks'
    log 'Readable piped output'
    [[ "$UI_INTERACTIVE" == false && -z "$C_RESET" && "$STAGE_TOTAL" == 1 ]]
    [[ $(duration 75) == '1m 15s' && $(duration 8) == '8s' ]]
) > "$TEST_DIR/plain-output"
if grep -q $'\033' "$TEST_DIR/plain-output"; then exit 1; fi
grep -q '\[1/1\] Read-only server checks' "$TEST_DIR/plain-output"
pass 'redirected output has readable stages and durations without ANSI codes'

(
    NO_COLOR=1; TERM=dumb
    init_display
    [[ -z "$C_BLUE" && -z "$C_GREEN" && "$UI_INTERACTIVE" == false ]]
)
pass 'NO_COLOR and dumb terminals use plain output'

(
    WORK="$TEST_DIR/display-command"; mkdir -p "$WORK"
    STATE="$TEST_DIR/no-display-state"; ENV_FILE="$TEST_DIR/display.env"
    DEPLOY_LOG="$TEST_DIR/display.log"
    printf 'APP_KEY=display-test-private-value\n' > "$ENV_FILE"
    run_quiet 'Verify output privacy' printf 'result: display-test-private-value\n'
    receive_input() { local input; IFS= read -r input; [[ "$input" == private-test-input ]]; }
    run_quiet 'Preserve command stdin' receive_input <<< 'private-test-input'
    [[ ! -s "$WORK/command.log" ]]
    grep -q '\[REDACTED\]' "$DEPLOY_LOG"
    if grep -q 'display-test-private-value' "$DEPLOY_LOG"; then exit 1; fi
) > "$TEST_DIR/display-success"
grep -q '\[RUN \] Verify output privacy' "$TEST_DIR/display-success"
grep -q '\[OK  \] Verify output privacy' "$TEST_DIR/display-success"
if grep -q 'display-test-private-value' "$TEST_DIR/display-success"; then exit 1; fi
pass 'timed task output stays readable and secrets remain redacted in protected logs'

(
    WORK="$TEST_DIR/display-command"; STATE="$TEST_DIR/no-display-state"; ENV_FILE=''
    DEPLOY_LOG="$TEST_DIR/display.log"
    expect_failure 'failed task still exits unsuccessfully' run_quiet 'Deliberate failure' false
    grep -q '\[FAIL\] Deliberate failure' "$TEST_DIR/output"
) > "$TEST_DIR/display-failure"
pass 'task errors retain failure status and identify the failed task'

(
    UI_INTERACTIVE=true
    sleep() { command sleep 0.02; }
    start_activity 'Long test task'
    activity=$ACTIVITY_PID
    command sleep 1
    stop_activity
    [[ -z "$ACTIVITY_PID" ]]
    if kill -0 "$activity" 2>/dev/null; then exit 1; fi
) > "$TEST_DIR/activity-output"
grep -q '\[WAIT\] Long test task is still running' "$TEST_DIR/activity-output"
pass 'long task updates stop promptly without leaving the display process running'

(
    WORK="$TEST_DIR/interrupted-display"; mkdir -p "$WORK"
    STAGING=''; SUCCESS=false; MAINTENANCE=false; SNAPSHOT_READY=false
    DEPLOY_LOG="$TEST_DIR/display.log"; STAGE_NUMBER=4
    UI_INTERACTIVE=true
    trap finish EXIT
    trap 'exit 143' TERM
    start_activity 'Interrupted task'
    printf '%s' "$ACTIVITY_PID" > "$TEST_DIR/interrupted-display.pid"
    kill -TERM "$BASHPID"
) > "$TEST_DIR/interrupted-display-output" 2>&1 && { printf 'Interrupt status was lost\n' >&2; exit 1; }
activity=$(cat "$TEST_DIR/interrupted-display.pid")
if kill -0 "$activity" 2>/dev/null; then exit 1; fi
grep -q 'Deployment stopped in stage 4/8' "$TEST_DIR/interrupted-display-output"
pass 'interrupted deployment stops its display helper and retains a failure exit status'

(
    DOMAIN=secondhand.webostics.com
    PG_PORT=20000; REDIS_PORT=20001; MEILI_PORT=7700; SSR_PORT=13714
    STAGE_NUMBER=7
    systemctl() {
        if [[ "$2" == secondhand-horizon.service ]]; then printf 'inactive'; return 3; fi
        printf 'active'
    }
    show_summary
) > "$TEST_DIR/summary-output"
grep -q 'PostgreSQL.*active.*127.0.0.1:20000' "$TEST_DIR/summary-output"
grep -q 'Queue workers.*inactive' "$TEST_DIR/summary-output"
grep -q 'A service needs attention' "$TEST_DIR/summary-output"
grep -q '\[8/8\] Deployment summary' "$TEST_DIR/summary-output"
pass 'service summary displays saved ports and flags inactive services'

printf '%s deployment CLI/safety checks passed.\n' "$passed"
