import importlib.util
import json
import os
from pathlib import Path
import tempfile
import subprocess
import unittest
from unittest.mock import patch

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
                             "AWS_BUCKET": "private", "LISTING_IMAGES_DISK": "s3", "MAIL_HOST": "smtp.test", "MAIL_PORT": "587",
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

    def test_root_laravel_env_uses_vps_storage_and_gmail_ssl_without_cloud_credentials(self):
        values = config.parse_env(ROOT / '.env.example')
        values.update(MAIL_MAILER='smtp', MAIL_HOST='smtp.gmail.com', MAIL_PORT='465',
                      MAIL_USERNAME='sender@example.com', MAIL_PASSWORD='replacement-test-password',
                      MAIL_ENCRYPTION='ssl', MAIL_FROM_ADDRESS='sender@example.com')
        path = self.root / 'env'
        path.write_text(''.join(f'{key}="{value}"\n' for key, value in values.items()))
        settings = config.settings_for({}, path)
        self.assertEqual('listing_images', settings['LISTING_IMAGES_DISK'])
        self.assertEqual('smtps', settings['MAIL_SCHEME'])
        self.assertEqual('Second Hand', settings['MAIL_FROM_NAME'])
        self.assertNotIn('DB_PASSWORD', settings)
        self.assertNotIn('APP_DEBUG', settings)
        state = self.state() | {'settings': settings}
        output = self.root / 'runtime'
        config.write_env(state, 'example.com', output)
        runtime = config.parse_env(output)
        self.assertEqual('listing_images', runtime['LISTING_IMAGES_DISK'])
        self.assertEqual('production', runtime['APP_ENV'])
        self.assertEqual('false', runtime['APP_DEBUG'])
        self.assertEqual('smtps', runtime['MAIL_SCHEME'])
        self.assertEqual(state['db_password'], runtime['DB_PASSWORD'])

    def test_cloud_storage_requires_credentials_and_public_storage_is_rejected(self):
        settings = {key: value for key, value in self.state()['settings'].items()
                    if key not in config.CLOUD_SETTINGS}
        config.validate_settings(settings | {'LISTING_IMAGES_DISK': 'listing_images'})
        with self.assertRaisesRegex(ValueError, 'AWS_BUCKET'):
            config.validate_settings(settings)
        with self.assertRaisesRegex(ValueError, 'LISTING_IMAGES_DISK'):
            config.validate_settings(settings | {'LISTING_IMAGES_DISK': 'public'})

    def test_legacy_cloud_disk_and_existing_key_survive_root_env_reimport(self):
        state = self.state()
        del state['settings']['LISTING_IMAGES_DISK']
        path = self.root / 'env'
        config.write_env(state, 'example.com', path)
        self.assertEqual('s3', config.settings_for(state, path)['LISTING_IMAGES_DISK'])
        self.assertEqual(state['settings']['APP_KEY'], config.settings_for(state, path)['APP_KEY'])

    def test_first_run_bootstraps_example_and_asks_only_for_missing_mail_secrets(self):
        path = self.root / '.env'
        password = 'test$only "quote" \\ literal'
        with patch.object(config.sys.stdin, 'isatty', return_value=True), \
                patch('builtins.input', return_value='sender@example.com') as prompt, \
                patch.object(config.getpass, 'getpass', return_value=password) as secret:
            config.bootstrap_env({}, path, ROOT / '.env.example', 'example.com')
        prompt.assert_called_once_with('MAIL_USERNAME: ')
        secret.assert_called_once_with('SMTP app password: ')
        values = config.settings_for({}, path)
        self.assertEqual('sender@example.com', values['MAIL_FROM_ADDRESS'])
        self.assertEqual(password, values['MAIL_PASSWORD'])
        self.assertEqual('smtp.gmail.com', values['MAIL_HOST'])
        self.assertEqual('smtps', values['MAIL_SCHEME'])
        self.assertEqual('listing_images', values['LISTING_IMAGES_DISK'])
        self.assertEqual('production', config.parse_env(path)['APP_ENV'])
        self.assertEqual('false', config.parse_env(path)['APP_DEBUG'])
        self.assertRegex(values['APP_KEY'], r'^base64:[A-Za-z0-9+/]{43}=$')
        before = path.read_bytes()
        with patch('builtins.input', side_effect=AssertionError('Unexpected repeat prompt')), \
                patch.object(config.getpass, 'getpass', side_effect=AssertionError('Unexpected repeat password')):
            config.bootstrap_env({}, path, ROOT / '.env.example', 'example.com')
        self.assertEqual(before, path.read_bytes())
        if os.name != 'nt':
            self.assertEqual(0o600, path.stat().st_mode & 0o777)

    def test_noninteractive_setup_creates_protected_env_without_guessing_mail_credentials(self):
        path = self.root / '.env'
        with patch.object(config.sys.stdin, 'isatty', return_value=False):
            with self.assertRaisesRegex(ValueError, 'MAIL_PASSWORD'):
                config.bootstrap_env({}, path, ROOT / '.env.example', 'example.com')
            key = config.parse_env(path)['APP_KEY']
            with self.assertRaises(ValueError):
                config.bootstrap_env({}, path, ROOT / '.env.example', 'example.com')
        self.assertEqual(key, config.parse_env(path)['APP_KEY'])

    def test_missing_env_is_restored_from_state_without_changing_domain_or_credentials(self):
        path = self.root / '.env'
        state = self.state()
        with patch('builtins.input', side_effect=AssertionError('Unexpected prompt')):
            config.bootstrap_env(state, path, ROOT / '.env.example', 'new.example.com')
        values = config.parse_env(path)
        self.assertEqual('https://' + state['domain'], values['APP_URL'])
        self.assertEqual(state['settings']['APP_KEY'], values['APP_KEY'])
        self.assertEqual(state['db_password'], values['DB_PASSWORD'])
        self.assertEqual(state['redis_password'], values['REDIS_PASSWORD'])

    def test_interrupted_password_entry_keeps_generated_key_for_retry(self):
        path = self.root / '.env'
        with patch.object(config.sys.stdin, 'isatty', return_value=True), \
                patch('builtins.input', return_value='sender@example.com'), \
                patch.object(config.getpass, 'getpass', side_effect=KeyboardInterrupt):
            with self.assertRaises(KeyboardInterrupt):
                config.bootstrap_env({}, path, ROOT / '.env.example', 'example.com')
        self.assertRegex(config.parse_env(path)['APP_KEY'], r'^base64:')

    def test_bootstrap_refuses_malformed_env_without_overwriting_it(self):
        path = self.root / '.env'
        path.write_text('APP_NAME=example\nPATH=/malicious\n')
        before = path.read_bytes()
        with self.assertRaises(ValueError):
            config.bootstrap_env({}, path, ROOT / '.env.example', 'example.com')
        self.assertEqual(before, path.read_bytes())

    def test_existing_env_is_adjusted_without_rotating_key_or_switching_live_domain_early(self):
        state = self.state()
        path = self.root / '.env'
        config.write_env(state, state['domain'], path)
        path.write_text(path.read_text().replace('MAIL_MAILER="smtp"', 'MAIL_MAILER="log"'))
        config.bootstrap_env(state, path, ROOT / '.env.example', 'new.example.com')
        values = config.parse_env(path)
        self.assertEqual('smtp', values['MAIL_MAILER'])
        self.assertEqual('https://' + state['domain'], values['APP_URL'])
        self.assertEqual(state['settings']['APP_KEY'], values['APP_KEY'])
        self.assertEqual(state['db_password'], values['DB_PASSWORD'])

    def test_password_prompt_rejects_newline_injection_without_printing_or_saving_it(self):
        path = self.root / '.env'
        with patch.object(config.sys.stdin, 'isatty', return_value=True), \
                patch('builtins.input', return_value='sender@example.com'), \
                patch.object(config.getpass, 'getpass', return_value='secret\nAPP_DEBUG=true'):
            with self.assertRaisesRegex(ValueError, 'Control characters are not allowed in MAIL_PASSWORD'):
                config.bootstrap_env({}, path, ROOT / '.env.example', 'example.com')
        self.assertEqual('false', config.parse_env(path)['APP_DEBUG'])
        self.assertNotIn('secret', path.read_text())

    def test_log_redaction_preserves_error_paths_and_numbers_but_removes_credentials(self):
        state = self.state()
        state_path = self.root / 'state'
        config.atomic_write(state_path, json.dumps(state))
        path = self.root / '.env'
        config.write_env(state, state['domain'], path)
        message = ('/etc/php/8.4/fpm/pool.d/secondhand.conf:1 value is NULL\n'
                   'JIT disabled; built in 2.39s; SMTP authentication: OK\n'
                   + state['db_password'] + ' secret user base64:' + 'a' * 43 + '=\n')
        result = subprocess.run([os.environ.get('PYTHON_BINARY', __import__('sys').executable),
                                 str(ROOT / 'deploy/config.py'), 'redact', '--state', str(state_path),
                                 '--input', str(path)], input=message, text=True, capture_output=True, check=True)
        self.assertIn('/etc/php/8.4/fpm/pool.d/secondhand.conf:1 value is NULL', result.stdout)
        self.assertIn('built in 2.39s; SMTP authentication: OK', result.stdout)
        self.assertNotIn(state['db_password'], result.stdout)
        self.assertNotIn('secret', result.stdout)
        self.assertNotIn(' user ', result.stdout)
        self.assertNotIn(state['settings']['APP_KEY'], result.stdout)

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
            reimported = config.settings_for(state, output)
            self.assertEqual(value, reimported['MAIL_PASSWORD'])
            config.write_env(state | {'settings': reimported}, 'example.com', output)
            # Canonical SMTP scheme is added on import; subsequent runs are stable.
            canonical = output.read_bytes()
            config.write_env(state | {'settings': config.settings_for(state | {'settings': reimported}, output)},
                             'example.com', output)
            self.assertEqual(canonical, output.read_bytes())
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
