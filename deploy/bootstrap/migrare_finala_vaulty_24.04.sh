#!/usr/bin/env bash
# Generatorul adauga dependintele verificate la final: un singur fisier de transferat.
set +x
set -Eeuo pipefail
umask 077
ROOT=/var/www/document-vault-app
STATE=/var/lib/vaulty-migration
REPO=https://github.com/Tiberiu96/document-vault-app.git
STAGE=initializare
WORK=''
MODE=${1:---help}
TARGET_MANAGED=false
cleanup() {
    rc=$?
    if (( rc != 0 )); then
        printf '\nOPRIT la etapa: %s. Nu sunt afisate credentiale.\n' "$STAGE" >&2
        printf 'Nu scoate mentenanta fara verificare. Reia numai prin --resume sau --resume-source, dupa caz.\n' >&2
        if $TARGET_MANAGED && [[ -f "$ROOT/artisan" && -f "$ROOT/.env" && -f "$ROOT/vendor/autoload.php" ]]; then
            /usr/sbin/runuser -u vaulty -- /usr/bin/php "$ROOT/artisan" down --no-interaction >/dev/null 2>&1 || true
            for unit in vaulty-horizon.service vaulty-scheduler.service vaulty-backup.timer vaulty-backup-monitor.timer vaulty-restore.path; do
                systemctl disable --now "$unit" >/dev/null 2>&1 || true
            done
            systemctl stop php8.3-fpm.service >/dev/null 2>&1 || true
        fi
    fi
    if [[ -n "$WORK" && -d "$WORK" ]]; then rm -rf -- "$WORK"; fi
}
trap cleanup EXIT
die() { printf '%s\n' "$1" >&2; exit 1; }
confirm() {
    local answer
    read -r -p "$1 [da/nu, implicit nu]: " answer </dev/tty
    [[ "$answer" == da ]]
}
usage() {
    cat <<'TXT'
Vaulty - migrare finala Ubuntu 24.04 LTS x86_64

  bash migrare_finala_vaulty_24.04.sh --dry-run
  bash migrare_finala_vaulty_24.04.sh --verify
  sudo bash migrare_finala_vaulty_24.04.sh --export
  sudo bash migrare_finala_vaulty_24.04.sh --install
  sudo bash migrare_finala_vaulty_24.04.sh --resume
  sudo bash migrare_finala_vaulty_24.04.sh --resume-source

EXPORT (pe sursa): pune aplicatia in mentenanta, opreste workeri/timere si creeaza
o arhiva GPG criptata cu DB, documente, configurare si SHA-ul codului. Sursa ramane
oprita, inclusiv dupa reboot; nu mai accepta scrieri in paralel cu destinatia.

INSTALL (numai VM noua): instalare completa, configurare interactiva, optional import
din export. Pastreaza APP_KEY si MFA, genereaza credentiale MySQL noi. Nginx HTTP/2,
TLS Certbot, PHP-FPM, Horizon, Scheduler, Restic/R2, timere si CD optional.
Redis/sesiunile/cozile nu se transfera. Joburile nefinalizate necesita reexaminare;
emailurile aflate in coada trebuie retrimise explicit. Reminderele viitoare raman.

RESUME: numai o instalare inceputa de acest script. Nu ruleaza peste alt proiect.
RESUME-SOURCE: numai daca destinatia este oprita; necesita confirmare explicita.
DRY-RUN/VERIFY nu instaleaza si nu modifica servicii sau proiectul.

Necesita root pentru operatii reale, internet, VM dedicata, operator SSH existent,
DNS administrabil si acces public pe 80/443 (plus 22 pentru CD). Nu modifica SSH/VPC.
Secretele sunt cerute ascuns si salvate numai in fisiere private. Nu rula cu bash -x.
TXT
}
[[ $# -le 1 ]] || die 'Foloseste un singur mod: --install, --export, --resume, --resume-source, --verify sau --dry-run.'
case "$MODE" in
    --help|-h) usage; exit 0 ;;
    --dry-run) usage; printf '\nPlan: runtime -> Git/SHA -> .env/DB -> import -> FPM/Nginx/TLS -> servicii -> backup -> CD optional.\n'; exit 0 ;;
    --verify|--export|--install|--resume|--resume-source) ;;
    *) usage; exit 2 ;;
