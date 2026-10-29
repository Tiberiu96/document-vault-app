#!/usr/bin/env bash
# Installer permanent solicitat pentru VM beta Vaulty. Nu este un utilitar cu secrete.
set -Eeuo pipefail
umask 077
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=l

readonly TAILWIND_VERSION='4.3.3'
readonly TAILWIND_SHA256='dc61b3ac6b8c9ca874c0cc4c57b2409791a64c5540404ca5f5367360babc313a'
readonly TAILWIND_TARGET='/usr/local/bin/vaulty-tailwindcss'
PACKAGES=(
  ca-certificates curl git unzip zip rsync acl jq tmux cron logrotate
  nginx mysql-server redis-server redis-tools composer
  php8.3-cli php8.3-fpm php8.3-common php8.3-opcache php8.3-mysql
  php8.3-xml php8.3-mbstring php8.3-curl php8.3-zip php8.3-gd
  php8.3-intl php8.3-bcmath php8.3-redis php8.3-sqlite3
  python3 tesseract-ocr tesseract-ocr-ron tesseract-ocr-eng tesseract-ocr-hun
  ocrmypdf poppler-utils qpdf ghostscript
  certbot python3-certbot-nginx restic
)
STAGE='initializare'
TEMP_DIR=''
trap 'printf "Instalare oprita la etapa: %s, linia %s. Nu continua deploy-ul.\n" "$STAGE" "$LINENO" >&2' ERR
trap 'if [[ -n "$TEMP_DIR" && -d "$TEMP_DIR" ]]; then rm -rf -- "$TEMP_DIR"; fi' EXIT

usage() {
  cat <<'TXT'
Vaulty - runtime Ubuntu 24.04 x86_64
  sudo bash install-ubuntu-24.04.sh          instaleaza si verifica
  bash install-ubuntu-24.04.sh --dry-run    afiseaza planul, fara modificari
  sudo bash install-ubuntu-24.04.sh --check verifica runtime-ul existent

Pentru VM noua. La rerulare poate reporni MySQL/Redis daca trebuie corectata configurarea.
Nu creeaza baza/utilizatorul Vaulty, .env, certificat, cont admin sau deploy-ul aplicatiei.
TXT
}

MODE="${1:-install}"
case "$MODE" in
  --help|-h) usage; exit 0 ;;
  --dry-run)
    usage
    printf '\nPachete: %s\n' "${PACKAGES[*]}"
    printf 'Tailwind standalone %s, SHA-256 verificat la instalare, in %s\n' "$TAILWIND_VERSION" "$TAILWIND_TARGET"
    printf 'MySQL/Redis: numai loopback. Redis AOF everysec si noeviction. Fara firewall sau SSH modificate.\n'
    exit 0 ;;
  install|--check) ;;
  *) usage >&2; exit 2 ;;
esac

STAGE='verificare sistem'
if [[ "$EUID" -ne 0 ]]; then
  printf 'Ruleaza cu sudo.\n' >&2; exit 1
fi
# shellcheck source=/etc/os-release
source /etc/os-release
if [[ "${ID:-}" != ubuntu || "${VERSION_ID:-}" != 24.04 || "$(uname -m)" != x86_64 ]]; then
  printf 'Scriptul suporta numai Ubuntu 24.04 LTS x86_64. Nu ruleaza pe alt sistem.\n' >&2; exit 1
fi
if [[ ! -d /run/systemd/system ]]; then
  printf 'Este necesara o VM cu systemd activ.\n' >&2; exit 1
fi

