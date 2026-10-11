#!/usr/bin/env python3
"""Small stdlib-only helpers. No environment file or saved state is executed."""

import argparse
import base64
import json
import glob
import getpass
import os
from pathlib import Path
import re
import secrets
import shlex
import sys
import tempfile

DEFAULT_DOMAIN = "secondhand.webostics.com"
ALLOWED_SETTINGS = {
    "APP_NAME", "APP_KEY", "LISTING_EXPIRY_DAYS", "AWS_ACCESS_KEY_ID",
    "AWS_SECRET_ACCESS_KEY", "AWS_DEFAULT_REGION", "AWS_BUCKET", "AWS_ENDPOINT",
    "AWS_USE_PATH_STYLE_ENDPOINT", "MAIL_MAILER", "MAIL_SCHEME", "MAIL_HOST",
    "MAIL_PORT", "MAIL_USERNAME", "MAIL_PASSWORD", "MAIL_FROM_ADDRESS",
    "MAIL_FROM_NAME", "MAIL_ENCRYPTION", "LISTING_IMAGES_DISK", "ASSET_URL", "SENTRY_LARAVEL_DSN", "CERTBOT_EMAIL",
}
# Accept a normal Laravel .env, but deployment owns these runtime settings.
MANAGED_SETTINGS = set("""
APP_ENV APP_DEBUG APP_URL APP_LOCALE APP_FALLBACK_LOCALE APP_FAKER_LOCALE
APP_MAINTENANCE_DRIVER BCRYPT_ROUNDS LOG_CHANNEL LOG_STACK LOG_LEVEL LOG_DAILY_DAYS
DB_CONNECTION DB_URL DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
SESSION_DRIVER SESSION_CONNECTION SESSION_LIFETIME SESSION_ENCRYPT SESSION_PATH
SESSION_DOMAIN SESSION_SECURE_COOKIE SESSION_HTTP_ONLY SESSION_SAME_SITE
BROADCAST_CONNECTION FILESYSTEM_DISK QUEUE_CONNECTION CACHE_STORE REDIS_CLIENT
REDIS_HOST REDIS_PASSWORD REDIS_PORT REDIS_DB REDIS_CACHE_DB REDIS_PREFIX CACHE_PREFIX
MEILISEARCH_HOST MEILISEARCH_KEY VITE_APP_NAME SCOUT_DRIVER SCOUT_PREFIX
INERTIA_SSR_ENABLED INERTIA_SSR_HOST INERTIA_SSR_URL INERTIA_SSR_PORT
""".split())
REQUIRED_SETTINGS = {
    "MAIL_HOST", "MAIL_PORT", "MAIL_USERNAME", "MAIL_PASSWORD", "MAIL_FROM_ADDRESS",
}
CLOUD_SETTINGS = {"AWS_ACCESS_KEY_ID", "AWS_SECRET_ACCESS_KEY", "AWS_DEFAULT_REGION", "AWS_BUCKET"}


def domain(value):
    value = value.lower()
    if len(value) > 253 or not re.fullmatch(
        r"(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+"
        r"[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?", value
    ):
        raise ValueError("Pass a hostname only, e.g. secondhand.om.pk (no URL, port or wildcard).")
    return value


def parse_env(path):
    values = {}
    for number, line in enumerate(Path(path).read_text(encoding="utf-8-sig").splitlines(), 1):
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        match = re.fullmatch(r"([A-Z][A-Z0-9_]*)\s*=\s*(.*)", line)
        if not match:
            raise ValueError(f"Invalid environment-file syntax on line {number}.")
        key, value = match.groups()
        if key not in ALLOWED_SETTINGS | MANAGED_SETTINGS:
            raise ValueError(f"Unsupported setting {key}; use deploy/production.env.example.")
        if key in values:
            raise ValueError(f"Duplicate setting {key}.")
        if value.startswith(("'", '"')):
            quote = value[0]
            # Double quotes support only dotenv's literal quote/backslash/dollar escapes.
            pattern = r"'([^']*)'\s*(?:#.*)?" if quote == "'" else r'"((?:[^"\\]|\\["\\$])*)"\s*(?:#.*)?'
            parsed = re.fullmatch(pattern, value)
            if not parsed:
                raise ValueError(f"Invalid quoted value for {key} on line {number}.")
            value = parsed.group(1)
            if quote == '"':
                value = re.sub(r'\\(["\\$])', r"\1", value)
        else:
            value = re.split(r"\s+#", value, maxsplit=1)[0].strip()
            if any(c.isspace() for c in value):
                raise ValueError(f"Quote the value for {key}.")
        if any(ord(c) < 32 or ord(c) == 127 for c in value):
            raise ValueError(f"Control characters are not allowed in {key}.")
        values[key] = value
    return values


