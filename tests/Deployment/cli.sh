#!/usr/bin/env bash
# Runs locally with mocked service commands; never installs packages or changes /etc.
set -Eeuo pipefail
# Overrides/globals are invoked through functions in the sourced deployment script.
# shellcheck disable=SC2034,SC2329
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
    touch "$APP/artisan" "$APP/composer.lock" "$APP/package-lock.json"
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
pass 'first deployment accepts its live checkout without moving Git or uploads'

(
    CHECK=true; DOMAIN=''; ENV_FILE=''; CONTACT_EMAIL=''
    APP="$TEST_DIR/in-place-checkout"; SOURCE="$APP"
    CONFIG="$TEST_DIR/unmanaged-config"; STATE="$CONFIG/state.json"; DATA="$TEST_DIR/unmanaged-data"
    touch "$APP/.env"
    check_os() { :; }
    expect_failure 'existing environment without deployment credentials is protected' preflight
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

printf '%s deployment CLI/safety checks passed.\n' "$passed"
