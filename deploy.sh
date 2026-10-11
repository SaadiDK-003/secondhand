#!/usr/bin/env bash
# Deploy this checkout on Ubuntu 24.04. See docs/deployment.md.
set -Eeuo pipefail
umask 027

SOURCE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
HELPER="$SOURCE/deploy/config.py"
BASE=/var/www/secondhand
CONFIG=/etc/secondhand
STATE="$CONFIG/state.json"
DATA=/var/lib/secondhand
RUNTIME=/opt/secondhand
USER_NAME=secondhand
NGINX_SITE=/etc/nginx/conf.d/secondhand.conf
NGINX_ACME=/etc/nginx/conf.d/secondhand-acme.conf
FPM_POOL=/etc/php/8.4/fpm/pool.d/secondhand.conf
OS_RELEASE=/etc/os-release
DOMAIN='' ENV_FILE='' CONTACT_EMAIL='' CHECK=false
SUCCESS=false MAINTENANCE=false SNAPSHOT_READY=false
WORK='' STAGING='' POLICY_CREATED=false PG_CONFIG_BACKUP=''
APP=/var/www/html/secondhand
PG_BIN='' MIGRATIONS_STARTED=false
PHP=/usr/bin/php8.4 NODE='' COMPOSER=''
MEILI_VERSION=1.54.3
DEPLOY_LOG=/var/log/secondhand-deploy.log
UI_INTERACTIVE=false STAGE_NUMBER=0 STAGE_TOTAL=8
START_TIME=$SECONDS STAGE_TIME=$SECONDS ACTIVITY_PID=''
C_RESET='' C_BOLD='' C_BLUE='' C_GREEN='' C_YELLOW='' C_RED=''

init_display() {
    UI_INTERACTIVE=false
    C_RESET='' C_BOLD='' C_BLUE='' C_GREEN='' C_YELLOW='' C_RED=''
    if [[ -t 1 && "${TERM:-dumb}" != dumb ]]; then
        UI_INTERACTIVE=true
        if [[ ! -v NO_COLOR ]]; then
            C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_BLUE=$'\033[36m'
            C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_RED=$'\033[31m'
        fi
    fi
    START_TIME=$SECONDS; STAGE_TIME=$SECONDS; STAGE_NUMBER=0
    STAGE_TOTAL=8; if $CHECK; then STAGE_TOTAL=1; fi
}

duration() {
    local elapsed=$1
    if ((elapsed >= 60)); then printf '%dm %02ds' "$((elapsed / 60))" "$((elapsed % 60))";
    else printf '%ss' "$elapsed"; fi
}

message() { printf '  %s[%-4s]%s %s\n' "$1" "$2" "$C_RESET" "$3"; }
log() { message "$C_BLUE" INFO "$*"; }
done_message() { message "$C_GREEN" OK "$*"; }
skip_message() { message "$C_YELLOW" SKIP "$*"; }

fatal() {
    stop_activity
    message "$C_RED" FAIL "$*" >&2
    exit 1
}

stage() {
    if ((STAGE_NUMBER > 0)); then
        done_message "Stage $STAGE_NUMBER complete ($(duration "$((SECONDS - STAGE_TIME))"))."
    fi
    STAGE_NUMBER=$1; STAGE_TIME=$SECONDS
    printf '\n%s[%s/%s] %s%s\n' "$C_BOLD$C_BLUE" "$STAGE_NUMBER" "$STAGE_TOTAL" "$2" "$C_RESET"
    [[ -z "${3:-}" ]] || printf '      %s\n' "$3"
}

start_activity() {
    $UI_INTERACTIVE || return 0
    local label=$1 started=$SECONDS
    (
        # Only the display helper is asynchronous; deployment commands retain stdin
        # and their normal signal/exit behavior. Clean up its sleeping child too.
        sleeper=''
        trap 'if [[ -n "$sleeper" ]]; then kill "$sleeper" 2>/dev/null || true; fi; exit 0' INT TERM
        while true; do
            sleep 15 & sleeper=$!
            wait "$sleeper" || exit 0
            message "$C_YELLOW" WAIT "$label is still running ($(duration "$((SECONDS - started))"))."
        done
    ) &
    ACTIVITY_PID=$!
}

stop_activity() {
    if [[ -n "$ACTIVITY_PID" ]]; then
        kill "$ACTIVITY_PID" 2>/dev/null || true
        wait "$ACTIVITY_PID" 2>/dev/null || true
        ACTIVITY_PID=''
    fi
}

show_context() {
    printf '\n  %-12s %s\n' Website "https://$DOMAIN"
    printf '  %-12s %s\n' Directory "$APP"
    if ! $CHECK; then printf '  %-12s %s\n' Details "$DEPLOY_LOG (protected, redacted)"; fi
}

show_summary() {
    stage 8 'Deployment summary'
    show_context
    printf '\n  %-22s %-9s %s\n' SERVICE STATUS CONNECTION
    printf '  %-22s %-9s %s\n' '----------------------' '---------' '-----------------------'
    local service label connection status attention=false
    while IFS='|' read -r service label connection; do
        status=$(systemctl is-active "$service" || true)
        status=${status:-unknown}
        if [[ "$status" == active ]]; then
            printf '  %-22s %s%-9s%s %s\n' "$label" "$C_GREEN" "$status" "$C_RESET" "$connection"
        else
            attention=true
            printf '  %-22s %s%-9s%s %s\n' "$label" "$C_RED" "$status" "$C_RESET" "$connection"
        fi
    done <<EOF
secondhand-postgres.service|PostgreSQL|127.0.0.1:$PG_PORT
secondhand-redis.service|Redis|127.0.0.1:$REDIS_PORT
secondhand-meili.service|Meilisearch|127.0.0.1:$MEILI_PORT
secondhand-ssr.service|Page rendering (SSR)|127.0.0.1:$SSR_PORT
secondhand-horizon.service|Queue workers|Horizon
secondhand-scheduler.timer|Scheduler|Every minute
php8.4-fpm.service|PHP 8.4 FPM|Dedicated app socket
nginx.service|Nginx / HTTPS|Ports 80 / 443
EOF
    printf '\n'
    done_message "Website deployed: https://$DOMAIN"
    log "Total time: $(duration "$((SECONDS - START_TIME))")."
    log "Site health: https://$DOMAIN/admin/site-health"
    if $attention; then message "$C_YELLOW" WARN 'A service needs attention. Inspect its systemd journal.'; fi
    printf '\n'
}

usage() {
    cat <<'EOF'
Usage: sudo bash deploy.sh [--domain HOST] [--env-file FILE] [--email ADDRESS]
       sudo bash deploy.sh --check [--domain HOST] [--env-file FILE]

Initial domain: secondhand.webostics.com. Subsequent runs retain the saved domain.
--check performs read-only preflight; it does not install or deploy anything.
Creates root .env from .env.example if missing and protects it automatically.
Missing SMTP credentials are requested once in the terminal (password hidden).
Images default to private VPS storage; cloud storage is optional.
EOF
}

