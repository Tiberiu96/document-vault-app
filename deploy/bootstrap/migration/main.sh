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