verify() {
  STAGE='verificare runtime'
  for binary in php8.3 composer nginx mysql mysqldump redis-server redis-cli python3 tesseract ocrmypdf pdftotext pdftoppm qpdf gs certbot restic tmux; do
    command -v "$binary" >/dev/null || { printf 'Lipseste: %s\n' "$binary" >&2; return 1; }
  done
  php8.3 -r '
    $required = ["ctype","curl","dom","fileinfo","filter","hash","mbstring","openssl","pcre","pdo","pdo_mysql","pdo_sqlite","session","tokenizer","xml","intl","bcmath","gd","zip","redis","pcntl","posix","Zend OPcache"];
    foreach ($required as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR,"Lipseste extensia PHP: ".$extension.PHP_EOL); exit(1); } }
    echo "PHP ".PHP_VERSION." si extensiile: OK".PHP_EOL;
  '
  for language in ron eng hun; do
    tesseract --list-langs 2>/dev/null | grep -Fxq "$language" || { printf 'Lipseste limba OCR: %s\n' "$language" >&2; return 1; }
  done
  /usr/sbin/php-fpm8.3 -t
  nginx -t
  for service in nginx php8.3-fpm mysql redis-server cron; do
    systemctl is-active --quiet "$service" || { printf 'Serviciu inactiv: %s\n' "$service" >&2; return 1; }
  done
  [[ "$(redis-cli -h 127.0.0.1 ping)" == PONG ]]
  python3 - <<'PY'
import subprocess
rows = subprocess.check_output(['ss', '-H', '-ltn'], text=True).splitlines()
for row in rows:
    address = row.split()[3]
    if address.rsplit(':', 1)[-1] in {'3306', '33060', '6379'}:
        host = address.rsplit(':', 1)[0].strip('[]')
        if host not in {'127.0.0.1', '::1'}:
            raise SystemExit('EROARE: MySQL/Redis asculta pe o adresa care nu este loopback.')
print('MySQL/Redis asculta numai local: OK')
PY
  [[ -x "$TAILWIND_TARGET" ]]
  printf '%s  %s\n' "$TAILWIND_SHA256" "$TAILWIND_TARGET" | sha256sum --check --status
  printf 'Unelte OCR, servicii si Tailwind: OK. Baza si credentialele aplicatiei NU sunt configurate.\n'
}

if [[ "$MODE" == --check ]]; then verify; exit 0; fi

if [[ -f /var/www/document-vault-app/artisan ]]; then
  printf 'Aplicatia exista deja pe acest sistem. Foloseste --check; installerul este pentru VM noua.\n' >&2; exit 1
fi
if command -v redis-cli >/dev/null && systemctl is-active --quiet redis-server; then
  if [[ "$(redis-cli -h 127.0.0.1 ping 2>/dev/null)" != PONG ]]; then
    printf 'Redis existent necesita autentificare/configurare separata. Instalare oprita.\n' >&2; exit 1
  fi
  if redis-cli --raw INFO keyspace | grep -Eq '^db[0-9]+:'; then
    printf 'Redis contine deja date. Nu ii modifica persistenta prin installerul pentru VM noua.\n' >&2; exit 1
  fi
fi
TEMP_DIR=$(mktemp -d /tmp/vaulty-bootstrap.XXXXXXXX)

STAGE='actualizare catalog pachete'
apt-get -o DPkg::Lock::Timeout=180 update
STAGE='instalare pachete oficiale Ubuntu'
apt-get -o DPkg::Lock::Timeout=180 install -y "${PACKAGES[@]}"

STAGE='instalare Tailwind standalone verificat'
if [[ ! -f "$TAILWIND_TARGET" ]] || ! printf '%s  %s\n' "$TAILWIND_SHA256" "$TAILWIND_TARGET" | sha256sum --check --status; then
  curl --proto '=https' --tlsv1.2 --fail --location --retry 3 --connect-timeout 20 --max-time 600 \
    "https://github.com/tailwindlabs/tailwindcss/releases/download/v${TAILWIND_VERSION}/tailwindcss-linux-x64" \
    --output "$TEMP_DIR/tailwindcss"
  printf '%s  %s\n' "$TAILWIND_SHA256" "$TEMP_DIR/tailwindcss" | sha256sum --check --status
  install -o root -g root -m 0755 "$TEMP_DIR/tailwindcss" "$TAILWIND_TARGET"