def validate_settings(values):
    disk = values.get("LISTING_IMAGES_DISK", "listing_images")
    if disk not in {"listing_images", "s3"}:
        raise ValueError("LISTING_IMAGES_DISK must be listing_images (private VPS storage) or s3.")
    required = REQUIRED_SETTINGS | (CLOUD_SETTINGS if disk == "s3" else set())
    missing = sorted(key for key in required if not values.get(key)
                     or key != "MAIL_PASSWORD" and values[key].lower() in {"null", "empty"})
    if missing:
        raise ValueError("Missing production settings: " + ", ".join(missing))
    if values.get("MAIL_MAILER", "smtp") != "smtp":
        raise ValueError("This deployment supports authenticated transactional SMTP; log/array mail is not allowed.")
    if values.get("MAIL_SCHEME", "smtp") not in {"smtp", "smtps"}:
        raise ValueError("MAIL_SCHEME must be smtp or smtps.")
    if not values["MAIL_PORT"].isdigit() or not 1 <= int(values["MAIL_PORT"]) <= 65535:
        raise ValueError("MAIL_PORT must be a valid port.")
    if values.get("APP_KEY") and not re.fullmatch(r"base64:[A-Za-z0-9+/]{43}=", values["APP_KEY"]):
        raise ValueError("APP_KEY must contain a base64-encoded 32-byte Laravel key.")
    for key in ("AWS_ENDPOINT", "ASSET_URL", "SENTRY_LARAVEL_DSN"):
        if values.get(key) and not values[key].startswith("https://"):
            raise ValueError(f"{key} must use HTTPS.")
    if values.get("AWS_USE_PATH_STYLE_ENDPOINT", "false") not in {"true", "false"}:
        raise ValueError("AWS_USE_PATH_STYLE_ENDPOINT must be true or false.")
    if values.get("AWS_BUCKET") and not re.fullmatch(r"[A-Za-z0-9._-]+", values["AWS_BUCKET"]):
        raise ValueError("Invalid AWS_BUCKET name.")
    if not re.fullmatch(r"[^\s@]+@[^\s@]+\.[^\s@]+", values["MAIL_FROM_ADDRESS"]):
        raise ValueError("MAIL_FROM_ADDRESS must be a real sender address.")
    if values.get("CERTBOT_EMAIL") and not re.fullmatch(r"[^\s@]+@[^\s@]+\.[^\s@]+", values["CERTBOT_EMAIL"]):
        raise ValueError("CERTBOT_EMAIL must be a valid contact address.")
    if values.get("LISTING_EXPIRY_DAYS") and not re.fullmatch(r"[1-9][0-9]{0,3}", values["LISTING_EXPIRY_DAYS"]):
        raise ValueError("LISTING_EXPIRY_DAYS must be 1 through 9999.")


def read_state(path):
    if not Path(path).exists():
        return {}
    data = json.loads(Path(path).read_text())
    if data.get("version") != 2:
        raise ValueError("Unsupported deployment state version.")
    if data.get("domain"):
        domain(data["domain"])
    ports = data.get("ports", {})
    if set(ports) != {"postgres", "redis", "meili", "ssr"} or len(set(ports.values())) != 4:
        raise ValueError("Invalid port assignments in deployment state.")
    if any(type(port) is not int or not 1024 <= port <= 65535 for port in ports.values()):
        raise ValueError("Invalid private port in deployment state.")
    for key in ("db_password", "redis_password", "meili_key"):
        if not re.fullmatch(r"[a-f0-9]{64}", data.get(key, "")):
            raise ValueError("Invalid service credential in deployment state.")
    return data


