#!/usr/bin/env bash
# DESTRUCTIVE test fixture setup: run ONLY on a disposable Ubuntu VM.
set -Eeuo pipefail
[[ ${SECONDHAND_DISPOSABLE_VM:-} == yes && $EUID == 0 ]] || { printf 'Requires a disposable VM and SECONDHAND_DISPOSABLE_VM=yes\n' >&2; exit 1; }
[[ ! -e /etc/secondhand && ! -e /var/www/secondhand ]] || { printf 'Refusing a VM with an existing Second Hand deployment\n' >&2; exit 1; }
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
FIXTURE=/root/secondhand-deploy-fixture
PROFILE=${DEPLOYMENT_PROFILE:-shared}
[[ "$PROFILE" == basic || "$PROFILE" == shared ]] || exit 1
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx certbot python3 openssl ca-certificates rsync iproute2
mkdir -p "$FIXTURE/source"
rsync -a --exclude=.git --exclude=.tools --exclude=node_modules --exclude=vendor --exclude='.env*' "$ROOT/" "$FIXTURE/source/"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$FIXTURE/ca.key" -out "$FIXTURE/ca.crt" -days 3 -subj '/CN=Second Hand disposable VM test CA' >/dev/null 2>&1
install -m 644 "$FIXTURE/ca.crt" /usr/local/share/ca-certificates/secondhand-test.crt
update-ca-certificates >/dev/null
openssl req -newkey rsa:2048 -nodes -keyout "$FIXTURE/services.key" -out "$FIXTURE/services.csr" -subj '/CN=storage.secondhand.test.invalid' >/dev/null 2>&1
printf 'subjectAltName=DNS:storage.secondhand.test.invalid,DNS:mail.secondhand.test.invalid\n' > "$FIXTURE/extensions"
openssl x509 -req -in "$FIXTURE/services.csr" -CA "$FIXTURE/ca.crt" -CAkey "$FIXTURE/ca.key" -CAcreateserial -out "$FIXTURE/services.crt" -days 3 -extfile "$FIXTURE/extensions" >/dev/null 2>&1
printf '\n127.0.0.1 storage.secondhand.test.invalid mail.secondhand.test.invalid secondhand.test.invalid changed.secondhand.test.invalid bad.secondhand.test.invalid\n' >> /etc/hosts
cat > /etc/nginx/conf.d/deployment-fixtures.conf <<EOF
server { listen 80; server_name neighbour.test.invalid; location / { return 200 'neighbour-preserved'; } }
server {
    listen 443 ssl;
    server_name storage.secondhand.test.invalid;
    ssl_certificate $FIXTURE/services.crt;
    ssl_certificate_key $FIXTURE/services.key;
    location / { proxy_pass http://127.0.0.1:29000; }
}
EOF
nginx -t
systemctl enable --now nginx
systemctl reload nginx
install -m 755 "$ROOT/tests/Deployment/certbot-fixture.sh" /usr/local/bin/certbot
python3 "$ROOT/tests/Deployment/services.py" "$FIXTURE" > "$FIXTURE/services.log" 2>&1 &
fixture_pid=$!
trap 'kill "$fixture_pid" 2>/dev/null || true' EXIT
if [[ "$PROFILE" == shared ]]; then
    apt-get install -y -qq php8.3-cli
    update-alternatives --set php /usr/bin/php8.3
    python3 -m http.server 3000 --bind 127.0.0.1 > "$FIXTURE/port3000.log" 2>&1 &
    # Reserve typical private ports using a separate background listener process.
    python3 - > "$FIXTURE/reserved.log" 2>&1 <<'PY' &
import socket, time
sockets=[]
for port in [5432,6379,7700,13714]:
    sock=socket.socket()
    try:
        sock.bind(('127.0.0.1',port)); sock.listen(); sockets.append(sock)
    except OSError:
        sock.close()
time.sleep(1800)
PY
fi
sleep 2
old_php=''
if [[ -x /usr/bin/php ]]; then old_php=$(readlink -f /usr/bin/php); fi
old_node=$(command -v node || true)
site_hash=$(sha256sum /etc/nginx/conf.d/deployment-fixtures.conf)
cat > "$FIXTURE/production.env" <<'EOF'
APP_NAME="Second Hand native smoke"
AWS_ACCESS_KEY_ID=fixture-key
AWS_SECRET_ACCESS_KEY=fixture-secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=deployment-private
AWS_ENDPOINT=https://storage.secondhand.test.invalid
AWS_USE_PATH_STYLE_ENDPOINT=true
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=mail.secondhand.test.invalid
MAIL_PORT=2465
MAIL_USERNAME=fixture-user
MAIL_PASSWORD=fixture-password
MAIL_FROM_ADDRESS=hello@example.com
EOF
chmod 600 "$FIXTURE/production.env"
cd "$FIXTURE/source"
# A foreign HTTP server must cause a read-only failure without being stopped.
systemctl stop nginx
python3 -m http.server 80 --bind 127.0.0.1 > "$FIXTURE/foreign-http.log" 2>&1 &
foreign_pid=$!
sleep 1
if bash deploy.sh --check --env-file "$FIXTURE/production.env" --domain secondhand.test.invalid; then exit 1; fi
kill -0 "$foreign_pid"
[[ ! -e /etc/secondhand ]]
kill "$foreign_pid"; wait "$foreign_pid" || true
systemctl start nginx
bash deploy.sh --check --env-file "$FIXTURE/production.env" --domain secondhand.test.invalid
[[ ! -e /etc/secondhand ]]
printf '20\n' > "$FIXTURE/certificate-days"
bash deploy.sh --env-file "$FIXTURE/production.env" --domain secondhand.test.invalid
rm "$FIXTURE/certificate-days"
if [[ -n "$old_php" ]]; then [[ $(readlink -f /usr/bin/php) == "$old_php" ]]; fi
[[ $(command -v node || true) == "$old_node" ]]
[[ $(sha256sum /etc/nginx/conf.d/deployment-fixtures.conf) == "$site_hash" ]]
[[ $(curl -fsS -H 'Host: neighbour.test.invalid' http://127.0.0.1) == neighbour-preserved ]]
if [[ "$PROFILE" == shared ]]; then curl -fsS http://127.0.0.1:3000 >/dev/null; fi
curl -fsS --resolve secondhand.test.invalid:443:127.0.0.1 https://secondhand.test.invalid/login >/dev/null
[[ $(curl -s -o /dev/null -w '%{http_code}' --resolve secondhand.test.invalid:443:127.0.0.1 https://secondhand.test.invalid/.env) == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' --resolve secondhand.test.invalid:443:127.0.0.1 https://secondhand.test.invalid/horizon) == 302 ]]
host_address=$(hostname -I | awk '{ print $1 }')
for key in postgres redis meili ssr; do
    private_port=$(python3 deploy/config.py get --key "ports.$key")
    if curl --noproxy '*' --connect-timeout 2 -s "http://$host_address:$private_port" >/dev/null; then printf 'Private service reachable on the public interface\n' >&2; exit 1; fi
done
cp /etc/secondhand/state.json "$FIXTURE/first-state.json"
bash deploy.sh
grep -q '^renew$' "$FIXTURE/certbot-calls"
calls=$(wc -l < "$FIXTURE/certbot-calls")
python3 - "$FIXTURE/first-state.json" /etc/secondhand/state.json <<'PY'
import json,sys
old,new=(json.load(open(path)) for path in sys.argv[1:])
for key in ['ports','db_password','redis_password','meili_key']: assert old[key] == new[key], key
assert old['settings']['APP_KEY'] == new['settings']['APP_KEY']

PY

# Reuse the certificate; change domain and remember it on later runs.
bash deploy.sh
[[ $(wc -l < "$FIXTURE/certbot-calls") == "$calls" ]]
bash deploy.sh --domain changed.secondhand.test.invalid
if grep -q 'server_name secondhand.test.invalid;' /etc/nginx/conf.d/secondhand.conf; then exit 1; fi
curl -fsS --resolve changed.secondhand.test.invalid:443:127.0.0.1 https://changed.secondhand.test.invalid/up >/dev/null
[[ $(python3 deploy/config.py get --key domain) == changed.secondhand.test.invalid ]]

# Certificate failures leave the existing application and configuration working.
before=$(sha256sum /var/www/secondhand/app/.env /etc/nginx/conf.d/secondhand.conf)
touch "$FIXTURE/fail-certbot"
if bash deploy.sh --domain bad.secondhand.test.invalid; then exit 1; fi
rm "$FIXTURE/fail-certbot"
[[ $(sha256sum /var/www/secondhand/app/.env /etc/nginx/conf.d/secondhand.conf) == "$before" ]]
curl -fsS --resolve changed.secondhand.test.invalid:443:127.0.0.1 https://changed.secondhand.test.invalid/up >/dev/null

# Invalid mail settings must fail before maintenance or data changes.
touch "$FIXTURE/fail-smtp"
if bash deploy.sh; then exit 1; fi
rm "$FIXTURE/fail-smtp"
[[ $(sha256sum /var/www/secondhand/app/.env /etc/nginx/conf.d/secondhand.conf) == "$before" ]]
curl -fsS --resolve changed.secondhand.test.invalid:443:127.0.0.1 https://changed.secondhand.test.invalid/login >/dev/null

# Real Horizon image job against this disposable stack, using fixture private S3.
cd /var/www/secondhand/app
SECONDHAND_DISPOSABLE_VM=yes runuser -u secondhand -- /usr/bin/php8.4 tests/Deployment/native-worker.php
for unit in secondhand-{postgres,redis,meili,ssr,horizon}.service secondhand-scheduler.timer; do
    systemctl is-enabled --quiet "$unit"
    systemctl restart "$unit"
    systemctl is-active --quiet "$unit"
done
runuser -u secondhand -- /usr/bin/php8.4 artisan deploy:health --storage
printf 'Native Ubuntu deployment smoke checks passed; real ACME, cloud providers and reboot still require verification.\n'
