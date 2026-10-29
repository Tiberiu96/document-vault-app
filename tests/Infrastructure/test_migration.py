"""Teste offline pentru installer; fara sudo, servicii sau credentiale reale."""
import base64
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest
import zlib
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('vaulty_migration', ROOT / 'deploy/bootstrap/migration/configure.py')
migration = importlib.util.module_from_spec(spec)
spec.loader.exec_module(migration)


class MigrationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='vaulty-migration-test-')
        self.root = Path(self.temp.name)
        self.previous_root = migration.ROOT
        migration.ROOT = self.root

    def tearDown(self):
        migration.ROOT = self.previous_root
        self.temp.cleanup()

    def bundle(self, entries=None):
        data = {'settings.json': b'{"APP_KEY":"fake-only"}', 'database.sql': b'-- fake database\n',
                'recovery.json': b'{}', 'documents/1/example.pdf': b'%PDF-fake-test-only'}
        manifest = {'format': 1, 'commit': 'a' * 40, 'files': {
            name: {'size': len(value), 'sha256': hashlib.sha256(value).hexdigest()} for name, value in data.items()}}
        data['manifest.json'] = json.dumps(manifest).encode()
        archive = self.root / 'bundle.tar.gz'
        with tarfile.open(archive, 'w:gz') as stream:
            for name, content in data.items():
                item = tarfile.TarInfo(name)
                item.size = len(content)
                stream.addfile(item, io.BytesIO(content))
            for item in entries or []:
                stream.addfile(item)
        return archive

    def unpack(self, archive):
        target = self.root / 'decoded'
        target.mkdir()
        migration.unpack(archive, target)
        return target

    def test_round_trip_and_document_hash(self):
        target = self.unpack(self.bundle())
        self.assertEqual((target/'documents/1/example.pdf').read_bytes(), b'%PDF-fake-test-only')

    def test_rejects_path_traversal(self):
        bad = tarfile.TarInfo('documents/../../outside')
        with self.assertRaises(migration.MigrationError):
            self.unpack(self.bundle([bad]))
        self.assertFalse((self.root/'outside').exists())

    def test_rejects_symlinks(self):
        bad = tarfile.TarInfo('documents/link')
        bad.type = tarfile.SYMTYPE
        bad.linkname = '/etc/passwd'
        with self.assertRaises(migration.MigrationError):
            self.unpack(self.bundle([bad]))

    def test_rejects_duplicate_file(self):
        with self.assertRaises(migration.MigrationError):
            self.unpack(self.bundle([tarfile.TarInfo('database.sql')]))

    def test_rejects_hash_mismatch(self):
        source = self.bundle()
        with tarfile.open(source) as archive:
            rows = [(i.name, archive.extractfile(i).read()) for i in archive.getmembers()]
        with tarfile.open(source, 'w:gz') as archive:
            for name, content in rows:
                if name == 'database.sql':
                    content += b'changed'
                item = tarfile.TarInfo(name); item.size = len(content)
                archive.addfile(item, io.BytesIO(content))
        with self.assertRaises(migration.MigrationError):
            self.unpack(source)

    def test_env_values_are_quoted_and_round_trip_without_shell(self):
        value = 'fake only $value \\ and "quotes"; $(never_execute)'
        migration.write_env({'settings': {'MAIL_PASSWORD': value, 'APP_KEY': 'fake', 'BAD\nNAME': 'ignored'}})
        path = self.root/'.env'
        self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        self.assertNotIn('BAD', path.read_text())
        if shutil.which('php'):
            result = subprocess.run(['php', '-r',
                'require $argv[1]; echo json_encode(Dotenv\\Dotenv::parse(file_get_contents($argv[2])));',
                str(ROOT/'vendor/autoload.php'), str(path)], capture_output=True, check=True, text=True)
            self.assertEqual(json.loads(result.stdout)['MAIL_PASSWORD'], value)

    def test_env_rejects_symlink(self):
        destination = self.root/'untouched'; destination.write_text('safe')
        (self.root/'.env').symlink_to(destination)
        with self.assertRaises(OSError):
            migration.write_env({'settings': {'APP_KEY': 'fake'}})
        self.assertEqual(destination.read_text(), 'safe')

    def test_env_rejects_multiline_secret(self):
        with self.assertRaises(migration.MigrationError):
            migration.write_env({'settings': {'MAIL_PASSWORD': 'fake\nAPP_DEBUG=true'}})

    def test_hostname_validation(self):
        for value in ['vaulty-beta.duckdns.org', 'vaulty.example.com']:
            self.assertTrue(migration.valid_domain(value))
        for value in ['https://vaulty.test', 'example.com;id', 'a\nexample.com', '../secret', '-bad.example.com']:
            self.assertFalse(migration.valid_domain(value))

    def test_resume_preserves_application_and_database_keys(self):
        state_path = self.root/'target.json'
        state = {'settings': {'APP_KEY': 'fake-preserved-key', 'DB_PASSWORD': 'fake-db-secret'}}
        migration.private_json(state_path, state)
        with patch.object(migration, 'STATE', state_path), patch('builtins.input', side_effect=['nu', 'nu']):
            migration.configure(None)
        self.assertEqual(json.loads(state_path.read_text()), state)
        self.assertEqual(state_path.stat().st_mode & 0o777, 0o600)

    def test_database_refuses_existing_names_before_creation(self):
        state = {'settings': {'DB_DATABASE': 'vaulty_test', 'DB_USERNAME': 'vaulty_test', 'DB_PASSWORD': 'ab' * 32}}
        response = subprocess.CompletedProcess([], 0, stdout='vaulty_test\n')
        with patch.object(migration, 'STATE', self.root/'target.json'), patch.object(migration.subprocess, 'run', return_value=response) as run:
            with self.assertRaises(migration.MigrationError):
                migration.database(state)
            self.assertEqual(run.call_count, 1)
            self.assertNotIn('CREATE DATABASE', run.call_args.kwargs['input'])

    def test_database_credentials_go_only_to_stdin(self):
        state = {'settings': {'DB_DATABASE': 'vaulty_test', 'DB_USERNAME': 'vaulty_test', 'DB_PASSWORD': 'cd' * 32}}
        response = subprocess.CompletedProcess([], 0, stdout='')
        with patch.object(migration, 'STATE', self.root/'target.json'), patch.object(migration.subprocess, 'run', return_value=response) as run:
            migration.database(state)
            self.assertEqual(run.call_count, 2)
            for call in run.call_args_list:
                self.assertNotIn(state['settings']['DB_PASSWORD'], ' '.join(call.args[0]))
            self.assertIn(state['settings']['DB_PASSWORD'], run.call_args.kwargs['input'])

    @unittest.skipUnless(shutil.which('gpg'), 'GPG nu este instalat in acest mediu')
    def test_encrypted_bundle_round_trip_and_wrong_password(self):
        home = self.root/'gnupg'; home.mkdir(mode=0o700)
        archive = self.bundle()
        encrypted = self.root/'migration.gpg'
        decrypted = self.root/'migration.tar.gz'
        flags = ['gpg', '--homedir', str(home), '--batch', '--quiet', '--no-symkey-cache', '--pinentry-mode', 'loopback', '--passphrase-fd', '0']
        secret = base64.b64encode(os.urandom(32)) + b'\n'
        subprocess.run(flags + ['--symmetric', '--cipher-algo', 'AES256', '--compress-algo', 'none', '--output', str(encrypted), str(archive)], input=secret, capture_output=True, check=True)
        wrong = subprocess.run(flags + ['--decrypt', '--output', str(decrypted), str(encrypted)], input=b'wrong-test-only\n', capture_output=True)
        self.assertNotEqual(wrong.returncode, 0)
        subprocess.run(flags + ['--yes', '--decrypt', '--output', str(decrypted), str(encrypted)], input=secret, capture_output=True, check=True)
        self.assertEqual(archive.read_bytes(), decrypted.read_bytes())

    def test_standalone_is_up_to_date_and_verifies_offline(self):
        script = ROOT/'deploy/bootstrap/migrare_finala_vaulty_24.04.sh'
        before = script.read_bytes()
        subprocess.run(['python3', str(ROOT/'deploy/bootstrap/build-migration.py')], capture_output=True, check=True)
        def canonical(content):
            header, payload = content.split(b'\n__VAULTY_ASSETS__\n', 1)
            return header, zlib.decompress(base64.b64decode(payload))
        self.assertEqual(canonical(before), canonical(script.read_bytes()), 'Regenereaza scriptul dupa schimbarea componentelor.')
        subprocess.run(['bash', '-n', str(script)], capture_output=True, check=True)
        subprocess.run(['bash', str(script), '--verify'], capture_output=True, check=True)
        result = subprocess.run(['bash', str(script), '--dry-run'], capture_output=True, check=True, text=True)
        self.assertIn('Nu modifica SSH/VPC', result.stdout)


if __name__ == '__main__':
    unittest.main()