def atomic_write(path, text, mode=0o600):
    path = Path(path)
    fd, temporary = tempfile.mkstemp(dir=path.parent, prefix=".secondhand-")
    try:
        if hasattr(os, "fchmod"):
            os.fchmod(fd, mode)
        else:
            os.chmod(temporary, mode)
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as stream:
            stream.write(text)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def settings_for(state, input_file, validate=True):
    values = dict(state.get("settings", {}))
    # Older deployments used cloud storage exclusively. Keep their existing disk.
    values.setdefault("LISTING_IMAGES_DISK", "s3" if state else "listing_images")
    if input_file:
        imported = {key: value for key, value in parse_env(input_file).items() if key in ALLOWED_SETTINGS}
        # Match Laravel's removal of one inner quote pair. Our writer uses this
        # pair to protect reserved words and literal quotes in credentials.
        for key, value in imported.items():
            if len(value) >= 2 and value[0] in {'"', "'"} and value[-1] == value[0]:
                imported[key] = value[1:-1]
        values.update(imported)
    encryption = values.pop("MAIL_ENCRYPTION", "").lower()
    if encryption not in {"", "null", "ssl", "tls"}:
        raise ValueError("MAIL_ENCRYPTION must be ssl or tls; alternatively use MAIL_SCHEME.")
    if encryption in {"ssl", "tls"}:
        values["MAIL_SCHEME"] = "smtps" if encryption == "ssl" else "smtp"
    elif values.get("MAIL_SCHEME") in {None, "", "null"}:
        values["MAIL_SCHEME"] = "smtps" if values.get("MAIL_PORT") == "465" else "smtp"
    if values.get("MAIL_FROM_NAME") == "${APP_NAME}":
        values["MAIL_FROM_NAME"] = values.get("APP_NAME", "Second Hand")
    if validate:
        validate_settings(values)
    if state and values.get("APP_KEY") != state["settings"]["APP_KEY"]:
        raise ValueError("Changing APP_KEY during deployment is prohibited; preserve it when moving servers.")
    return values


def env_text(values):
    def quote(key, value):
        if key in {"APP_DEBUG", "SESSION_HTTP_ONLY", "SESSION_SECURE_COOKIE", "SESSION_ENCRYPT",
                   "AWS_USE_PATH_STYLE_ENDPOINT", "INERTIA_SSR_ENABLED", "SESSION_DOMAIN"}:
            return value
        # Match Laravel's string handling, including reserved words and quotes.
        if not key.startswith("VITE_") and (
                value.lower() in {"true", "false", "null", "empty", "(true)", "(false)", "(null)", "(empty)"}
                or len(value) >= 2 and value[0] in {'"', "'"} and value[-1] == value[0]):
            value = '"' + value + '"'
        return '"' + value.replace("\\", "\\\\").replace('"', '\\"').replace("$", "\\$") + '"'
    return "".join(f"{key}={quote(key, str(value))}\n" for key, value in values.items())


def bootstrap_env(state, path, template, host):
    path = Path(path)
    if path.is_symlink() or path.exists() and not path.is_file():
        raise ValueError("The root .env must be a regular file, not a symlink.")
    if not path.exists() and state:
        # Restore saved production settings; do not rotate keys or change a live URL.
        write_env(state, state.get("domain") or host, path)
        print("[secondhand] Restored root .env from protected deployment settings.")
        return
    created = not path.exists()
    source = template if created else path
    original = parse_env(source)
    values = settings_for(state, source, validate=False)
    previous = values.copy()
    values["MAIL_MAILER"] = "smtp"
    if values.get("MAIL_HOST") in {None, "", "null", "127.0.0.1", "localhost"}:
        values.update(MAIL_HOST="smtp.gmail.com", MAIL_PORT="465", MAIL_SCHEME="smtps")
    if not state:
        values["APP_KEY"] = values.get("APP_KEY") or "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()
        original.update(APP_ENV="production", APP_DEBUG="false", APP_URL="https://" + domain(host),
                        SESSION_SECURE_COOKIE="true")
    if values.get("MAIL_FROM_ADDRESS") in {None, "", "null", "hello@example.com"}:
        values["MAIL_FROM_ADDRESS"] = values.get("MAIL_USERNAME", "")
    missing = [key for key in REQUIRED_SETTINGS if not values.get(key) or values[key].lower() in {"null", "empty"}]
    if created:
        atomic_write(path, env_text(original | values))
        print("[secondhand] Created protected root .env from .env.example.")
    if missing:
        if not sys.stdin.isatty():
            raise ValueError("Root .env is ready. Fill " + ", ".join(sorted(missing))
                             + " in that file, then rerun sudo bash deploy.sh from a terminal.")
        print("[secondhand] Enter missing SMTP settings once; the app password is hidden.")
        for key in ("MAIL_HOST", "MAIL_PORT", "MAIL_USERNAME", "MAIL_PASSWORD", "MAIL_FROM_ADDRESS"):
            if key not in missing:
                continue
            if key == "MAIL_FROM_ADDRESS" and values.get("MAIL_USERNAME"):
                values[key] = values["MAIL_USERNAME"]
                continue
            value = getpass.getpass("SMTP app password: ") if key == "MAIL_PASSWORD" else input(key + ": ").strip()
            if any(ord(c) < 32 or ord(c) == 127 for c in value):
                raise ValueError("Control characters are not allowed in " + key + ".")
            values[key] = value
    validate_settings(values)
    if created or missing or values != previous:
        # Save the new key/mail details even if a later package/build step fails.
        atomic_write(path, env_text(original | values))