esac
command -v python3 >/dev/null || die 'Lipseste python3. Pe Ubuntu: sudo apt install python3'
WORK=$(mktemp -d /var/tmp/vaulty-migration.XXXXXXXX)
export VAULTY_MIGRATION_SCRIPT
VAULTY_MIGRATION_SCRIPT=$(readlink -f -- "$0")
python3 - "$WORK" <<'PY'
import base64, hashlib, json, os, pathlib, sys, zlib
raw = pathlib.Path(os.environ['VAULTY_MIGRATION_SCRIPT']).read_bytes()
data = json.loads(zlib.decompress(base64.b64decode(raw.split(b'\n__VAULTY_ASSETS__\n', 1)[1])))
root = pathlib.Path(sys.argv[1])
for name, item in data.items():
    if pathlib.PurePosixPath(name).name != name:
        raise SystemExit('Asset nepermis.')
    content = base64.b64decode(item['data'])
    if hashlib.sha256(content).hexdigest() != item['sha256']:
        raise SystemExit('Asset corupt.')
    (root/name).write_bytes(content)
PY
if [[ "$MODE" == --verify ]]; then
    bash -n "$WORK/runtime.sh"
    bash -n "$WORK/deploy-vaulty.sh"
    bash -n "$WORK/restore-vaulty.sh"
    if command -v php >/dev/null; then php -l "$WORK/data.php" >/dev/null; fi
    python3 - "$WORK/configure.py" <<'PY'
import pathlib, sys
compile(pathlib.Path(sys.argv[1]).read_text(), sys.argv[1], 'exec')
PY
    printf 'Integritatea componentelor si sintaxa Bash/Python: OK. Nu a fost executata instalarea.\n'
    exit 0
fi
[[ $EUID -eq 0 ]] || die 'Ruleaza operatia reala cu sudo.'
source /etc/os-release
[[ "${ID:-}" == ubuntu && "${VERSION_ID:-}" == 24.04 && "$(uname -m)" == x86_64 ]] || die 'Suportat: Ubuntu 24.04 LTS x86_64.'
[[ -d /run/systemd/system && -r /dev/tty ]] || die 'Necesita systemd si terminal interactiv.'
exec 7>/run/vaulty-migration.lock
flock -n 7 || die 'Alta migrare este in curs.'
install -d -m 0700 "$STATE"
cfg() { python3 "$WORK/configure.py" "$@"; }
php_app() { /usr/sbin/runuser -u vaulty -- /usr/bin/php "$ROOT/artisan" "$@"; }
as_operator() { /usr/sbin/runuser -u "$operator" -- /bin/bash -c 'umask 022; exec "$@"' bash env HOME="$operator_home" "$@"; }
data_app() { /usr/sbin/runuser -u vaulty -- /usr/bin/php "$WORK/data.php" "$@"; }
grant_work() {
    setfacl -m u:vaulty:rx "$WORK"
    chmod 0644 "$WORK/data.php"
}
pause_units() {
    for unit in vaulty-backup.timer vaulty-backup-monitor.timer vaulty-restore.path; do
        if systemctl is-enabled --quiet "$unit" 2>/dev/null; then printf '%s\n' "$unit" >> "$STATE/source-enabled"; fi
        systemctl disable --now "$unit" >/dev/null 2>&1 || true
    done
    for unit in vaulty-backup.service vaulty-backup-monitor.service vaulty-restore.service; do
        active=$(systemctl show "$unit" --property=ActiveState --value)
        [[ "$active" == inactive || "$active" == failed ]] || die 'Backup/restaurare activa. Asteapta finalizarea inainte de migrare.'
    done
    php_app down --no-interaction
    for unit in vaulty-scheduler.service vaulty-horizon.service; do
        if systemctl is-enabled --quiet "$unit"; then printf '%s\n' "$unit" >> "$STATE/source-enabled"; fi
        systemctl disable --now "$unit"
    done
    systemctl stop php8.3-fpm.service
}
if [[ "$MODE" == --resume-source ]]; then
    [[ -f "$STATE/source-paused" && -f "$STATE/source-enabled" ]] || die 'Nu exista o sursa oprita de acest script.'
    [[ ! -e "$ROOT/storage/app/backup-control/restore/incomplete" && ! -e "$ROOT/storage/app/backup-control/restore/request.json" ]] || die 'Exista o restaurare nefinalizata; nu reporni automat sursa.'
    exec 9>/run/vaulty-deploy.lock
    flock -n 9 || die 'Deploy in curs.'
    exec 8>/run/vaulty-restore.lock
    flock -n 8 || die 'Restaurare in curs.'
    printf 'Asigura-te ca destinatia este oprita si DNS nu mai trimite utilizatori acolo.\n'
    read -r -p 'Scrie REIA SURSA pentru a redeschide serverul vechi: ' answer </dev/tty
    [[ "$answer" == 'REIA SURSA' ]] || die 'Reluare anulata.'
    php_app vault:services:check
    php_app up
    systemctl start php8.3-fpm.service
    while IFS= read -r unit; do
        case "$unit" in vaulty-horizon.service|vaulty-scheduler.service|vaulty-backup.timer|vaulty-backup-monitor.timer|vaulty-restore.path) systemctl enable --now "$unit" ;; *) die 'Lista de servicii invalida.' ;; esac
    done < "$STATE/source-enabled"
    mv "$STATE/source-paused" "$STATE/source-resumed-$(date +%s)"
    printf 'Sursa a fost redeschisa. Nu reporni simultan destinatia.\n'
    exit 0