valid_domain() {
    local host=${1,,} label
    [[ ${#host} -le 253 && "$host" == *.* && "$host" != *[^a-z0-9.-]* ]] || return 1
    local -a labels
    IFS=. read -r -a labels <<< "$host"
    [[ "$host" != *. && "${labels[-1]}" =~ ^[a-z][a-z0-9-]*[a-z0-9]$|^[a-z]$ ]] || return 1
    for label in "${labels[@]}"; do
        [[ ${#label} -ge 1 && ${#label} -le 63 && "$label" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?$ ]] || return 1
    done
}

parse_args() {
    local domain_seen=false env_seen=false email_seen=false
    while (($#)); do
        case "$1" in
            --domain|--env-file|--email)
                [[ $# -ge 2 && -n "$2" && "$2" != --* ]] || fatal "$1 requires a value."
                case "$1" in
                    --domain) $domain_seen && fatal 'Duplicate --domain.'; DOMAIN=${2,,}; domain_seen=true ;;
                    --env-file) $env_seen && fatal 'Duplicate --env-file.'; ENV_FILE=$2; env_seen=true ;;
                    --email) $email_seen && fatal 'Duplicate --email.'; CONTACT_EMAIL=$2; email_seen=true ;;
                esac
                shift 2 ;;
            --check) $CHECK && fatal 'Duplicate --check.'; CHECK=true; shift ;;
            --help|-h) usage; exit 0 ;;
            *) fatal 'Unknown argument. Use --help.' ;;
        esac
    done
    [[ -z "$DOMAIN" ]] || valid_domain "$DOMAIN" || fatal 'Domain must be a hostname, without URL, port or wildcard.'
    [[ -z "$CONTACT_EMAIL" || "$CONTACT_EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || fatal 'Invalid certificate contact email.'

}

state_get() { python3 "$HELPER" get --state "$STATE" --key "$1"; }

check_os() {
    [[ -r "$OS_RELEASE" ]] || fatal 'Cannot identify the operating system.'
    # Read fixed OS metadata without sourcing executable shell text.
    local id version arch
    id=$(sed -n 's/^ID=//p' "$OS_RELEASE" | tr -d '"')
    version=$(sed -n 's/^VERSION_ID=//p' "$OS_RELEASE" | tr -d '"')
    [[ "$id" == ubuntu && "$version" == 24.04 ]] || fatal 'Supported OS: Ubuntu 24.04 LTS only.'
    arch=$(uname -m)
    [[ "$arch" == x86_64 || "$arch" == aarch64 ]] || fatal 'Supported architectures: amd64 and arm64.'
    [[ -d /run/systemd/system ]] || fatal 'A running systemd host is required.'
    if ! command -v python3 >/dev/null || ! command -v ss >/dev/null; then
        $CHECK && fatal 'Missing bootstrap requirements: python3/iproute2.'
        [[ "$EUID" == 0 ]] || fatal 'Run with sudo.'
        apt-get update >/dev/null
        DEBIAN_FRONTEND=noninteractive apt-get install -y --no-upgrade python3 iproute2 >/dev/null
    fi
    [[ "$EUID" == 0 ]] || fatal 'Run with sudo, including --check when inspecting protected deployment state.'
}

check_public_ports() {
    command -v ss >/dev/null || fatal 'Install iproute2 first; port ownership must be inspectable.'
    local port output
    for port in 80 443; do
        output=$(ss -H -ltnp "sport = :$port")
        if [[ -n "$output" ]]; then
            while IFS= read -r line; do
                [[ "$line" == *'"nginx"'* ]] || fatal "Port $port belongs to another web server. Integrate with its proxy first; no process was stopped."
            done <<< "$output"
            [[ -x /usr/sbin/nginx ]] || fatal 'The existing Nginx is not a native /usr/sbin/nginx installation; integrate its proxy manually.'
            systemctl is-active --quiet nginx || fatal 'Ports 80/443 are owned by Nginx outside the native nginx service; manual integration is required.'
        fi
    done
}

check_saved_ports() {
    [[ -f "$STATE" ]] || return 0
    local key port unit pid output
    for key in postgres redis meili ssr; do
        port=$(state_get "ports.$key")
        output=$(ss -H -ltnp "sport = :$port")
        [[ -n "$output" ]] || continue
        unit="secondhand-$key.service"
        pid=$(systemctl show "$unit" -p MainPID --value 2>/dev/null || true)
        [[ "$pid" =~ ^[1-9][0-9]*$ && "$output" == *"pid=$pid,"* ]] || fatal "Saved $key port $port is occupied by another process; resolve it explicitly."
        # A second IPv4/IPv6 listener must also belong to the same service.
        while IFS= read -r line; do
            [[ "$line" == *"pid=$pid,"* ]] || fatal "Another process shares saved $key port $port."
        done <<< "$output"
    done
}

check_nginx_domain() {
    local file
    for file in "$NGINX_SITE" "$NGINX_ACME" "$FPM_POOL" /etc/systemd/system/secondhand-{postgres,redis,meili,ssr,horizon,scheduler}.service /etc/systemd/system/secondhand-scheduler.timer; do
        if [[ -e "$file" || -L "$file" ]]; then
            [[ ! -L "$file" ]] || fatal "Reserved configuration path is a symlink: $file"
            grep -qE '^[#;] Managed by secondhand deploy.sh$' "$file" || fatal "Reserved configuration path already exists: $file"
        fi
    done
    [[ -x /usr/sbin/nginx ]] || return 0
    if $CHECK; then
        # nginx -t/-T can create logs/PID/temp paths. Read includes without running it.
        python3 "$HELPER" nginx-check --state "$STATE" --domain "$DOMAIN" --input /etc/nginx/nginx.conf
        return
    fi
    /usr/sbin/nginx -t >/dev/null 2>&1 || fatal 'Existing Nginx configuration is invalid; fix it before deploying.'
    /usr/sbin/nginx -T 2>/dev/null | python3 "$HELPER" conflicts --state "$STATE" --domain "$DOMAIN"
}

validate_input() {
    [[ -f "$HELPER" ]] || fatal 'Run from the complete project checkout, including deploy/config.py.'
    local -a args=(validate --state "$STATE" --domain "$DOMAIN")
    if [[ -z "$ENV_FILE" && ( -e "$APP/.env" || -L "$APP/.env" ) ]]; then ENV_FILE="$APP/.env"; fi
    if [[ -n "$ENV_FILE" ]]; then
        [[ -f "$ENV_FILE" && ! -L "$ENV_FILE" ]] || fatal 'The production environment file must be a regular file.'
        local mode group
        mode=$(stat -c '%a' "$ENV_FILE"); group=$(stat -c '%G' "$ENV_FILE")
        [[ $(stat -c '%u' "$ENV_FILE") == 0 && ( "$mode" == 600 || ( "$mode" == 640 && "$group" == "$USER_NAME" ) ) ]] || fatal 'Protect .env first: sudo chown root .env && sudo chmod 600 .env (deployment permits its own app group to read it).'
        args+=(--input "$ENV_FILE")
    fi
    python3 "$HELPER" "${args[@]}"
}

prepare_environment() {
    ENV_FILE=${ENV_FILE:-$APP/.env}
    [[ ! -L "$ENV_FILE" && ( ! -e "$ENV_FILE" || -f "$ENV_FILE" ) ]] || fatal 'Environment file must be a regular file, not a symlink.'
    if [[ -e "$ENV_FILE" ]]; then
        [[ $(stat -c '%h' "$ENV_FILE") == 1 ]] || fatal 'Environment file must not have hard links.'
    elif [[ "$ENV_FILE" != "$APP/.env" ]]; then
        fatal 'The supplied --env-file does not exist.'
    else
        install -d -m 755 "$APP"
    fi
    # Keep an existing app's read access while correcting unsafe permissions.
    local group=root mode=600
    if getent group "$USER_NAME" >/dev/null; then group=$USER_NAME; mode=640; fi
    if [[ -f "$ENV_FILE" ]]; then chown "root:$group" "$ENV_FILE"; chmod "$mode" "$ENV_FILE"; fi
    if [[ "$ENV_FILE" == "$APP/.env" ]]; then
        python3 "$HELPER" bootstrap --state "$STATE" --input "$ENV_FILE" --template "$SOURCE/.env.example" --domain "$DOMAIN"
        chown "root:$group" "$ENV_FILE"; chmod "$mode" "$ENV_FILE"
    fi
    validate_input
}

preflight() {
    check_os
    local directory
    for directory in "$CONFIG" "$BASE" "$DATA" "$RUNTIME" "$APP" "$APP/storage" "$BASE/shared" "$BASE/backups" "$DATA/postgres" "$DATA/redis" "$DATA/meili"; do
        [[ ! -L "$directory" ]] || fatal 'Application directories must not be symlinks.'
    done
    [[ ! -L "$STATE" ]] || fatal 'Deployment state must not be a symlink.'
    if [[ -f "$STATE" ]]; then
        [[ $(stat -c '%u' "$STATE") == 0 && $(stat -c '%a' "$STATE") == 600 ]] || fatal 'Deployment state must be root-owned and chmod 600.'
    elif [[ -d "$DATA" && -n $(find "$DATA" -mindepth 1 -print -quit) ]]; then
        fatal 'Application data exists without deployment state. Restore its protected credentials/state before deploying.'
    elif [[ -d "$APP" && "$SOURCE" != "$APP" && -n $(find "$APP" -mindepth 1 -maxdepth 1 ! -name storage -print -quit) ]]; then
        fatal 'The target directory already exists. Run deploy.sh from that checkout or restore its deployment state.'
    fi
    [[ ! -e "$CONFIG/in-progress" && ! -e "$BASE/current" && ! -L "$BASE/current" ]] || fatal 'This VPS has an older release-based deployment; migrate it manually before using this simpler script.'
    if [[ -z "$DOMAIN" ]]; then DOMAIN=$(state_get domain); fi
    DOMAIN=${DOMAIN:-secondhand.webostics.com}
    valid_domain "$DOMAIN" || fatal 'Invalid saved domain.'
    check_public_ports
    check_saved_ports
    check_nginx_domain
    [[ -f "$SOURCE/artisan" && -f "$SOURCE/composer.lock" && -f "$SOURCE/package-lock.json" ]] || fatal 'The project source and lock files are required.'
    [[ "$SOURCE" != "$APP"/* ]] || fatal 'Run from the project root, not a nested directory inside the live application.'
    if $CHECK; then
        validate_input
        inspect_requirements
        log 'Read-only check complete. No packages, files, services or certificates changed.'
    fi
}

inspect_requirements() {
    local binary path
    for binary in php8.4 php-fpm8.4 node composer nginx certbot redis-server; do
        path=$(command -v "$binary" || true)
        if [[ -z "$path" && "$binary" == node && -x "$RUNTIME/bin/node" ]]; then path="$RUNTIME/bin/node"; fi
        if [[ -z "$path" && "$binary" == composer && -f "$RUNTIME/composer.phar" && -x "$PHP" ]]; then
            log "Composer: $("$PHP" "$RUNTIME/composer.phar" --version --no-ansi --no-plugins --no-scripts 2>/dev/null | head -n 1)"
        elif [[ -n "$path" ]]; then
            case "$binary" in
                php-fpm8.4) log "$binary: $("$path" -v 2>/dev/null | head -n 1)" ;;
                nginx) log "$binary: $("$path" -v 2>&1)" ;;
                composer) log "$binary: $("$path" --version --no-ansi --no-plugins --no-scripts 2>/dev/null | head -n 1)" ;;
                *) log "$binary: $("$path" --version 2>/dev/null | head -n 1)" ;;
            esac
        else log "$binary: missing (will be installed or located)."; fi
    done
    if [[ -x "$PHP" ]]; then
        # shellcheck disable=SC2016
        "$PHP" -r 'foreach (["ctype","curl","dom","fileinfo","filter","hash","mbstring","openssl","pcre","PDO","pdo_pgsql","session","tokenizer","xml","redis","gd","exif","intl","zip","bcmath","pcntl","posix","Zend OPcache"] as $e) if (!extension_loaded($e)) echo "[secondhand] Missing PHP extension: $e\n"; if (extension_loaded("gd")) { $gd=gd_info(); foreach (["JPEG Support","PNG Support","WebP Support"] as $c) if (empty($gd[$c])) echo "[secondhand] Missing GD capability: $c\n"; }'
    fi
    if locate_postgres; then log "$("$PG_BIN/postgres" --version)"; else log 'PostgreSQL 16+: missing.'; fi
    if command -v meilisearch >/dev/null && meilisearch --version | grep -q "meilisearch $MEILI_VERSION$"; then
        if [[ ! -e "$RUNTIME/meilisearch" ]]; then ln -s "$(readlink -f "$(command -v meilisearch)")" "$RUNTIME/meilisearch"; fi
    fi
    if [[ -x "$RUNTIME/meilisearch" ]]; then log "$("$RUNTIME/meilisearch" --version)"; else log 'Dedicated Meilisearch: missing.'; fi
    if [[ -f "$STATE" ]]; then
        log "Saved ports: PostgreSQL=$(state_get ports.postgres) Redis=$(state_get ports.redis) Meilisearch=$(state_get ports.meili) SSR=$(state_get ports.ssr)."
    fi
}

run_quiet() {
    local label=$1 status=0 started=$SECONDS
    shift
    message "$C_BLUE" RUN "$label"
    printf '\n[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$label" >> "$DEPLOY_LOG"
    start_activity "$label"
    "$@" > "$WORK/command.log" 2>&1 || status=$?
    stop_activity
    local -a args=(redact --state "$STATE")
    [[ -z "$ENV_FILE" ]] || args+=(--input "$ENV_FILE")
    python3 "$HELPER" "${args[@]}" < "$WORK/command.log" >> "$DEPLOY_LOG"
    : > "$WORK/command.log"
    [[ "$status" == 0 ]] || fatal "$label failed after $(duration "$((SECONDS - started))"). Inspect $DEPLOY_LOG (redacted, root-only)."
    done_message "$label ($(duration "$((SECONDS - started))"))."
}

restore_package_guards() {
    if [[ -n "$PG_CONFIG_BACKUP" && -f "$PG_CONFIG_BACKUP" ]]; then
        cp -- "$PG_CONFIG_BACKUP" /etc/postgresql-common/createcluster.conf || return 1
        rm -f -- "$PG_CONFIG_BACKUP" || return 1
        PG_CONFIG_BACKUP=
    fi
    if $POLICY_CREATED; then
        rm -f -- /usr/sbin/policy-rc.d || return 1
        rm -f -- "$WORK/policy.created" || return 1
        POLICY_CREATED=false
    fi
    if [[ -f "$WORK/php-default" ]]; then
        local previous_php
        previous_php=$(cat "$WORK/php-default")
        if [[ -x "$previous_php" && $(readlink -f /usr/bin/php) != "$previous_php" ]]; then
            update-alternatives --set php "$previous_php" >/dev/null 2>&1 || return 1
        fi
    fi
}

apt_missing() {
    local package
    local -a missing=()
    for package in "$@"; do
        [[ $(dpkg-query -W -f='${db:Status-Status}' "$package" 2>/dev/null || true) == installed ]] || missing+=("$package")
    done
    if ((${#missing[@]} == 0)); then
        skip_message "Requested system packages already installed ($# checked)."
        return 0
    fi
    # Do not let package hooks restart unrelated sites. Preserve an existing policy.
    if [[ ! -e /usr/sbin/policy-rc.d ]]; then
        printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d
        chmod 755 /usr/sbin/policy-rc.d
        POLICY_CREATED=true
        touch "$WORK/policy.created"
    fi
    run_quiet 'Refresh signed package indexes' apt-get update
    run_quiet 'Install missing packages' env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l apt-get install -y --no-install-recommends --no-upgrade "${missing[@]}"
    restore_package_guards
}

locate_postgres() {
    local directory wanted=''
    [[ ! -f "$DATA/postgres/PG_VERSION" ]] || wanted=$(cat "$DATA/postgres/PG_VERSION")
    for directory in $(printf '%s\n' /usr/lib/postgresql/*/bin | sort -Vr); do
        [[ -x "$directory/postgres" && -x "$directory/pg_dump" ]] || continue
        local version=${directory%/bin}; version=${version##*/}
        [[ "$version" =~ ^[0-9]+$ && "$version" -ge 16 ]] || continue
        [[ -z "$wanted" || "$version" == "$wanted" ]] || continue
        PG_BIN=$directory; return 0
    done
    [[ -z "$wanted" ]] || fatal 'The PostgreSQL version for existing data is missing; reinstall that version, never reinitialize it.'
    return 1
}

setup_dependencies() {
    local old_php=
    local new_redis=false
    if [[ $(dpkg-query -W -f='${db:Status-Status}' redis-server 2>/dev/null || true) != installed ]] && ! systemctl cat redis-server.service >/dev/null 2>&1; then new_redis=true; fi
    if [[ -x /usr/bin/php ]]; then
        old_php=$(readlink -f /usr/bin/php)
        printf '%s\n' "$old_php" > "$WORK/php-default"
    fi
    apt_missing ca-certificates curl gnupg openssl rsync unzip xz-utils nginx redis-server
    # A newly installed distro default is ours to disable; existing Redis is untouched.
    if $new_redis; then
        systemctl disable --now redis-server.service >/dev/null
        touch "$CONFIG/redis-default-disabled"
    fi
    if ! apt-cache show php8.4-cli >/dev/null 2>&1; then
        apt_missing software-properties-common
        run_quiet 'Add signed PHP 8.4 package repository' add-apt-repository -y -n ppa:ondrej/php
    fi
    apt_missing php8.4-cli php8.4-fpm php8.4-common php8.4-pgsql php8.4-redis php8.4-gd php8.4-intl php8.4-zip php8.4-mbstring php8.4-xml php8.4-curl php8.4-bcmath php8.4-opcache
    if [[ -n "$old_php" && -x "$old_php" && $(readlink -f /usr/bin/php) != "$old_php" ]]; then
        run_quiet 'Preserve the previous global PHP executable' update-alternatives --set php "$old_php"
    fi
    # PHP variables must not be expanded by Bash.
    # shellcheck disable=SC2016
    run_quiet 'Check PHP extensions and image encoders' "$PHP" -r '
        if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) exit(1);
        foreach (["ctype","curl","dom","fileinfo","filter","hash","mbstring","openssl","pcre","PDO","pdo_pgsql","session","tokenizer","xml","redis","gd","exif","intl","zip","bcmath","pcntl","posix","Zend OPcache"] as $extension) if (!extension_loaded($extension)) exit(1);
        $gd=gd_info(); if (!$gd["JPEG Support"] || !$gd["PNG Support"] || !$gd["WebP Support"]) exit(1);
        $image=imagecreatetruecolor(2,2); ob_start(); imagewebp($image); $bytes=ob_get_clean(); if (!$bytes || !imagecreatefromstring($bytes)) exit(1);'
    # Reuse PostgreSQL 16+ when already installed; otherwise use Ubuntu's packages.
    if ! locate_postgres; then
        apt_missing postgresql-common
        PG_CONFIG_BACKUP="$WORK/createcluster.conf"
        cp /etc/postgresql-common/createcluster.conf "$PG_CONFIG_BACKUP"
        printf '\ncreate_main_cluster = false\n' >> /etc/postgresql-common/createcluster.conf
        apt_missing postgresql postgresql-client
        restore_package_guards
        locate_postgres || fatal 'PostgreSQL 16 or newer was not installed correctly.'
    else
        skip_message 'Compatible PostgreSQL installation found; keeping it.'
    fi
    if command -v certbot >/dev/null; then skip_message 'Certbot is already installed.';
    else apt_missing certbot; fi
    setup_node_composer
    setup_meilisearch
}

setup_node_composer() {
    local arch tarball hash
    if command -v node >/dev/null && [[ $(node -p 'process.versions.node.split(".")[0]') == 24 ]]; then
        NODE=$(readlink -f "$(command -v node)")
        skip_message 'Compatible Node 24 installation found; keeping it.'
    elif [[ -x "$RUNTIME/node/bin/node" && $("$RUNTIME/node/bin/node" -p 'process.versions.node.split(".")[0]') == 24 ]]; then
        NODE="$RUNTIME/node/bin/node"
        skip_message 'Reusing the app-specific Node 24 runtime.'
    else
        arch=x64; [[ $(uname -m) != aarch64 ]] || arch=arm64
        run_quiet 'Download official Node 24 checksum manifest' curl --fail --silent --show-error --proto '=https' --tlsv1.2 https://nodejs.org/dist/latest-v24.x/SHASUMS256.txt -o "$WORK/node-checksums"
        tarball=$(awk -v suffix="linux-$arch.tar.xz" '$2 ~ suffix "$" {print $2}' "$WORK/node-checksums")
        [[ "$tarball" =~ ^node-v24\.[0-9]+\.[0-9]+-linux-(x64|arm64)\.tar\.xz$ ]] || fatal 'Unexpected Node artifact manifest.'
        hash=$(awk -v name="$tarball" '$2 == name {print $1}' "$WORK/node-checksums")
        [[ "$hash" =~ ^[a-f0-9]{64}$ ]] || fatal 'Invalid Node checksum.'
        run_quiet 'Download Node runtime' curl --fail --silent --show-error --proto '=https' --tlsv1.2 "https://nodejs.org/dist/latest-v24.x/$tarball" -o "$WORK/node.tar.xz"
        printf '%s  %s\n' "$hash" "$WORK/node.tar.xz" | sha256sum --check --status || fatal 'Node checksum verification failed.'
        install -d -m 755 "$RUNTIME/node"
        tar -xJf "$WORK/node.tar.xz" --strip-components=1 -C "$RUNTIME/node"
        NODE="$RUNTIME/node/bin/node"
    fi
    if command -v composer >/dev/null && composer --version --no-ansi 2>/dev/null | grep -q '^Composer version 2\.'; then
        COMPOSER=$(readlink -f "$(command -v composer)")
        skip_message 'Compatible Composer 2 installation found; keeping it.'
    elif [[ -f "$RUNTIME/composer.phar" ]] && "$PHP" "$RUNTIME/composer.phar" --version --no-ansi | grep -q '^Composer version 2\.'; then
        COMPOSER="$RUNTIME/composer.phar"
        skip_message 'Reusing the app-specific Composer 2 runtime.'
    else
        run_quiet 'Download verified Composer installer checksum' curl --fail --silent --show-error --proto '=https' --tlsv1.2 https://composer.github.io/installer.sig -o "$WORK/composer.sig"
        run_quiet 'Download Composer installer' curl --fail --silent --show-error --proto '=https' --tlsv1.2 https://getcomposer.org/installer -o "$WORK/composer-setup.php"
        [[ $(sha384sum "$WORK/composer-setup.php" | cut -d ' ' -f 1) == $(cat "$WORK/composer.sig") ]] || fatal 'Composer installer checksum failed.'
        run_quiet 'Install app-specific Composer 2' "$PHP" "$WORK/composer-setup.php" --2 --install-dir="$RUNTIME" --filename=composer.phar
        COMPOSER="$RUNTIME/composer.phar"
    fi
    install -d -m 755 "$RUNTIME/bin"
    ln -sfn -- "$NODE" "$RUNTIME/bin/node"
    ln -sfn -- "$(dirname "$NODE")/npm" "$RUNTIME/bin/npm"
    ln -sfn -- "$PHP" "$RUNTIME/bin/php"
    ln -sfn -- "$COMPOSER" "$RUNTIME/bin/composer"
    [[ -x "$RUNTIME/bin/npm" ]] || fatal 'Node 24 is present but npm is unavailable.'
}

setup_meilisearch() {
    local asset arch url digest
    if [[ -x "$RUNTIME/meilisearch" ]] && "$RUNTIME/meilisearch" --version | grep -q "meilisearch $MEILI_VERSION"; then
        skip_message "Meilisearch $MEILI_VERSION is already installed."
        return 0
    fi
    [[ ! -e "$RUNTIME/meilisearch" ]] || fatal 'The app Meilisearch version differs; perform a documented search-engine upgrade separately.'
    arch=amd64; [[ $(uname -m) != aarch64 ]] || arch=aarch64
    asset="meilisearch-linux-$arch"
    run_quiet 'Read official Meilisearch release metadata' curl --fail --silent --show-error --proto '=https' --tlsv1.2 "https://api.github.com/repos/meilisearch/meilisearch/releases/tags/v$MEILI_VERSION" -o "$WORK/meili-release.json"
    mapfile -t metadata < <(python3 - "$WORK/meili-release.json" "$asset" <<'PY'
import json, sys
assets = [a for a in json.load(open(sys.argv[1]))['assets'] if a['name'] == sys.argv[2]]
if len(assets) != 1: sys.exit(1)
print(assets[0]['browser_download_url'])
print(assets[0].get('digest') or '')
PY
    )
    url=${metadata[0]:-}; digest=${metadata[1]:-}
    [[ "$url" == "https://github.com/meilisearch/meilisearch/releases/download/v$MEILI_VERSION/$asset" && "$digest" =~ ^sha256:[a-f0-9]{64}$ ]] || fatal 'Meilisearch release lacks a verifiable SHA-256 asset digest; installation stopped.'
    run_quiet 'Download pinned Meilisearch binary' curl -L --fail --silent --show-error --proto '=https' --proto-redir '=https' --tlsv1.2 "$url" -o "$WORK/meilisearch"
    printf '%s  %s\n' "${digest#sha256:}" "$WORK/meilisearch" | sha256sum --check --status || fatal 'Meilisearch checksum failed.'
    install -m 755 "$WORK/meilisearch" "$RUNTIME/meilisearch"
    "$RUNTIME/meilisearch" --version | grep -q "meilisearch $MEILI_VERSION" || fatal 'Unexpected Meilisearch version.'
}

select_port() {
    local preferred=$1 reserved=$2 candidate matches file conflict
    for candidate in "$preferred" $(seq 20000 29999); do
        [[ " $reserved " != *" $candidate "* ]] || continue
        [[ -z $(ss -H -ltn "sport = :$candidate") ]] || continue
        # Do not take a configured port from another currently stopped systemd service.
        matches=$(grep -RIlE "(port|PORT|--port|http-addr)[ =:]+$candidate([^0-9]|$)|:$candidate([^0-9]|$)" /etc/systemd/system /etc/redis /etc/postgresql /etc/meilisearch* 2>/dev/null || true)
        conflict=false
        while IFS= read -r file; do
            [[ -n "$file" ]] || continue
            if [[ "$file" == /etc/redis/redis.conf && -f "$CONFIG/redis-default-disabled" ]] && ! systemctl is-enabled --quiet redis-server.service; then continue; fi
            conflict=true
        done <<< "$matches"
        $conflict && continue
        printf '%s\n' "$candidate"; return 0
    done
    fatal 'No available private service port was found.'
}

prepare_state() {
    local -a args=(init --state "$STATE" --output "$WORK/candidate-state.json")
    [[ -z "$ENV_FILE" ]] || args+=(--input "$ENV_FILE")
    [[ -z "$CONTACT_EMAIL" ]] || args+=(--email "$CONTACT_EMAIL")
    if [[ ! -f "$STATE" ]]; then
        local pg redis meili ssr
        pg=$(select_port 5432 '')
        redis=$(select_port 6379 "$pg")
        meili=$(select_port 7700 "$pg $redis")
        ssr=$(select_port 13714 "$pg $redis $meili")
        args+=(--ports "$pg" "$redis" "$meili" "$ssr")
    fi
    python3 "$HELPER" "${args[@]}"
    if [[ ! -f "$STATE" ]]; then
        install -m 600 "$WORK/candidate-state.json" "$STATE"
    fi
    PG_PORT=$(state_get ports.postgres); REDIS_PORT=$(state_get ports.redis)
    MEILI_PORT=$(state_get ports.meili); SSR_PORT=$(state_get ports.ssr)
    DB_PASSWORD=$(state_get db_password); REDIS_PASSWORD=$(state_get redis_password); MEILI_KEY=$(state_get meili_key)
    if [[ -z "$CONTACT_EMAIL" ]]; then CONTACT_EMAIL=$(python3 "$HELPER" get --state "$WORK/candidate-state.json" --key settings.CERTBOT_EMAIL); fi
}

write_unit() {
    local name=$1 user=$2 executable=$3 directory=$4 extra=${5:-}
    local dependencies=''
    if [[ "$name" == horizon ]]; then dependencies='secondhand-postgres.service secondhand-redis.service secondhand-meili.service'; fi
    local target="/etc/systemd/system/secondhand-$name.service"
    if [[ -e "$target" ]]; then grep -q '^# Managed by secondhand deploy.sh$' "$target" || fatal "Existing unmanaged unit: $target"; fi
    cat > "$target" <<EOF
# Managed by secondhand deploy.sh
[Unit]
Description=Second Hand $name
After=network-online.target $dependencies
Wants=network-online.target
Requires=$dependencies
[Service]
Type=simple
User=$user
Group=$user
WorkingDirectory=$directory
ExecStart=$executable
Restart=on-failure
RestartSec=5
TimeoutStopSec=95
UMask=0027
NoNewPrivileges=true
ProtectSystem=full
ProtectHome=true
PrivateTmp=true
$extra
[Install]
WantedBy=multi-user.target
EOF
}

wait_ready() {
    local label=$1 started=$SECONDS
    shift
    message "$C_BLUE" RUN "Wait for $label to become ready"
    start_activity "Waiting for $label"
    local attempt
    for ((attempt=1; attempt<=30; attempt++)); do
        if "$@" >/dev/null 2>&1; then
            stop_activity
            done_message "$label is ready ($(duration "$((SECONDS - started))"))."
            return 0
        fi
        sleep 1
    done
    fatal "$label did not become ready. Inspect its systemd journal."
}

start_unit() {
    systemctl daemon-reload
    run_quiet "Enable/start $1 service" systemctl enable --now "secondhand-$1.service"
}

setup_data_services() {
    install -d -o postgres -g postgres -m 700 "$DATA/postgres"
    if [[ ! -f "$DATA/postgres/PG_VERSION" ]]; then
        run_quiet 'Initialize application database' runuser -u postgres -- "$PG_BIN/initdb" -D "$DATA/postgres" --auth-local=peer --auth-host=scram-sha-256
        cat >> "$DATA/postgres/postgresql.conf" <<EOF
listen_addresses = '127.0.0.1'
port = $PG_PORT
unix_socket_directories = '/run/secondhand-postgres'
shared_buffers = '128MB'
max_connections = 50
EOF
    fi
    [[ $(cat "$DATA/postgres/PG_VERSION") == "$(basename "${PG_BIN%/bin}")" ]] || fatal 'Existing PostgreSQL data requires a separate major-version migration.'
    write_unit postgres postgres "$PG_BIN/postgres -D $DATA/postgres" "$DATA/postgres" $'RuntimeDirectory=secondhand-postgres\nRuntimeDirectoryMode=0750'
    start_unit postgres
    wait_ready PostgreSQL "$PG_BIN/pg_isready" -h /run/secondhand-postgres -p "$PG_PORT"
    # Password is random hex; never place it in argv or print SQL to the terminal.
    run_quiet 'Ensure dedicated PostgreSQL role/database' runuser -u postgres -- "$PG_BIN/psql" -h /run/secondhand-postgres -p "$PG_PORT" -v ON_ERROR_STOP=1 postgres <<EOF
SELECT 'CREATE ROLE secondhand LOGIN PASSWORD ''$DB_PASSWORD'' NOSUPERUSER NOCREATEDB NOCREATEROLE'
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'secondhand')\gexec
SELECT 'CREATE DATABASE secondhand OWNER secondhand'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'secondhand')\gexec
EOF
    install -d -o "$USER_NAME" -g "$USER_NAME" -m 700 "$DATA/redis" "$DATA/meili"
    cat > "$CONFIG/redis.conf" <<EOF
bind 127.0.0.1
protected-mode yes
port $REDIS_PORT
requirepass $REDIS_PASSWORD
dir $DATA/redis
appendonly yes
daemonize no
logfile ""
EOF
    chmod 640 "$CONFIG/redis.conf"; chown root:"$USER_NAME" "$CONFIG/redis.conf"
    write_unit redis "$USER_NAME" "/usr/bin/redis-server $CONFIG/redis.conf" "$DATA/redis"
    start_unit redis
    REDISCLI_AUTH="$REDIS_PASSWORD" wait_ready Redis /usr/bin/redis-cli -h 127.0.0.1 -p "$REDIS_PORT" ping
    printf 'MEILI_MASTER_KEY=%s\n' "$MEILI_KEY" > "$CONFIG/meili.env"
    chmod 600 "$CONFIG/meili.env"
    write_unit meili "$USER_NAME" "$RUNTIME/meilisearch --env production --http-addr 127.0.0.1:$MEILI_PORT --db-path $DATA/meili --no-analytics" "$DATA/meili" "EnvironmentFile=$CONFIG/meili.env"
    start_unit meili
    wait_ready Meilisearch curl --fail --silent "http://127.0.0.1:$MEILI_PORT/health"
}

app_run() {
    local directory=$1
    shift
    # Expanded by the unprivileged child Bash.
    # shellcheck disable=SC2016
    runuser -u "$USER_NAME" -- env -i HOME="$BASE/shared/home" PATH="$RUNTIME/bin:/usr/bin:/bin" COMPOSER_HOME="$BASE/shared/composer" COMPOSER_CACHE_DIR="$BASE/shared/composer/cache" NODE_ENV=production /bin/bash -c 'cd -- "$1"; shift; exec "$@"' bash "$directory" "$@"
}

artisan() { app_run "$1" "$PHP" artisan "${@:2}"; }

build_app() {
    STAGING=$(mktemp -d "$BASE/.build.XXXXXXXX")
    chown "$USER_NAME:$USER_NAME" "$STAGING"; chmod 755 "$STAGING"
    run_quiet 'Prepare application build' rsync -a --safe-links --chown="$USER_NAME:$USER_NAME" \
        --exclude='/.git' --include='/.env.example' --exclude='/.env*' --exclude='*.env' \
        --exclude='/.tools' --exclude='/.starter' --exclude='/.agents' --exclude='/dumps' --exclude='/backups' \
        --exclude='/deploy/state.json' --exclude='/deploy/success' \
        --exclude='/vendor' --exclude='/node_modules' --exclude='/storage' --exclude='/bootstrap/cache/*.php' \
        --exclude='/bootstrap/ssr' --exclude='/public/build' --exclude='/public/hot' --exclude='/public/storage' \
        "$SOURCE/" "$STAGING/"
    ln -s "$APP/storage" "$STAGING/storage"
    python3 "$HELPER" env --state "$WORK/candidate-state.json" --domain "$DOMAIN" --output "$STAGING/.env"
    chown root:"$USER_NAME" "$STAGING/.env"
    install -d -o "$USER_NAME" -g "$USER_NAME" -m 750 "$STAGING/bootstrap/cache"
    run_quiet 'Install locked PHP dependencies' app_run "$STAGING" "$PHP" "$COMPOSER" install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    run_quiet 'Check PHP requirements' app_run "$STAGING" "$PHP" "$COMPOSER" check-platform-reqs --no-dev
    run_quiet 'Install locked frontend dependencies' app_run "$STAGING" "$RUNTIME/bin/npm" ci --include=dev --no-audit --no-fund
    run_quiet 'Build website and SSR' app_run "$STAGING" env INERTIA_SSR_HOST=127.0.0.1 INERTIA_SSR_PORT="$SSR_PORT" "$RUNTIME/bin/npm" run build:ssr
    run_quiet 'Check data, private storage and mail settings' artisan "$STAGING" deploy:health --storage --skip-ssr
}

snapshot_configs() {
    local file key
    install -d -m 700 "$WORK/snapshot"
    for file in "$NGINX_SITE" "$NGINX_ACME" "$FPM_POOL" /etc/systemd/system/secondhand-ssr.service /etc/systemd/system/secondhand-horizon.service /etc/systemd/system/secondhand-scheduler.service /etc/systemd/system/secondhand-scheduler.timer; do
        key=${file//\//_}
        [[ ! -e "$file" ]] || cp -a "$file" "$WORK/snapshot/$key"
    done
    SNAPSHOT_READY=true
}

nginx_http() {
    cat <<EOF
# Managed by secondhand deploy.sh
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    location ^~ /.well-known/acme-challenge/ { root $BASE/acme; }
    location / { return 503; }
}
EOF
}

ensure_certificate() {
    local lineage certificate existing=''
    CERT_LINEAGE=''
    # Reuse any Certbot-managed certificate that covers the requested hostname.
    for lineage in /etc/letsencrypt/live/*; do
        [[ -f "$lineage/fullchain.pem" && -f "$lineage/privkey.pem" ]] || continue
        certificate="$lineage/fullchain.pem"
        if openssl verify -no_check_time -verify_hostname "$DOMAIN" -CApath /etc/ssl/certs -untrusted "$certificate" "$lineage/cert.pem" >/dev/null 2>&1; then
            # Renew only our lineage. Do not invoke another site's saved hooks,
            # standalone authenticator or installer for a borrowed certificate.
            if [[ "${lineage##*/}" == "secondhand-$DOMAIN" ]]; then existing=$lineage; fi
            if openssl x509 -in "$certificate" -noout -checkend 2592000 >/dev/null 2>&1; then
                CERT_LINEAGE=$lineage
                skip_message 'Valid HTTPS certificate found; more than 30 days remain.'
                break
            fi
        fi
    done
    if [[ -z "$CERT_LINEAGE" && -n "$existing" ]]; then
        run_quiet 'Renew existing certificate without changing its covered domains' certbot renew --non-interactive --cert-name "${existing##*/}"
        CERT_LINEAGE=$existing
    fi
    if [[ -z "$CERT_LINEAGE" ]]; then
        local -a args=(certonly --non-interactive --agree-tos --webroot -w "$BASE/acme" --cert-name "secondhand-$DOMAIN" -d "$DOMAIN" --keep-until-expiring)
        if [[ -n "$CONTACT_EMAIL" ]]; then args+=(--email "$CONTACT_EMAIL"); else args+=(--register-unsafely-without-email); fi
        run_quiet 'Issue/renew HTTPS certificate' certbot "${args[@]}"
        CERT_LINEAGE="/etc/letsencrypt/live/secondhand-$DOMAIN"
    fi
    [[ -f "$CERT_LINEAGE/privkey.pem" ]] || fatal 'Certificate private key unavailable.'
    openssl verify -verify_hostname "$DOMAIN" -CApath /etc/ssl/certs -untrusted "$CERT_LINEAGE/fullchain.pem" "$CERT_LINEAGE/cert.pem" >/dev/null 2>&1 || fatal 'Certificate chain/hostname validation failed.'
    openssl x509 -in "$CERT_LINEAGE/fullchain.pem" -noout -checkend 86400 >/dev/null 2>&1 || fatal 'Certificate is expired or expires within one day.'
    install -d -m 755 /etc/letsencrypt/renewal-hooks/deploy
    local hook=/etc/letsencrypt/renewal-hooks/deploy/secondhand-nginx
    if [[ -e "$hook" ]]; then grep -q '^# Managed by secondhand deploy.sh$' "$hook" || fatal 'Unmanaged certificate hook occupies the reserved path.'; fi
    printf '#!/bin/sh\n# Managed by secondhand deploy.sh\n/usr/sbin/nginx -t && /bin/systemctl reload nginx\n' > "$hook"
    chmod 755 "$hook"
    if systemctl list-unit-files certbot.timer --no-legend | grep -q '^certbot.timer'; then
        run_quiet 'Enable automatic HTTPS renewal' systemctl enable --now certbot.timer
    elif systemctl list-unit-files snap.certbot.renew.timer --no-legend | grep -q '^snap.certbot.renew.timer'; then
        run_quiet 'Enable automatic HTTPS renewal' systemctl enable --now snap.certbot.renew.timer
    else
        fatal 'Certbot is installed but no automatic renewal timer is available.'
    fi
}

nginx_https() {
    cat <<EOF
# Managed by secondhand deploy.sh
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    location ^~ /.well-known/acme-challenge/ { root $BASE/acme; }
    location / { return 301 https://$DOMAIN\$request_uri; }
}
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name $DOMAIN;
    ssl_certificate $CERT_LINEAGE/fullchain.pem;
    ssl_certificate_key $CERT_LINEAGE/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    root $APP/public;
    index index.php;
    charset utf-8;
    client_max_body_size 128m;
    access_log /var/log/nginx/secondhand-access.log;
    error_log /var/log/nginx/secondhand-error.log;
    location ^~ /.well-known/acme-challenge/ { root $BASE/acme; }
    location ~ /\. { deny all; }
    location ~* ^/(?:vendor|storage|bootstrap|config|database|resources|tests|deploy)(?:/|$) { deny all; }
    location ~* \.(?:env|log|sql|bak|sh|py|ya?ml|neon)$ { deny all; }
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/secondhand.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root/index.php;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_param HTTPS on;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 90s;
    }
    location ~ \.php$ { return 404; }
    location ~* ^/build/.*\.(?:js|css|woff2?|png|jpe?g|webp|svg|ico)$ {
        try_files \$uri =404;
        add_header Cache-Control "public, max-age=31536000, immutable";
        add_header X-Content-Type-Options nosniff;
    }
}
EOF
}

php_fpm_pool() {
    local nginx_user=$1
    cat <<EOF
; Managed by secondhand deploy.sh
[secondhand]
user = $USER_NAME
group = $USER_NAME
listen = /run/php/secondhand.sock
listen.owner = $nginx_user
listen.group = $(id -gn "$nginx_user")
listen.mode = 0660
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 10s
pm.max_requests = 500
clear_env = yes
catch_workers_output = yes
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 128M
php_admin_value[post_max_size] = 128M
php_admin_flag[expose_php] = off
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
EOF
}

prepare_web() {
    local nginx_user
    nginx_user=$(/usr/sbin/nginx -T 2>/dev/null | awk '$1 == "user" && !seen { gsub(";", "", $2); print $2; seen=1 }')
    nginx_user=${nginx_user:-www-data}
    [[ "$nginx_user" =~ ^[a-z_][a-z0-9_-]*\$?$ ]] || fatal 'Cannot identify the existing Nginx worker account.'
    id "$nginx_user" >/dev/null 2>&1 || fatal 'Cannot identify the existing Nginx worker account.'
    # For same-domain deployments, the existing HTTP challenge route already works.
    if [[ "$DOMAIN" != $(state_get domain) || ! -f "$NGINX_SITE" ]]; then
        nginx_http > "$NGINX_ACME"
        run_quiet 'Validate HTTP challenge virtual host' /usr/sbin/nginx -t
        if systemctl is-active --quiet nginx; then systemctl reload nginx; else systemctl enable --now nginx >/dev/null; fi
    fi
    ensure_certificate
    php_fpm_pool "$nginx_user" > "$FPM_POOL"
    run_quiet 'Validate PHP-FPM pool' /usr/sbin/php-fpm8.4 -t
}

write_app_units() {
    local extra="Environment=PATH=$RUNTIME/bin:/usr/bin:/bin"
    write_unit ssr "$USER_NAME" "$NODE $APP/bootstrap/ssr/app.js" "$APP" "$extra"
    write_unit horizon "$USER_NAME" "$PHP -d memory_limit=512M $APP/artisan horizon" "$APP" "$extra"
    # schedule:run is a oneshot; systemd ensures a single invocation and persistent timer.
    cat > /etc/systemd/system/secondhand-scheduler.service <<EOF
# Managed by secondhand deploy.sh
[Unit]
Description=Second Hand scheduler
After=secondhand-postgres.service secondhand-redis.service secondhand-meili.service
Requires=secondhand-postgres.service secondhand-redis.service secondhand-meili.service
[Service]
Type=oneshot
User=$USER_NAME
Group=$USER_NAME
WorkingDirectory=$APP
ExecStart=$PHP $APP/artisan schedule:run
Environment=PATH=$RUNTIME/bin:/usr/bin:/bin
UMask=0027
NoNewPrivileges=true
ProtectSystem=full
ProtectHome=true
EOF
    cat > /etc/systemd/system/secondhand-scheduler.timer <<'EOF'
# Managed by secondhand deploy.sh
[Unit]
Description=Run Second Hand scheduler every minute
[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
AccuracySec=1s
[Install]
WantedBy=timers.target
EOF
}

backup_database() {
    local backup
    backup="$BASE/backups/$(date -u +%Y%m%dT%H%M%SZ)-$(openssl rand -hex 4).dump"
    install -d -o postgres -g postgres -m 700 "$BASE/backups"
    run_quiet 'Back up database before migrations' runuser -u postgres -- "$PG_BIN/pg_dump" -h /run/secondhand-postgres -p "$PG_PORT" -Fc -f "$backup" secondhand
    [[ -s "$backup" ]] || fatal 'Database backup is missing or empty.'
    chmod 600 "$backup"
}

install_prepared_app() {
    # Update code in place while retaining Git history, data and local-only files.
    rsync -a --delete --chown="root:$USER_NAME" \
        --exclude='/.git' --exclude='/storage' --include='/.env.example' --exclude='/.env.*' --exclude='*.env' \
        --exclude='/.tools' --exclude='/.starter' --exclude='/.agents' --exclude='/dumps' --exclude='/backups' \
        --exclude='/deploy/state.json' --exclude='/deploy/success' \
        "$STAGING/" "$APP/"
    # rsync excludes environment files: install only the validated runtime .env.
    install -o root -g "$USER_NAME" -m 640 "$STAGING/.env" "$APP/.env"
    find "$APP" \( -path "$APP/.git" -o -path "$APP/storage" -o -path "$APP/.tools" \
        -o -path "$APP/.starter" -o -path "$APP/.agents" -o -path "$APP/dumps" -o -path "$APP/backups" \) \
        -prune -o -type f -exec chmod 640 {} + -o -type d -exec chmod 750 {} +
    chmod 755 "$APP"
    find "$APP/public" -type d -exec chmod 755 {} +
    find "$APP/public" -type f -exec chmod 644 {} +
    chmod 640 "$APP/.env"
    # Git metadata may contain authenticated remotes; keep it private to its owner.
    if [[ -d "$APP/.git" ]]; then chmod 700 "$APP/.git"; elif [[ -f "$APP/.git" ]]; then chmod 600 "$APP/.git"; fi
    chown -R "$USER_NAME:$USER_NAME" "$APP/bootstrap/cache"
}

deploy_app() {
    # Build and certificate failures occur before taking the current site offline.
    if [[ $(state_get seeded) == true && -f "$APP/artisan" ]]; then
        run_quiet 'Enter maintenance mode' artisan "$APP" down --retry=10
    else
        run_quiet 'Enter maintenance mode' artisan "$STAGING" down --retry=10
    fi
    MAINTENANCE=true
    local unit
    for unit in scheduler.timer scheduler.service horizon.service ssr.service; do
        if systemctl cat "secondhand-$unit" >/dev/null 2>&1; then systemctl stop "secondhand-$unit"; fi
    done
    backup_database
    MIGRATIONS_STARTED=true
    run_quiet 'Run production migrations' artisan "$STAGING" migrate --force
    if [[ $(state_get seeded) != true ]]; then
        run_quiet 'Seed categories, locations and attributes only' artisan "$STAGING" db:seed --force
    fi
    # One fixed application directory; no release archive or rollback machinery.
    install -d -m 755 "$APP"
    run_quiet 'Install prepared application in place' install_prepared_app
    run_quiet 'Cache production configuration' artisan "$APP" optimize
    run_quiet 'Update search settings' artisan "$APP" scout:sync-index-settings
    if [[ $(state_get seeded) != true ]]; then
        run_quiet 'Initialize search index' artisan "$APP" scout:import 'App\Models\Listing'
    fi
    write_app_units
    systemctl daemon-reload
    run_quiet 'Enable/start PHP-FPM' systemctl enable --now php8.4-fpm
    systemctl reload php8.4-fpm
    nginx_https > "$NGINX_ACME"
    if [[ "$DOMAIN" == $(state_get domain) ]]; then
        cp "$NGINX_ACME" "$NGINX_SITE"; rm -f -- "$NGINX_ACME"
    fi
    run_quiet 'Validate HTTPS configuration' /usr/sbin/nginx -t
    systemctl reload nginx
    run_quiet 'Start rendering, workers and scheduler' systemctl enable --now secondhand-ssr.service secondhand-horizon.service secondhand-scheduler.timer
    stage 7 'Verify the live website' 'Check HTTPS, data services, image storage, mail, workers and scheduler.'
    wait_ready SSR artisan "$APP" inertia:check-ssr
    wait_ready Horizon artisan "$APP" horizon:status
    run_quiet 'Check application services and private storage' artisan "$APP" deploy:health --storage
    run_quiet 'Check scheduler' systemctl start secondhand-scheduler.service
    run_quiet 'Leave maintenance mode' artisan "$APP" up
    wait_ready HTTPS curl --fail --silent --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/up"
    systemctl is-active --quiet secondhand-scheduler.timer || fatal 'Scheduler is not active.'
    # Retire only our previous hostname after the new HTTPS endpoint works.
    if [[ -f "$NGINX_ACME" ]]; then
        cp "$NGINX_ACME" "$NGINX_SITE"; rm -f -- "$NGINX_ACME"
        run_quiet 'Validate final domain configuration' /usr/sbin/nginx -t
        systemctl reload nginx
    fi
    python3 "$HELPER" commit --state "$STATE" --input "$WORK/candidate-state.json" --domain "$DOMAIN"
    SUCCESS=true; MAINTENANCE=false
}

restore_configs() {
    local file key failed=false
    for file in "$NGINX_SITE" "$NGINX_ACME" "$FPM_POOL" /etc/systemd/system/secondhand-ssr.service /etc/systemd/system/secondhand-horizon.service /etc/systemd/system/secondhand-scheduler.service /etc/systemd/system/secondhand-scheduler.timer; do
        key=${file//\//_}
        if [[ -e "$WORK/snapshot/$key" ]]; then
            cp -a "$WORK/snapshot/$key" "$file" || failed=true
        else rm -f -- "$file" || failed=true; fi
    done
    ! $failed
}

finish() {
    local status=$?
    trap - EXIT INT TERM
    set +e
    stop_activity
    restore_package_guards || { log 'Package setup cleanup failed; inspect the deployment log.'; status=1; }
    if ! $SUCCESS; then
        if $SNAPSHOT_READY; then
            restore_configs || status=1
            systemctl daemon-reload
            /usr/sbin/nginx -t >/dev/null 2>&1 && systemctl reload nginx
            if systemctl is-active --quiet php8.4-fpm; then systemctl reload php8.4-fpm; fi
        fi
        if $MAINTENANCE; then
            # Never guess whether a partially migrated schema supports the old code.
            if $MIGRATIONS_STARTED; then
                [[ ! -f "$APP/artisan" ]] || artisan "$APP" down --retry=60 >/dev/null 2>&1
                systemctl stop secondhand-horizon.service secondhand-scheduler.timer secondhand-scheduler.service secondhand-ssr.service >/dev/null 2>&1
                log 'Deployment failed after migrations began. Maintenance remains enabled; fix the error and rerun the same command. No database restoration was attempted.'
            else
                [[ ! -f "$APP/artisan" ]] || artisan "$APP" up >/dev/null 2>&1
                systemctl start secondhand-horizon.service secondhand-scheduler.timer secondhand-ssr.service >/dev/null 2>&1
            fi
        fi
        printf '\n'
        message "$C_RED" FAIL "Deployment stopped in stage $STAGE_NUMBER/$STAGE_TOTAL ($(duration "$((SECONDS - START_TIME))") elapsed)."
        log "Failure details: $DEPLOY_LOG; saved configuration: $WORK"
        printf '  Read log: sudo tail -n 60 %s\n\n' "$DEPLOY_LOG"
        [[ "$status" != 0 ]] || status=1
    elif [[ "$WORK" == "$CONFIG"/run.* && ! -L "$WORK" ]]; then
        rm -rf -- "$WORK"
    fi
    if [[ "$STAGING" == "$BASE"/.build.* && ! -L "$STAGING" ]]; then rm -rf -- "$STAGING"; fi
    exit "$status"
}

main() {
    parse_args "$@"
    init_display
    printf '\n%s  SECOND HAND  |  %s%s\n' "$C_BOLD$C_BLUE" "$(if $CHECK; then printf 'READ-ONLY CHECK'; else printf 'DEPLOYMENT'; fi)" "$C_RESET"
    printf '  Ubuntu 24.04 / Native Nginx + PHP-FPM\n'
    stage 1 'Check server and configuration' 'Validate the platform, domain, ports and protected settings.'
    preflight
    if $CHECK; then show_context; done_message 'Read-only checks finished.'; printf '\n'; return 0; fi
    [[ -d "$CONFIG" ]] || install -d -m 700 "$CONFIG"
    exec 9> "$CONFIG/deploy.lock"
    flock -n 9 || fatal 'Another Second Hand deployment is running.'
    # Recheck protected state and conflicts after acquiring the lock.
    preflight
    done_message "Preflight passed for https://$DOMAIN."
    show_context
    prepare_environment
    WORK=$(mktemp -d "$CONFIG/run.XXXXXXXX")
    trap finish EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
    touch "$DEPLOY_LOG"; chmod 600 "$DEPLOY_LOG"
    install -d -m 755 "$BASE" "$BASE/shared" "$BASE/acme" "$DATA" "$RUNTIME" "$APP"
    if ! id "$USER_NAME" >/dev/null 2>&1; then
        useradd --system --user-group --home-dir "$BASE/shared/home" --shell /usr/sbin/nologin "$USER_NAME"
    fi
    [[ $(getent passwd "$USER_NAME" | cut -d: -f6) == "$BASE/shared/home" && $(id -u "$USER_NAME") != 0 ]] || fatal 'An unrelated account uses the secondhand username.'
    install -d -o "$USER_NAME" -g "$USER_NAME" -m 700 "$BASE/shared/home" "$BASE/shared/composer"
    install -d -o "$USER_NAME" -g "$USER_NAME" -m 750 "$APP/storage" "$APP/storage/app/private" "$APP/storage/app/listing-images" "$APP/storage/framework/cache/data" "$APP/storage/framework/sessions" "$APP/storage/framework/views" "$APP/storage/logs"
    chown -R "$USER_NAME:$USER_NAME" "$APP/storage"
    chmod 750 "$CONFIG"; chown root:"$USER_NAME" "$CONFIG"
    snapshot_configs
    stage 2 'Prepare runtime dependencies' 'Reuse compatible tools and install only missing requirements.'
    setup_dependencies
    check_public_ports
    stage 3 'Prepare private data services' 'PostgreSQL, Redis and Meilisearch use the saved private ports.'
    prepare_state
    setup_data_services
    stage 4 'Build the application' 'Install locked dependencies and build the website and SSR.'
    build_app
    stage 5 'Configure Nginx and HTTPS' 'Validate the dedicated site and reuse or issue its certificate.'
    prepare_web
    stage 6 'Publish the application' 'Brief maintenance window: back up the database, migrate and update the app.'
    deploy_app
    show_summary
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then main "$@"; fi