def write_env(state, host, output):
    values = {
        "APP_NAME": "Second Hand", "APP_LOCALE": "en", "APP_FALLBACK_LOCALE": "en",
        "APP_MAINTENANCE_DRIVER": "file", "BCRYPT_ROUNDS": "12",
        "LOG_CHANNEL": "daily", "LOG_LEVEL": "warning", "LOG_DAILY_DAYS": "14",
        "SESSION_LIFETIME": "120", "SESSION_DOMAIN": "null", "SESSION_PATH": "/",
        "SESSION_HTTP_ONLY": "true", "SESSION_SAME_SITE": "lax",
        "MAIL_MAILER": "smtp", "MAIL_SCHEME": "smtp", "MAIL_FROM_NAME": "Second Hand",
        "FILESYSTEM_DISK": "local", "BROADCAST_CONNECTION": "log",
        "AWS_USE_PATH_STYLE_ENDPOINT": "false",
    }
    values.update({k: v for k, v in state["settings"].items() if k != "CERTBOT_EMAIL"})
    values.update({
        "APP_ENV": "production", "APP_DEBUG": "false", "APP_URL": "https://" + domain(host),
        "SESSION_SECURE_COOKIE": "true", "SESSION_ENCRYPT": "true", "SESSION_DRIVER": "redis",
        "CACHE_STORE": "redis", "QUEUE_CONNECTION": "redis", "DB_CONNECTION": "pgsql",
        "DB_HOST": "127.0.0.1", "DB_PORT": str(state["ports"]["postgres"]),
        "DB_DATABASE": "secondhand", "DB_USERNAME": "secondhand", "DB_PASSWORD": state["db_password"],
        "REDIS_CLIENT": "phpredis", "REDIS_HOST": "127.0.0.1", "REDIS_PORT": str(state["ports"]["redis"]),
        "REDIS_PASSWORD": state["redis_password"], "REDIS_DB": "0", "REDIS_CACHE_DB": "1",
        "REDIS_PREFIX": "secondhand_", "CACHE_PREFIX": "secondhand_cache_",
        "MEILISEARCH_HOST": "http://127.0.0.1:" + str(state["ports"]["meili"]),
        "MEILISEARCH_KEY": state["meili_key"], "SCOUT_DRIVER": "meilisearch", "SCOUT_PREFIX": "secondhand_",
        "LISTING_IMAGES_DISK": state["settings"].get("LISTING_IMAGES_DISK", "s3"),
        "INERTIA_SSR_ENABLED": "true", "INERTIA_SSR_HOST": "127.0.0.1",
        "INERTIA_SSR_PORT": str(state["ports"]["ssr"]),
        "INERTIA_SSR_URL": "http://127.0.0.1:" + str(state["ports"]["ssr"]),
        "VITE_APP_NAME": values["APP_NAME"],
    })
    atomic_write(output, env_text(values), 0o640)


def nginx_directives(text):
    lexer = shlex.shlex(text, posix=True, punctuation_chars=";{}")
    lexer.whitespace_split = True
    statement = []
    for token in lexer:
        if token and all(c in ";{}" for c in token):
            for separator in token:
                if separator == ";" and statement:
                    yield statement
                statement = []
        else:
            statement.append(token)


def check_nginx_names(text, host):
    for statement in nginx_directives(text):
        if statement[0] == "server_name":
            for name in statement[1:]:
                name = name.lower()
                if name.startswith("~"):
                    raise ValueError("Existing regex server_name needs manual review before adding this domain.")
                if (name == host or name == "." + host or
                    name.startswith("*.") and host.endswith(name[1:]) or
                    name.startswith(".") and host.endswith(name) or
                    name.endswith(".*") and host.startswith(name[:-1])):
                    raise ValueError("The requested domain matches an existing unmanaged Nginx site.")


