#!/usr/bin/env python3
"""Configurare privata si validarea arhivei. Nu executa valori .env ca shell."""
import base64
import getpass
import hashlib
import ipaddress
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import shutil
import socket
import subprocess
import sys
import tarfile

ROOT = Path('/var/www/document-vault-app')
STATE = Path('/var/lib/vaulty-migration/target.json')


class MigrationError(Exception):
    pass


def private_json(path, value):
    temporary = path.with_suffix('.new')
    with temporary.open('w', encoding='utf-8') as stream:
        os.chmod(temporary, 0o600)
        json.dump(value, stream, ensure_ascii=False)
    temporary.replace(path)


def ask(label, default='', secret=False):
    text = label + (f' [{default}]' if default and not secret else '') + ': '
    value = (getpass.getpass(text) if secret else input(text)).strip()
    return value or default


def yes(label, default=False):
    answer = ask(label + ' (da/nu)', 'da' if default else 'nu').lower()
    if answer not in ('da', 'nu'):
        raise MigrationError('Raspunde numai da sau nu; reia etapa.')
    return answer == 'da'


def valid_domain(value):
    return bool(re.fullmatch(r'(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}', value))


def unpack(source, destination):
    """Fara extractall: refuza traversari, linkuri, dispozitive si duplicate."""
    with tarfile.open(source, 'r:gz') as archive:
        members = archive.getmembers()
        limit = shutil.disk_usage(destination).free - 536870912
        if sum(m.size for m in members) > limit:
            raise MigrationError('Spatiu insuficient pentru arhiva si rezerva de 512 MiB.')
        seen = set()
        for item in members:
            name = item.name.removeprefix('./').rstrip('/')
            path = PurePosixPath(name)
            if name in ('', '.') and item.isdir():
                continue
            if (name in seen or path.is_absolute() or '..' in path.parts or '\\' in name
                    or '\x00' in name or not (item.isdir() or item.isfile())):
                raise MigrationError('Arhiva contine o cale sau un tip de fisier nepermis.')
            if name not in ('manifest.json', 'settings.json', 'database.sql', 'recovery.json', 'documents') and not name.startswith('documents/'):
                raise MigrationError('Arhiva nu are formatul de migrare Vaulty.')
            seen.add(name)
        for item in members:
            name = item.name.removeprefix('./').rstrip('/')
            if name in ('', '.'):
                continue
            output = destination / name
            output.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            if item.isdir():
                output.mkdir(exist_ok=True, mode=0o700)
            else:
                with archive.extractfile(item) as src, output.open('xb') as dst:
                    os.chmod(output, 0o600)
                    shutil.copyfileobj(src, dst, 1024 * 1024)
    manifest = json.loads((destination / 'manifest.json').read_text())
    if manifest.get('format') != 1 or not re.fullmatch('[a-f0-9]{40}', manifest.get('commit', '')):
        raise MigrationError('Manifest incompatibil.')
    actual = {str(p.relative_to(destination)) for p in destination.rglob('*') if p.is_file()} - {'manifest.json'}
    if actual != set(manifest['files']):
        raise MigrationError('Lista fisierelor nu corespunde manifestului.')
    for name, info in manifest['files'].items():
        path = destination / name
        with path.open('rb') as stream:
            digest = hashlib.file_digest(stream, 'sha256').hexdigest()
        if path.stat().st_size != info['size'] or digest != info['sha256']:
            raise MigrationError('Verificarea hashurilor arhivei a esuat.')
    print('Arhiva decriptata si verificata; nu sunt afisate date private.')


