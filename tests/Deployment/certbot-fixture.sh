#!/usr/bin/env bash
# Only installed by the disposable VM harness; never used by deploy.sh itself.
set -Eeuo pipefail
FIXTURE=/root/secondhand-deploy-fixture
[[ -d "$FIXTURE" ]] || exit 1
[[ ! -f "$FIXTURE/fail-certbot" ]] || exit 42
name='' domain=''
operation=${1:-}
printf '%s\n' "$operation" >> "$FIXTURE/certbot-calls"
while (($#)); do
    case "$1" in
        --cert-name) name=$2; shift 2 ;;
        -d) domain=$2; shift 2 ;;
        *) shift ;;
    esac
done
if [[ "$operation" == renew ]]; then domain=${name#secondhand-}; fi
[[ "$name" =~ ^secondhand-[a-z0-9.-]+$ && "$domain" =~ ^[a-z0-9.-]+$ ]] || exit 1
target="/etc/letsencrypt/live/$name"
days=90
if [[ -f "$FIXTURE/certificate-days" ]]; then days=$(cat "$FIXTURE/certificate-days"); fi
[[ "$days" =~ ^[1-9][0-9]?$ ]] || exit 1
mkdir -p "$target"
openssl req -newkey rsa:2048 -nodes -keyout "$target/privkey.pem" -out "$target/request.csr" -subj "/CN=$domain" >/dev/null 2>&1
printf 'subjectAltName=DNS:%s\n' "$domain" > "$target/extensions"
openssl x509 -req -in "$target/request.csr" -CA "$FIXTURE/ca.crt" -CAkey "$FIXTURE/ca.key" -CAcreateserial -out "$target/cert.pem" -days "$days" -extfile "$target/extensions" >/dev/null 2>&1
cat "$target/cert.pem" "$FIXTURE/ca.crt" > "$target/fullchain.pem"
chmod 600 "$target/privkey.pem"