def nginx_conflicts(text, host, owned_paths):
    for block in re.split(r"(?m)^# configuration file ", text)[1:]:
        path, _, contents = block.partition("\n")
        if path.rstrip(":") not in owned_paths:
            check_nginx_names(contents, host)


def nginx_readonly(path, host, owned_paths):
    prefix = Path(path).parent
    visited = set()
    def visit(filename):
        filename = Path(filename)
        resolved = str(filename.resolve())
        if resolved in visited:
            return
        visited.add(resolved)
        text = filename.read_text()
        if str(filename) not in owned_paths:
            check_nginx_names(text, host)
        for statement in nginx_directives(text):
            if statement[0] == "include":
                if len(statement) != 2 or "$" in statement[1]:
                    raise ValueError("This Nginx include needs manual review.")
                include = Path(statement[1])
                if not include.is_absolute():
                    include = prefix / include
                matches = glob.glob(str(include))
                if not matches and not glob.has_magic(str(include)):
                    raise ValueError("An included Nginx file is missing.")
                for match in sorted(matches):
                    visit(match)
    visit(path)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["bootstrap", "validate", "init", "get", "env", "commit", "conflicts", "nginx-check", "redact"])
    parser.add_argument("--state", default="/etc/secondhand/state.json")
    parser.add_argument("--input")
    parser.add_argument("--template")
    parser.add_argument("--domain")
    parser.add_argument("--output")
    parser.add_argument("--key")
    parser.add_argument("--ports", nargs=4, type=int)
    parser.add_argument("--email")
    args = parser.parse_args()
    state = read_state(args.state)
    if args.command == "bootstrap":
        bootstrap_env(state, args.input, args.template, domain(args.domain))
    elif args.command == "validate":
        settings_for(state, args.input)
        if args.domain:
            domain(args.domain)
    elif args.command == "init":
        values = settings_for(state, args.input)
        if args.email:
            values["CERTBOT_EMAIL"] = args.email
            validate_settings(values)
        if not state:
            values.setdefault("APP_KEY", "base64:" + base64.b64encode(secrets.token_bytes(32)).decode())
            if not values["APP_KEY"]:
                values["APP_KEY"] = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()
            state = {"version": 2, "domain": "", "seeded": False,
                     "ports": dict(zip(("postgres", "redis", "meili", "ssr"), args.ports)),
                     "db_password": secrets.token_hex(32), "redis_password": secrets.token_hex(32),
                     "meili_key": secrets.token_hex(32)}
        state["settings"] = values
        atomic_write(args.output, json.dumps(state, indent=2) + "\n")
    elif args.command == "get":
        value = state
        for key in args.key.split("."):
            value = value.get(key, "") if isinstance(value, dict) else ""
        print(str(value).lower() if isinstance(value, bool) else value)
    elif args.command == "env":
        write_env(state, args.domain, args.output)
    elif args.command == "commit":
        if args.input:
            state = read_state(args.input)
        state.update(domain=domain(args.domain), seeded=True)
        atomic_write(args.state, json.dumps(state, indent=2) + "\n")
    elif args.command == "conflicts":
        nginx_conflicts(sys.stdin.read(), domain(args.domain),
                        {"/etc/nginx/conf.d/secondhand.conf", "/etc/nginx/conf.d/secondhand-acme.conf"})
    elif args.command == "nginx-check":
        nginx_readonly(args.input, domain(args.domain),
                       {"/etc/nginx/conf.d/secondhand.conf", "/etc/nginx/conf.d/secondhand-acme.conf"})
    elif args.command == "redact":
        text = sys.stdin.read()
        values = [state.get(key, "") for key in ("db_password", "redis_password", "meili_key")]
        values += [v for k, v in state.get("settings", {}).items() if re.search("KEY|SECRET|PASSWORD|USERNAME|DSN", k)]
        if args.input:
            values += list(parse_env(args.input).values())
        for value in sorted(filter(None, values), key=len, reverse=True):
            text = text.replace(value, "[REDACTED]")
        text = re.sub(r"(https?://)[^\s/@]+:[^\s/@]+@", r"\1[REDACTED]@", text)
        sys.stdout.write(text)


if __name__ == "__main__":
    try:
        main()
    except (EOFError, KeyboardInterrupt):
        print("Configuration interrupted; rerun deploy.sh to finish setup.", file=sys.stderr)
        sys.exit(1)
    except (ValueError, OSError, KeyError, TypeError) as error:
        # Messages are intentionally about keys/structure, not secret values or raw input.
        print("Configuration error: " + str(error), file=sys.stderr)
        sys.exit(1)