def configure(stage):
    if STATE.exists():
        print('Se reutilizeaza configurarea privata a acestei instalari.')
        if yes('Corectezi domeniul, IP-ul sau emailul pentru certificat?'):
            state = json.loads(STATE.read_text())
            domain = ask('Domeniu public', state['domain']).lower()
            ip = str(ipaddress.IPv4Address(ask('IPv4 public', state['ip'])))
            email = ask('Email certificat', state['email'])
            if not valid_domain(domain) or not ipaddress.IPv4Address(ip).is_global or not re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+', email):
                raise MigrationError('Domeniu/IP/email invalid.')
            state.update({'domain': domain, 'ip': ip, 'email': email})
            state['settings'].update({'APP_URL': 'https://' + domain, 'TURNSTILE_HOSTNAMES': domain, 'MAIL_EHLO_DOMAIN': domain})
            private_json(STATE, state)
            for name in ('tls.done', 'renewal.done'):
                (STATE.parent/name).unlink(missing_ok=True)
        if yes('Corectezi configurarea unui provider extern?'):
            state = json.loads(STATE.read_text())
            fields = {
                'smtp': ('REMINDERS_EMAIL_ENABLED', ['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS']),
                'gemini': ('AI_ENABLED', ['GEMINI_MODEL', 'GEMINI_API_KEY']),
                'r2': ('BACKUP_ENABLED', ['BACKUP_R2_ACCOUNT_ID', 'BACKUP_R2_JURISDICTION', 'BACKUP_R2_BUCKET', 'BACKUP_R2_ACCESS_KEY_ID', 'BACKUP_R2_SECRET_ACCESS_KEY', 'BACKUP_RESTIC_PASSWORD', 'BACKUP_ALERT_EMAIL']),
                'turnstile': ('TURNSTILE_ENABLED', ['TURNSTILE_SITE_KEY', 'TURNSTILE_SECRET_KEY']),
            }
            provider = ask('Provider: smtp/gemini/r2/turnstile')
            if provider not in fields:
                raise MigrationError('Provider necunoscut.')
            enabled, names = fields[provider]
            for key in names:
                hidden = any(word in key for word in ('PASSWORD', 'KEY', 'SECRET'))
                state['settings'][key] = ask(key, str(state['settings'].get(key, '')), secret=hidden)
            state['settings'][enabled] = 'true' if yes('Activezi acest provider?', True) else 'false'
            if provider == 'smtp':
                state['settings'].update({'MAIL_MAILER': 'smtp', 'MAIL_SCHEME': 'smtp'})
            if provider == 'gemini':
                state['settings']['AI_PROVIDER'] = 'gemini'
            private_json(STATE, state)
        return
    imported = stage is not None
    settings = json.loads((stage / 'settings.json').read_text()) if imported else {}
    suggested = os.environ.get('SUDO_USER', '')
    if suggested in ('', 'root', 'vaulty'):
        suggested = ''
    operator = ask('Utilizator Linux existent pentru Git/deploy (nu root/vaulty)', suggested)
    if not re.fullmatch('[a-z_][a-z0-9_-]{0,31}', operator) or operator in ('root', 'vaulty'):
        raise MigrationError('Utilizator de deploy invalid.')
    import pwd
    user = pwd.getpwnam(operator)
    if user.pw_uid == 0 or not Path(user.pw_dir).is_dir() or not user.pw_shell.endswith(('bash', 'sh', 'zsh')):
        raise MigrationError('Contul trebuie sa aiba home si shell de autentificare.')
    domain = ask('Domeniul nou, fara https://').lower()
    if not valid_domain(domain):
        raise MigrationError('Domeniu invalid; este necesar un domeniu public pentru certificat.')
    ip = str(ipaddress.IPv4Address(ask('IP-ul IPv4 public al serverului nou')))
    if not ipaddress.IPv4Address(ip).is_global:
        raise MigrationError('Este necesar un IP IPv4 public, nu IP-ul intern al VM-ului.')
    email = ask('Email pentru expirarea certificatului TLS')
    if not re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+', email):
        raise MigrationError('Email invalid.')
    db = ask('Baza MySQL noua', 'vaulty')
    db_user = ask('Utilizator MySQL nou', 'vaulty_app')
    if not re.fullmatch('[a-z][a-z0-9_]{0,47}', db) or not re.fullmatch('[a-z][a-z0-9_]{0,31}', db_user):
        raise MigrationError('Nume MySQL invalid.')
    settings.update({
        'APP_NAME': settings.get('APP_NAME') or 'Vaulty', 'APP_ENV': 'production',
        'APP_MAINTENANCE_DRIVER': 'file', 'APP_DEBUG': 'false', 'APP_URL': 'https://' + domain, 'APP_LOCALE': 'ro', 'APP_FALLBACK_LOCALE': 'ro',
        'APP_KEY': settings.get('APP_KEY') if imported else 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
        'DB_CONNECTION': 'mysql', 'DB_URL': '', 'DB_HOST': '127.0.0.1', 'DB_PORT': '3306',
        'DB_DATABASE': db, 'DB_USERNAME': db_user, 'DB_PASSWORD': secrets.token_hex(32), 'DB_SOCKET': '',
        'REDIS_CLIENT': 'phpredis', 'REDIS_HOST': '127.0.0.1', 'REDIS_PORT': '6379',
        'REDIS_USERNAME': '', 'REDIS_PASSWORD': '', 'REDIS_URL': '', 'REDIS_DB': '0', 'REDIS_CACHE_DB': '1',
        'CACHE_STORE': 'redis', 'QUEUE_CONNECTION': 'redis', 'REDIS_QUEUE_RETRY_AFTER': '480',
        'SESSION_DRIVER': 'redis', 'SESSION_CONNECTION': 'default', 'SESSION_DOMAIN': '',
        'SESSION_SECURE_COOKIE': 'true', 'SESSION_HTTP_ONLY': 'true', 'SESSION_SAME_SITE': 'lax',
        'MAIL_FROM_ADDRESS': settings.get('MAIL_FROM_ADDRESS') or ('no-reply@' + domain),
        'MAIL_FROM_NAME': settings.get('APP_NAME') or 'Vaulty',
        'FILESYSTEM_DISK': 'local', 'LOG_CHANNEL': 'stack', 'LOG_LEVEL': 'warning',
        'OCR_ENABLED': 'true', 'TESSERACT_BINARY': 'tesseract', 'OCRMYPDF_BINARY': 'ocrmypdf',
        'PDFTOTEXT_BINARY': 'pdftotext', 'PDFTOPPM_BINARY': 'pdftoppm', 'QPDF_BINARY': 'qpdf',
        'REMINDERS_ENABLED': 'true', 'VAULT_ANALYTICS_ENABLED': 'false', 'VAULT_PUSH_ENABLED': 'false',
        'BACKUP_SINGLE_HOST_CONFIRMED': 'true', 'BACKUP_RESTORE_ENABLED': 'true',
        'BACKUP_RESTORE_DEDICATED_REDIS': 'true', 'BACKUP_RESTIC_BINARY': 'restic',
        'BACKUP_MYSQL_BINARY': 'mysql', 'BACKUP_MYSQLDUMP_BINARY': 'mysqldump',
    })
    key = settings.get('APP_KEY', '')
    if not key.startswith('base64:') or len(base64.b64decode(key[7:], validate=True)) != 32:
        raise MigrationError('APP_KEY lipsa sau invalida; nu genera alta cheie pentru datele migrate.')
    keep = imported and yes('Pastram configurarile SMTP/AI/R2/Turnstile din export?', True)
    if not keep:
        settings.update({'MAIL_MAILER': 'array', 'REMINDERS_EMAIL_ENABLED': 'false',
                         'AI_ENABLED': 'false', 'BACKUP_ENABLED': 'false', 'TURNSTILE_ENABLED': 'false'})
        if yes('Configurezi SMTP acum?'):
            print('Brevo: smtp-relay.brevo.com, port 587, login SMTP si cheia SMTP (nu parola contului).')
            settings.update({'MAIL_MAILER': 'smtp', 'MAIL_SCHEME': 'smtp',
                             'MAIL_HOST': ask('Server SMTP', 'smtp-relay.brevo.com'),
                             'MAIL_PORT': ask('Port SMTP', '587'), 'MAIL_USERNAME': ask('Login SMTP'),
                             'MAIL_PASSWORD': ask('Cheie/parola SMTP, ascunsa', secret=True),
                             'MAIL_FROM_ADDRESS': ask('Adresa expeditor verificata la provider'),
                             'MAIL_FROM_NAME': settings['APP_NAME'], 'REMINDERS_EMAIL_ENABLED': 'true'})
        if yes('Configurezi Gemini acum? (necesita cont si cheie API)'):
            print('Textul documentelor poate fi trimis extern; verifica planul si conditiile de confidentialitate.')
            settings.update({'AI_ENABLED': 'true', 'AI_PROVIDER': 'gemini',
                             'GEMINI_MODEL': ask('Model Gemini disponibil in cont', 'gemini-3.5-flash-lite'),
                             'GEMINI_API_KEY': ask('Cheie Gemini, ascunsa', secret=True)})
        if yes('Configurezi backup Restic in R2 acum?'):
            print('R2: bucket privat dedicat, preferabil jurisdictia EU; costuri posibile peste limitele planului.')
            settings.update({'BACKUP_ENABLED': 'true', 'BACKUP_R2_ACCOUNT_ID': ask('Account ID Cloudflare'),
                             'BACKUP_R2_JURISDICTION': ask('Jurisdictie R2: eu/default', 'eu'),
                             'BACKUP_R2_BUCKET': ask('Bucket R2'),
                             'BACKUP_R2_ACCESS_KEY_ID': ask('Access Key ID R2, ascuns', secret=True),
                             'BACKUP_R2_SECRET_ACCESS_KEY': ask('Secret Access Key R2, ascuns', secret=True),
                             'BACKUP_RESTIC_PASSWORD': ask('Parola repository Restic (existenta la migrare), ascunsa', secret=True),
                             'BACKUP_ALERT_EMAIL': ask('Email pentru alerte backup', email)})
            if len(settings['BACKUP_RESTIC_PASSWORD']) < 24 or settings['BACKUP_R2_JURISDICTION'] not in ('eu', 'default'):
                raise MigrationError('Parola Restic trebuie sa aiba minimum 24 caractere; jurisdictie eu/default.')
        if yes('Configurezi Turnstile acum? (widget creat pentru noul domeniu)'):
            settings.update({'TURNSTILE_ENABLED': 'true', 'TURNSTILE_SITE_KEY': ask('Site key Turnstile'),
                             'TURNSTILE_SECRET_KEY': ask('Secret key Turnstile, ascuns', secret=True)})
    settings['TURNSTILE_HOSTNAMES'] = domain
    settings['MAIL_EHLO_DOMAIN'] = domain
    state = {'operator': operator, 'home': user.pw_dir, 'domain': domain, 'ip': ip, 'email': email,
             'imported': imported, 'commit': json.loads((stage/'manifest.json').read_text())['commit'] if imported else '',
             'settings': settings}
    STATE.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    private_json(STATE, state)
    print('Configurarea a fost salvata privat; credentialele nu sunt afisate.')