fi

STAGE='configurare servicii locale'
# Nu se afiseaza configuratii existente, care ar putea contine credentiale.
install -d -o root -g root -m 0700 /var/backups/vaulty-bootstrap
mysql_changed=0
mysql_config="$TEMP_DIR/mysql-loopback.cnf"
cat > "$mysql_config" <<'MYSQL'
# Gestionat de installerul Vaulty. Nu contine credentiale.
[mysqld]
bind-address = 127.0.0.1
mysqlx-bind-address = 127.0.0.1
MYSQL
if ! cmp -s "$mysql_config" /etc/mysql/mysql.conf.d/zz-vaulty-loopback.cnf; then
  if [[ -f /etc/mysql/mysql.conf.d/zz-vaulty-loopback.cnf ]]; then
    cp -p /etc/mysql/mysql.conf.d/zz-vaulty-loopback.cnf "/var/backups/vaulty-bootstrap/mysql.$(date -u +%Y%m%dT%H%M%S).cnf"
  fi
  install -o root -g root -m 0644 "$mysql_config" /etc/mysql/mysql.conf.d/zz-vaulty-loopback.cnf
  mysql_changed=1
fi
rm -f -- "$mysql_config"

redis_changed=$(python3 - <<'PY'
from pathlib import Path
from datetime import datetime, timezone
import os, re
path = Path('/etc/redis/redis.conf')
source = path.read_text()
settings = {'bind': '127.0.0.1 ::1', 'protected-mode': 'yes', 'appendonly': 'yes', 'appendfsync': 'everysec', 'maxmemory-policy': 'noeviction'}
result = source
for key, value in settings.items():
    pattern = r'^\s*' + re.escape(key) + r'\s+[^\n]*$'
    replacement = key + ' ' + value
    if re.search(pattern, result, re.M):
        result = re.sub(pattern, replacement, result, flags=re.M)
    else:
        result += '\n' + replacement + '\n'
if result != source:
    backup = Path('/var/backups/vaulty-bootstrap') / ('redis.' + datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%f') + '.conf')
    fd = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w') as file:
        file.write(source)
    path.write_text(result)
    print('1')
else:
    print('0')
PY
)
# Un drop-in pentru upload, fara rescrierea php.ini.
for sapi in cli fpm; do
  cat > "/etc/php/8.3/$sapi/conf.d/99-vaulty-runtime.ini" <<'INI'
; Gestionat de installerul Vaulty. Fara secrete.
expose_php = Off
upload_max_filesize = 5M
post_max_size = 6M
memory_limit = 256M
max_execution_time = 60
INI
  if [[ "$sapi" == cli ]]; then
    printf "max_execution_time = 0\n" >> "/etc/php/8.3/$sapi/conf.d/99-vaulty-runtime.ini"
  fi
done

STAGE='pornire servicii'
for service in mysql redis-server nginx php8.3-fpm cron; do
  systemctl enable --now "$service"
done
if [[ "$mysql_changed" == 1 ]]; then systemctl restart mysql; fi
if [[ "$redis_changed" == 1 ]]; then systemctl restart redis-server; fi
/usr/sbin/php-fpm8.3 -t
systemctl reload php8.3-fpm

verify
printf '\nInstalarea runtime-ului Vaulty s-a incheiat.\n'
printf 'Urmeaza: acces Git, clone, baza/utilizator MySQL dedicat, .env, domeniu/TLS, FPM/workeri dedicati si email.\n'
printf 'Systemd este disponibil pentru Horizon/Scheduler; nu este necesar Supervisor in paralel.\n'
printf 'Nu au fost pornite joburi Vaulty, backupuri externe sau apeluri AI.\n'
if [[ -f /var/run/reboot-required ]]; then
  printf 'Sistemul indica reboot necesar. Planifica repornirea inainte de lansare.\n'
fi
