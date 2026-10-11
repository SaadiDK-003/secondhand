import importlib.util
import json
import os
from pathlib import Path
import tempfile
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("deploy_config", ROOT / "deploy/config.py")
config = importlib.util.module_from_spec(spec)
spec.loader.exec_module(config)


class DeploymentConfigurationTests(unittest.TestCase):
    def setUp(self):
        (ROOT / '.tools').mkdir(exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(dir=ROOT / '.tools')
        self.root = Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def state(self):
        return {"version": 2, "domain": "secondhand.webostics.com", "seeded": False,
                "ports": {"postgres": 5432, "redis": 20000, "meili": 7700, "ssr": 13714},
                "db_password": "a" * 64, "redis_password": "b" * 64, "meili_key": "c" * 64,
                "settings": {"APP_KEY": "base64:" + "a" * 43 + "=", "AWS_ACCESS_KEY_ID": "test",
                             "AWS_SECRET_ACCESS_KEY": "secret", "AWS_DEFAULT_REGION": "auto",
                             "AWS_BUCKET": "private", "MAIL_HOST": "smtp.test", "MAIL_PORT": "587",
                             "MAIL_USERNAME": "user", "MAIL_PASSWORD": "secret",
                             "MAIL_FROM_ADDRESS": "mail@example.com"}}

    def test_valid_domains_and_injection_rejection(self):
        self.assertEqual("secondhand.om.pk", config.domain("SecondHand.OM.PK"))
        for value in ["https://example.com", "example.com; reboot", "*.example.com", "example.com:443",
                      "example.com\nserver_name evil;", "a..com", "-a.com", "a-.com", "localhost", "127.0.0.1"]:
            with self.subTest(value=value), self.assertRaises(ValueError):
                config.domain(value)

    def test_environment_is_data_and_never_executed_or_expanded(self):
        path = self.root / "env"
        path.write_text('APP_NAME="Second Hand"\nMAIL_PASSWORD=\'$(touch /tmp/no-execution) ${HOME} `id`\'\n')
        values = config.parse_env(path)
        self.assertEqual('$(touch /tmp/no-execution) ${HOME} `id`', values["MAIL_PASSWORD"])

    def test_invalid_environment_syntax_unknown_keys_and_duplicates_fail(self):
        for text in ["export MAIL_PASSWORD=x", "MAIL_PASSWORD='unterminated", "PATH=/malicious",
                     "APP_NAME=a\nAPP_NAME=b", "MAIL_PASSWORD=\"a\\n\"", "APP_NAME=unquoted spaces"]:
            path = self.root / "bad"
            path.write_text(text)
            with self.subTest(text=text), self.assertRaises(ValueError):
                config.parse_env(path)

    def test_required_production_settings_and_tls_urls(self):
        values = self.state()["settings"]
        config.validate_settings(values)
        for key, value in [("AWS_BUCKET", ""), ("MAIL_MAILER", "log"), ("MAIL_PORT", "70000"),
                           ("AWS_ENDPOINT", "http://insecure.test"), ("APP_KEY", "regenerate")]:
            with self.subTest(key=key), self.assertRaises(ValueError):
                config.validate_settings(values | {key: value})

    def test_existing_key_cannot_be_rotated_by_environment_import(self):
        path = self.root / "env"
        path.write_text('APP_KEY="base64:' + 'b' * 43 + '="\n')
        with self.assertRaises(ValueError):
            config.settings_for(self.state(), path)

    def test_production_env_uses_persistent_private_ports_and_escapes_secrets(self):
        state = self.state()
        state["settings"]["MAIL_PASSWORD"] = 'literal$HOME "quote" \\ backtick`'
        output = self.root / "env"
        config.write_env(state, "changed.example.com", output)
        values = output.read_text()
        self.assertIn('APP_URL="https://changed.example.com"', values)
        self.assertIn('REDIS_PORT="20000"', values)
        self.assertIn('SESSION_SECURE_COOKIE=true', values)
        self.assertIn('APP_ENV="production"', values)
        self.assertIn('MAIL_PASSWORD="literal\\$HOME \\"quote\\" \\\\ backtick`"', values)
        if os.name != 'nt':
            self.assertEqual(0o640, output.stat().st_mode & 0o777)

    def test_state_rejects_duplicate_ports_invalid_domains_and_secrets(self):
        state = self.state()
        path = self.root / "state"
        config.atomic_write(path, json.dumps(state))
        self.assertEqual(state, config.read_state(path))
        for updates in [{"domain": "../other-site"}, {"db_password": "bad"},
                        {"ports": state["ports"] | {"redis": 5432}}]:
            config.atomic_write(path, json.dumps(state | updates))
            with self.subTest(updates=list(updates)), self.assertRaises(ValueError):
                config.read_state(path)

    def test_passwords_named_like_boolean_values_stay_strings(self):
        state = self.state()
        for value in ['true', 'false', 'null', 'empty', '(null)', '"quoted"']:
            state['settings']['MAIL_PASSWORD'] = value
            output = self.root / 'env'
            config.write_env(state, 'example.com', output)
            self.assertIn('MAIL_PASSWORD="\\"', output.read_text())
            if php := os.environ.get('PHP_BINARY'):
                code = r'''require $argv[2].'/vendor/autoload.php';
                    Dotenv\Dotenv::create(Illuminate\Support\Env::getRepository(), dirname($argv[1]), basename($argv[1]))->load();
                    echo json_encode(Illuminate\Support\Env::get('MAIL_PASSWORD'));'''
                result = subprocess.run([php, '-r', code, str(output), str(ROOT)], check=True, capture_output=True, text=True)
                self.assertEqual(value, json.loads(result.stdout))

    def test_commit_remembers_domain_without_changing_credentials(self):
        path = self.root / "state"
        state = self.state()
        config.atomic_write(path, json.dumps(state))
        subprocess.run([os.environ.get('PYTHON_BINARY', __import__('sys').executable),
                        str(ROOT / 'deploy/config.py'), 'commit', '--state', str(path),
                        '--domain', 'changed.example.com'], check=True, capture_output=True)
        saved = config.read_state(path)
        self.assertEqual('changed.example.com', saved['domain'])
        self.assertTrue(saved['seeded'])
        for key in ['ports', 'settings', 'db_password', 'redis_password', 'meili_key']:
            self.assertEqual(state[key], saved[key])

    def test_unmanaged_nginx_domains_and_wildcards_are_preserved(self):
        for names in ['secondhand.om.pk', '"SECONDHAND.OM.PK"', '\n  secondhand.om.pk', '*.om.pk', '.om.pk', 'secondhand.*', '~^secondhand']:
            text = '# configuration file /etc/nginx/sites-enabled/other:\nserver_name ' + names + ';'
            with self.subTest(names=names), self.assertRaises(ValueError):
                config.nginx_conflicts(text, 'secondhand.om.pk', set())
        config.nginx_conflicts('# configuration file /etc/nginx/conf.d/secondhand.conf:\nserver_name secondhand.om.pk;',
                               'secondhand.om.pk', {'/etc/nginx/conf.d/secondhand.conf'})

    def test_readonly_nginx_inspection_follows_relative_glob_includes(self):
        main = self.root / 'nginx.conf'
        sites = self.root / 'sites'
        sites.mkdir()
        main.write_text('http { include sites/*.conf; }')
        site = sites / 'other.conf'
        site.write_text('server {server_name\n "SECONDHAND.OM.PK";}')
        before = {str(p): p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
        with self.assertRaises(ValueError):
            config.nginx_readonly(main, 'secondhand.om.pk', set())
        config.nginx_readonly(main, 'unrelated.example.com', set())
        after = {str(p): p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
        self.assertEqual(before, after)


if __name__ == "__main__":
    unittest.main()