def write_env(state):
    data = state['settings']
    def quote(value):
        text = str(value or '')
        if '\n' in text or '\r' in text or '\x00' in text:
            raise MigrationError('Valorile .env trebuie sa fie pe o singura linie.')
        return '"' + text.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$') + '"'
    result = ''.join(k + '=' + quote(v) + '\n' for k, v in data.items() if re.fullmatch('[A-Z][A-Z0-9_]*', k))
    path = ROOT / '.env'
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as stream:
        os.fchmod(stream.fileno(), 0o600)
        stream.write(result)


def database(state):
    env = state['settings']
    db, user, password = (env[k] for k in ['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'])
    marker = STATE.parent / 'database-created'
    if not marker.exists():
        query = f"SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{db}'; SELECT User FROM mysql.user WHERE User='{user}';"
        found = subprocess.run(['mysql', '--batch', '--skip-column-names'], input=query, text=True, capture_output=True, check=True)
        if found.stdout.strip():
            raise MigrationError('Baza sau utilizatorul MySQL exista deja; nu sunt suprascrise.')
        marker.touch(mode=0o600)
    # Numele sunt validate; parola generata este exclusiv hex. Nu exista interpolare de secrete arbitrare.
    query = (f"CREATE DATABASE IF NOT EXISTS `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
             f"CREATE USER IF NOT EXISTS '{user}'@'127.0.0.1' IDENTIFIED BY '{password}';"
             f"ALTER USER '{user}'@'127.0.0.1' IDENTIFIED BY '{password}';"
             f"GRANT ALL PRIVILEGES ON `{db}`.* TO '{user}'@'127.0.0.1';")
    subprocess.run(['mysql', '--batch'], input=query, text=True, capture_output=True, check=True)
    print('Baza/utilizatorul MySQL dedicat sunt configurate; parola este numai in configuratia privata.')