fi
ensure_gpg() {
    if ! command -v gpg >/dev/null; then
        apt-get -o DPkg::Lock::Timeout=180 update
        apt-get -o DPkg::Lock::Timeout=180 install -y gnupg
    fi
}
secret_password() {
    local again
    read -r -s -p 'Parola arhivei de migrare (minimum 24 caractere): ' archive_password </dev/tty; printf '\n'
    [[ ${#archive_password} -ge 24 ]] || die 'Parola este prea scurta.'
    if [[ "$1" == create ]]; then
        read -r -s -p 'Confirma parola arhivei: ' again </dev/tty; printf '\n'
        [[ "$archive_password" == "$again" ]] || die 'Parolele nu coincid.'
    fi
}
if [[ "$MODE" == --export ]]; then
    STAGE='export: verificare sursa'
    [[ -f "$ROOT/artisan" && -f "$ROOT/.env" && ! -L "$ROOT" ]] || die 'Sursa Vaulty nu este disponibila.'
    [[ ! -e "$STATE/source-paused" ]] || die 'Sursa este deja oprita; verifica arhiva existenta sau foloseste --resume-source.'
    operator=$(stat -c %U "$ROOT")
    [[ "$operator" != root && "$operator" != vaulty ]] || die 'Directorul proiectului trebuie sa apartina operatorului de deploy.'
    operator_home=$(getent passwd "$operator" | cut -d: -f6)
    cd "$ROOT"
    [[ -z "$(as_operator git status --porcelain)" ]] || die 'Git contine modificari locale; rezolva-le inainte de export.'
    sha=$(as_operator git rev-parse HEAD)
    [[ "$sha" =~ ^[a-f0-9]{40}$ ]]
    [[ ! -f storage/framework/down && ! -f storage/app/backup-control/restore/incomplete && ! -f storage/app/backup-control/restore/request.json ]] || die 'Exista mentenanta sau restaurare nefinalizata.'
    grant_work
    data_app preflight
    ensure_gpg
    secret_password create
    if command -v gh >/dev/null && as_operator gh auth status --hostname github.com >/dev/null 2>&1 && confirm 'Oprim acum CD in GitHub pentru perioada migrarii?'; then
        as_operator gh variable set AUTO_DEPLOY_ENABLED --repo Tiberiu96/document-vault-app --body false
    fi
    printf 'AUTO_DEPLOY_ENABLED trebuie sa fie false in GitHub pentru perioada migrarii.\n'
    confirm 'Ai oprit CD si accepti mentenanta pe sursa pana la mutarea DNS?' || die 'Export anulat.'
    exec 9>/run/vaulty-deploy.lock
    flock -n 9 || die 'Deploy in curs.'
    exec 8>/run/vaulty-restore.lock
    flock -n 8 || die 'Restaurare in curs.'
    : > "$STATE/source-enabled"
    touch "$STATE/source-paused"
    pause_units
    grant_work
    install -d -o vaulty -g vaulty -m 0700 "$WORK/stage"
    STAGE='export: snapshot consistent DB/documente'
    data_app export "$WORK/stage" "$sha"
    chown -R root:root "$WORK/stage"
    pending=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["pending_jobs"])' "$WORK/stage/manifest.json")
    if (( pending > 0 )); then
        printf 'Exista %s joburi in Redis. Cozile nu se transfera; emailurile necesita retrimitere si procesarile reexaminare.\n' "$pending"
        confirm 'Accepti aceasta reconciliere pe destinatie?' || die 'Sursa ramane in mentenanta; foloseste --resume-source daca renunti la migrare.'
    fi
    install -d -m 0700 /var/backups/vaulty-migration
    output="/var/backups/vaulty-migration/vaulty-$(date -u +%Y%m%dT%H%M%SZ)-${sha:0:12}.tar.gz.gpg"
    STAGE='export: criptare arhiva'
    tar -C "$WORK/stage" -czf - . | gpg --batch --quiet --no-symkey-cache --pinentry-mode loopback --passphrase-fd 3 --symmetric --cipher-algo AES256 --compress-algo none --output "$output" 3<<<"$archive_password"
    unset archive_password
    chmod 0600 "$output"
    setfacl -m "u:$operator:rx" /var/backups/vaulty-migration
    setfacl -m "u:$operator:r--" "$output"
    printf '\nExport criptat: %s\n' "$output"
    sha256sum "$output"
    printf 'Copiaza arhiva privat pe destinatie (SCP). Pastreaza parola separat, in afara serverului.\n'
    printf 'Sursa ramane in mentenanta; workeri/timere/restore sunt dezactivate. Nu o redeschide dupa activarea destinatiei.\n'
    exit 0
fi

STAGE='verificare destinatie'
[[ ! -L "$ROOT" ]] || die 'Checkout-ul destinatiei nu poate fi symlink.'
if [[ "$MODE" == --install ]]; then
    [[ ! -e "$STATE/target.json" && ! -e "$ROOT/artisan" ]] || die 'Exista deja o instalare. --resume este permis numai pentru instalarea creata de acest script.'
    [[ ! -d "$ROOT" || -z "$(ls -A "$ROOT")" ]] || die 'Directorul aplicatiei nu este gol.'
    [[ ! -e /etc/nginx/sites-available/vaulty && ! -e /etc/php/8.3/fpm/pool.d/vaulty.conf ]] || die 'Exista deja configuratii Vaulty; nu sunt suprascrise.'
    confirm 'Confirmi ca aceasta VM este noua si dedicata exclusiv Vaulty?' || die 'Instalare anulata.'
else
    [[ -f "$STATE/target.json" ]] || die 'Nu exista o instalare proprie de reluat.'
    [[ ! -e "$STATE/finished.done" ]] || die 'Instalarea este deja finalizata. Foloseste CD pentru actualizari, nu rerula migrarea.'
fi
exec 9>/run/vaulty-deploy.lock
flock -n 9 || die 'Deploy in curs.'
exec 8>/run/vaulty-restore.lock
flock -n 8 || die 'Restaurare in curs.'
if [[ "$MODE" == --resume && -f "$ROOT/artisan" && -f "$ROOT/.env" ]]; then
    confirm 'Reluam instalarea proprie si punem destinatia in mentenanta?' || die 'Reluare anulata.'
    [[ ! -e "$ROOT/storage/app/backup-control/restore/incomplete" && ! -e "$ROOT/storage/app/backup-control/restore/request.json" ]] || die 'Restaurare nefinalizata.'
    for unit in vaulty-backup.service vaulty-backup-monitor.service; do
        active=$(systemctl show "$unit" --property=ActiveState --value)
        [[ "$active" == inactive || "$active" == failed ]] || die 'Backup/monitor in curs; asteapta finalizarea.'
    done
    for unit in vaulty-backup.timer vaulty-backup-monitor.timer vaulty-restore.path; do systemctl stop "$unit" 2>/dev/null || true; done
    php_app down
    for unit in vaulty-horizon.service vaulty-scheduler.service; do systemctl stop "$unit" 2>/dev/null || true; done
    systemctl stop php8.3-fpm.service
fi
ensure_gpg
imported=false
if [[ "$MODE" == --resume ]]; then
    imported=$(cfg get imported)
else
    if confirm 'Importi arhiva criptata a serverului vechi?'; then imported=true; fi
fi
if $imported && [[ ! -f "$STATE/data.done" ]]; then
    read -r -p 'Calea absoluta a arhivei .tar.gz.gpg de pe acest server: ' archive </dev/tty
    [[ "$archive" == /* && -f "$archive" && ! -L "$archive" ]] || die 'Arhiva nu exista sau calea nu este acceptata.'
    secret_password open
    STAGE='decriptare si verificare export'
    gpg --batch --quiet --no-symkey-cache --pinentry-mode loopback --passphrase-fd 3 --decrypt --output "$WORK/archive.tar.gz" "$archive" 3<<<"$archive_password"
    unset archive_password
    install -d -m 0700 "$WORK/stage"
    cfg unpack "$WORK/archive.tar.gz" "$WORK/stage"
    confirm 'Serverul vechi ramane oprit, inclusiv CD/workeri/timere?' || die 'Nu continua cu doua servere active pe aceleasi date.'
    cfg configure "$WORK/stage"
else
    cfg configure
fi
operator=$(cfg get operator)
operator_home=$(cfg get home)
domain=$(cfg get domain)
public_ip=$(cfg get ip)
email=$(cfg get email)
TARGET_MANAGED=true
checkpoint() { touch "$STATE/$1.done"; }

STAGE='runtime Ubuntu'
if [[ ! -f "$STATE/runtime.done" ]]; then
    bash "$WORK/runtime.sh"
    checkpoint runtime
fi
apt-get -o DPkg::Lock::Timeout=180 install -y gnupg gh
if ! id vaulty >/dev/null 2>&1; then useradd --system --user-group --home-dir /nonexistent --shell /usr/sbin/nologin vaulty; fi
[[ "$(id -u vaulty)" != 0 && "$(getent passwd vaulty | cut -d: -f7)" == /usr/sbin/nologin ]] || die 'Cont runtime vaulty nepotrivit.'
grant_work
STAGE='checkout Git al versiunii exacte'
if [[ ! -d "$ROOT/.git" ]]; then
    install -d -o "$operator" -g "$operator" -m 0755 "$ROOT"
    printf 'Repository: %s\n' "$REPO"
    if ! as_operator env GIT_TERMINAL_PROMPT=0 git ls-remote "$REPO" HEAD >/dev/null 2>&1; then
        confirm 'Git nu poate citi repository-ul. Configurezi acces GitHub cu autentificare web?' || die 'Verifica reteaua sau accesul la repository, apoi reia cu --resume.'
        as_operator gh auth login --hostname github.com --git-protocol https --web
        as_operator gh auth setup-git --hostname github.com
    fi
    as_operator env GIT_TERMINAL_PROMPT=0 git clone --no-checkout "$REPO" "$ROOT"
    if $imported; then
        sha=$(cfg get commit)
        as_operator git -C "$ROOT" checkout -B main "$sha"
    else
        as_operator git -C "$ROOT" checkout main
    fi
fi
cd "$ROOT"
[[ "$(as_operator git remote get-url origin)" == "$REPO" && "$(as_operator git branch --show-current)" == main ]] || die 'Checkout Git diferit de cel configurat.'
if $imported; then [[ "$(as_operator git rev-parse HEAD)" == "$(cfg get commit)" ]] || die 'SHA-ul exportului nu corespunde codului instalat.'; fi
STAGE='dependinte si permisiuni'
as_operator composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts
as_operator composer check-platform-reqs --no-dev
for path in storage storage/app storage/app/private storage/framework storage/framework/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap bootstrap/cache; do
    [[ ! -L "$path" ]] || die 'Director runtime nepermis: nu se urmeaza symlink-uri la configurarea permisiunilor.'
done
install -d -o "$operator" -g vaulty -m 2770 storage storage/app storage/app/private storage/framework storage/framework/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
setfacl -R -P -m "u:vaulty:rwx,u:$operator:rwx,m::rwx" storage bootstrap/cache
setfacl -R -P -d -m "u:vaulty:rwx,u:$operator:rwx,m::rwx" storage bootstrap/cache
cfg env
chown "$operator:$operator" .env
setfacl -m u:vaulty:r-- .env
as_operator mkdir -p .tools
if [[ ! -e .tools/tailwindcss ]]; then as_operator ln -s /usr/local/bin/vaulty-tailwindcss .tools/tailwindcss; fi
as_operator composer css:build
STAGE='MySQL si configurare Laravel'
cfg database
php_app config:clear
php_app route:clear
php_app clear-compiled
php_app view:clear
php_app package:discover
php_app down
php_app migrate --force
if [[ ! -f "$STATE/data.done" ]]; then
    if $imported; then
        chown -R vaulty:vaulty "$WORK/stage"
        data_app import "$WORK/stage"
    else
        php_app db:seed --force
    fi
    checkpoint data
fi
php_app vault:services:check
php_app config:cache
php_app route:cache
php_app view:cache

STAGE='pool PHP-FPM dedicat'
cat > /etc/php/8.3/fpm/pool.d/vaulty.conf <<'FPM'
[vaulty]
user = vaulty
group = vaulty
listen = /run/php/php8.3-fpm-vaulty.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
pm.max_requests = 500
chdir = /var/www/document-vault-app
clear_env = yes
security.limit_extensions = .php
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /var/www/document-vault-app/storage/logs/php-fpm.log
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 60
php_admin_value[upload_max_filesize] = 5M
php_admin_value[post_max_size] = 6M
FPM
chmod 0644 /etc/php/8.3/fpm/pool.d/vaulty.conf
php-fpm8.3 -t

STAGE='Nginx si certificat TLS'
install -d -o root -g root -m 0755 /var/www/letsencrypt
if [[ ! -f "$STATE/tls.done" ]]; then
    cat > /etc/nginx/sites-available/vaulty <<NGINX
server {
    listen 80;
    server_name $domain;
    server_tokens off;
    access_log off;
    error_log /dev/null crit;
    location ^~ /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { return 503; }
}
NGINX
    chmod 0644 /etc/nginx/sites-available/vaulty
    ln -sfn /etc/nginx/sites-available/vaulty /etc/nginx/sites-enabled/vaulty
    nginx -t
    systemctl reload nginx
    printf 'Configureaza DNS A pentru %s -> %s. Permite 80/443 in firewall/VPC.\n' "$domain" "$public_ip"
    while ! cfg dns; do
        confirm 'Ai corectat DNS si vrei sa reverifici?' || die 'Poti relua cu --resume dupa configurarea DNS.'
    done
    certbot certonly --webroot -w /var/www/letsencrypt --domain "$domain" --email "$email" --agree-tos --non-interactive --keep-until-expiring
    checkpoint tls
fi
cat > /etc/nginx/conf.d/vaulty-log.conf <<'NGINX'
log_format vaulty_safe '$remote_addr [$time_local] "$request_method" $status $body_bytes_sent $request_time';
NGINX
cat > /etc/nginx/sites-available/vaulty <<NGINX
server {
    listen 80;
    server_name $domain;
    server_tokens off;
    access_log /var/log/nginx/vaulty.access.log vaulty_safe;
    error_log /dev/null crit;
    location ^~ /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { return 301 https://$domain\$request_uri; }
}
server {
    listen 443 ssl http2;
    server_name $domain;
    root /var/www/document-vault-app/public;
    index index.php;
    charset utf-8;
    server_tokens off;
    ssl_certificate /etc/letsencrypt/live/$domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$domain/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    client_max_body_size 6m;
    access_log /var/log/nginx/vaulty.access.log vaulty_safe;
    error_log /dev/null crit;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_param HTTP_PROXY "";
        fastcgi_pass unix:/run/php/php8.3-fpm-vaulty.sock;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 60s;
    }
    location ~ \.php(?:/|$) { return 404; }
    location ~ /\. { deny all; }
}
NGINX
chmod 0644 /etc/nginx/conf.d/vaulty-log.conf /etc/nginx/sites-available/vaulty
nginx -t
install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\n/usr/sbin/nginx -t && /usr/bin/systemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
systemctl enable --now certbot.timer

STAGE='servicii systemd si helpere privilegiate'
for name in horizon scheduler; do
    if [[ "$name" == horizon ]]; then command='horizon'; stop=450; else command='schedule:work --no-interaction'; stop=30; fi
    cat > "/etc/systemd/system/vaulty-$name.service" <<UNIT
[Unit]
Description=Vaulty production $name
After=network-online.target mysql.service redis-server.service
Wants=network-online.target
[Service]
User=vaulty
Group=vaulty
WorkingDirectory=$ROOT
ExecStart=/usr/bin/php $ROOT/artisan $command
Environment=OMP_THREAD_LIMIT=1
Restart=always
RestartSec=5
TimeoutStopSec=$stop
KillSignal=SIGTERM
UMask=0077
NoNewPrivileges=true
StandardOutput=null
StandardError=journal
[Install]
WantedBy=multi-user.target
UNIT
done
for name in vaulty-backup.service vaulty-backup-monitor.service vaulty-backup.timer vaulty-backup-monitor.timer vaulty-restore.service vaulty-restore.path; do
    sed 's|WorkingDirectory=/var/www/vaulty|WorkingDirectory=/var/www/document-vault-app|' "$WORK/$name" > "/etc/systemd/system/$name"
done
chmod 0644 /etc/systemd/system/vaulty-*.service /etc/systemd/system/vaulty-*.timer /etc/systemd/system/vaulty-restore.path
install -d -m 0755 /usr/local/libexec
install -o root -g root -m 0700 "$WORK/restore-vaulty.sh" /usr/local/libexec/vaulty-restore
cfg helpers "$WORK"
systemctl daemon-reload
STAGE='verificare aplicatie noua'
systemctl restart php8.3-fpm.service
systemctl reload nginx
php_app vault:services:check
php_app up
response=$(curl --fail --silent --show-error --http2 --max-time 30 -o /dev/null -w '%{http_code} %{http_version}' "https://$domain/up")
[[ "$response" == '200 2' ]] || die 'Verificarea HTTPS/HTTP2 nu a reusit; se asteapta status 200 si HTTP/2.'
printf 'HTTPS: 200, HTTP/2 verificat.\n'
if cfg enabled REMINDERS_EMAIL_ENABLED && confirm 'Trimitem un email de test catre expeditorul configurat?'; then data_app mail-test; fi
if cfg enabled AI_ENABLED && confirm 'Testam AI cu un text fictiv? Consuma cota providerului.'; then php_app vault:ai:check --infer; fi
if ! $imported && confirm 'Creezi acum administratorul? Parola va fi ceruta ascuns de Artisan.'; then php_app vault:admin:create; fi

STAGE='backup si programare'
if cfg enabled BACKUP_ENABLED; then
    if ! data_app backup-probe; then
        confirm 'Initializezi un repository NOU in bucketul dedicat? Nu alege da pentru o parola gresita.' || die 'Verifica configurarea R2; reia cu --resume.'
        php_app vault:backup init
    fi
    php_app vault:backup run
    systemctl enable --now vaulty-backup.timer vaulty-backup-monitor.timer vaulty-restore.path
else
    printf 'Backupul extern nu este configurat. CD va refuza livrarea pana la activarea si testarea backupului.\n'
fi
systemctl enable --now vaulty-horizon.service vaulty-scheduler.service
sleep 2
for unit in mysql redis-server nginx php8.3-fpm vaulty-horizon vaulty-scheduler; do systemctl is-active --quiet "$unit"; done
php_app horizon:status
if [[ ! -f "$STATE/renewal.done" ]]; then certbot renew --dry-run; checkpoint renewal; fi

STAGE='configurare GitHub Actions optionala'
if cfg enabled BACKUP_ENABLED && confirm 'Configurezi acum CD in GitHub pentru acest server?'; then
    printf 'Necesita cont GitHub cu acces la repository; autentificarea se face direct cu gh, nu in chat.\n'
    if ! as_operator gh auth status --hostname github.com >/dev/null 2>&1; then
        as_operator gh auth login --hostname github.com --git-protocol https --web
    fi
    repository=Tiberiu96/document-vault-app
    as_operator gh variable set AUTO_DEPLOY_ENABLED --repo "$repository" --body false
    key="$operator_home/.ssh/vaulty-github-actions"
    as_operator mkdir -p "$operator_home/.ssh"
    as_operator chmod 0700 "$operator_home/.ssh"
    if [[ ! -f "$key" && ! -f "$key.pub" ]]; then
        as_operator ssh-keygen -q -t ed25519 -N '' -C vaulty-github-actions -f "$key"
    fi
    [[ -f "$key" && -f "$key.pub" ]] || die 'Perechea SSH existenta este incompleta.'
    public_key=$(as_operator cat "$key.pub")
    [[ "$public_key" =~ ^ssh-ed25519\ [A-Za-z0-9+/=]+(\ [A-Za-z0-9@._-]+)?$ ]] || die 'Cheie de deploy invalida.'
    entry="restrict,command=\"sudo -n /usr/local/libexec/vaulty-deploy\" $public_key"
    as_operator touch "$operator_home/.ssh/authorized_keys"
    as_operator chmod 0600 "$operator_home/.ssh/authorized_keys"
    if ! as_operator grep -qxF "$entry" "$operator_home/.ssh/authorized_keys"; then
        printf '\n%s\n' "$entry" | as_operator /bin/sh -c 'cat >> "$1"' sh "$operator_home/.ssh/authorized_keys"
    fi
    printf '%s ALL=(root) NOPASSWD: /usr/local/libexec/vaulty-deploy ""\n' "$operator" > /etc/sudoers.d/.vaulty-deploy.tmp
    chmod 0440 /etc/sudoers.d/.vaulty-deploy.tmp
    visudo -cf /etc/sudoers.d/.vaulty-deploy.tmp
    mv /etc/sudoers.d/.vaulty-deploy.tmp /etc/sudoers.d/vaulty-deploy
    # Cheia privata trece prin stdin direct catre GitHub, fara output sau argumente.
    as_operator /bin/sh -c 'gh secret set DEPLOY_SSH_KEY --repo "$1" < "$2"' sh "$repository" "$key"
    as_operator gh secret set DEPLOY_HOST --repo "$repository" --body "$public_ip"
    as_operator gh secret set DEPLOY_USER --repo "$repository" --body "$operator"
    awk -v host="$public_ip" '{print host " " $1 " " $2}' /etc/ssh/ssh_host_ed25519_key.pub | as_operator gh secret set DEPLOY_KNOWN_HOSTS --repo "$repository"
    if ! as_operator gh api --include "repos/$repository/environments/production" > "$WORK/environment.json" 2>/dev/null; then
        grep -Eq '^HTTP/[0-9.]+[[:space:]]+404' "$WORK/environment.json" || die 'Environment GitHub nu poate fi verificat; protectiile existente nu sunt modificate.'
        printf '%s' '{"deployment_branch_policy":{"protected_branches":false,"custom_branch_policies":true}}' | as_operator gh api -X PUT "repos/$repository/environments/production" --input - >/dev/null
        printf '%s' '{"name":"main","type":"branch"}' | as_operator gh api -X POST "repos/$repository/environments/production/deployment-branch-policies" --input - >/dev/null
    fi
    printf 'Secretele CD au fost actualizate. Verifica regulile environment production si accesul extern SSH port 22.\n'
    if confirm 'Activezi AUTO_DEPLOY_ENABLED=true? Urmatorul push main va livra aici dupa CI.'; then
        as_operator gh variable set AUTO_DEPLOY_ENABLED --repo "$repository" --body true
    fi
fi
checkpoint finished
printf '\nConfigurare terminata: https://%s/ro\n' "$domain"
printf 'Verifica autentificarea/MFA, un document existent si emailurile. Nu porni sursa in paralel.\n'
printf 'Arhiva criptata si parola ei se pastreaza separat. Datele temporare decriptate se elimina acum.\n'
printf 'Configuratia privata pentru reluare ramane in %s/target.json (root-only); .env este privat.\n' "$STATE"
exit 0
__VAULTY_ASSETS__
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