def main():
    mode = sys.argv[1]
    if mode == 'unpack':
        unpack(Path(sys.argv[2]), Path(sys.argv[3])); return
    if mode == 'configure':
        configure(Path(sys.argv[2]) if len(sys.argv) > 2 else None); return
    state = json.loads(STATE.read_text())
    if mode == 'get':
        key = sys.argv[2]
        if key not in ('operator', 'home', 'domain', 'ip', 'email', 'imported', 'commit'):
            raise MigrationError('Camp nepublic refuzat.')
        print(str(state[key]).lower() if isinstance(state[key], bool) else state[key])
    elif mode == 'enabled':
        if sys.argv[2] not in ('BACKUP_ENABLED', 'AI_ENABLED', 'TURNSTILE_ENABLED', 'REMINDERS_EMAIL_ENABLED'):
            raise MigrationError('Camp refuzat.')
        sys.exit(0 if str(state['settings'].get(sys.argv[2], '')).lower() == 'true' else 1)
    elif mode == 'env':
        write_env(state)
    elif mode == 'database':
        database(state)
    elif mode == 'dns':
        ipv4 = {r[4][0] for r in socket.getaddrinfo(state['domain'], 80, socket.AF_INET)}
        if ipv4 != {state['ip']}:
            raise MigrationError('DNS A nu indica exclusiv IP-ul nou. Actualizeaza DNS si asteapta propagarea.')
        try:
            socket.getaddrinfo(state['domain'], 80, socket.AF_INET6)
        except socket.gaierror:
            print('DNS A verificat.'); return
        raise MigrationError('Exista si AAAA; elimina-l daca serverul nu este configurat pentru IPv6 public.')
    elif mode == 'helpers':
        assets = Path(sys.argv[2])
        text = (assets/'deploy-vaulty.sh').read_text()
        text = text.replace('operator=szabotiberiuandrei2020', 'operator=' + state['operator'])
        text = text.replace('health=https://vaulty-beta.duckdns.org/up', 'health=https://' + state['domain'] + '/up')
        path = Path('/usr/local/libexec/vaulty-deploy')
        path.write_text(text); path.chmod(0o700)
    else:
        raise MigrationError('Operatie necunoscuta.')


if __name__ == '__main__':
    os.umask(0o077)
    try:
        main()
    except MigrationError as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
    except Exception:
        # Nu afisa exceptii: subprocess/JSON pot contine credentiale sau nume private.
        print('Etapa de configurare/validare a esuat. Verifica raspunsurile, DNS si starea privata; nu trimite secrete in chat.', file=sys.stderr)
        sys.exit(1)
