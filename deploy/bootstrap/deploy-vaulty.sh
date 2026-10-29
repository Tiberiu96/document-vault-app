#!/usr/bin/env bash
# Se instaleaza root-owned in /usr/local/libexec/vaulty-deploy, fara argumente.
# Cheia SSH dedicata poate invoca numai acest helper prin sudo.
set -Eeuo pipefail
umask 077
[[ $EUID -eq 0 && $# -eq 0 ]] || exit 1
root=/var/www/document-vault-app
operator=szabotiberiuandrei2020
health=https://vaulty-beta.duckdns.org/up
operator_home=$(getent passwd "$operator" | cut -d: -f6)
[[ -d "$operator_home" && -d "$root/.git" ]] || exit 1
as_operator() { /usr/sbin/runuser -u "$operator" -- /bin/bash -c 'umask 022; exec "$@"' bash env HOME="$operator_home" "$@"; }
php_app() { /usr/sbin/runuser -u vaulty -- /usr/bin/php "$root/artisan" "$@"; }
exec 9>/run/vaulty-deploy.lock
flock -n 9 || { echo 'Alt deploy este in curs.'; exit 1; }
# Acelasi lock ca runnerul de restaurare: nu pot schimba simultan instalarea.
exec 8>/run/vaulty-restore.lock
flock -n 8 || { echo 'Restaurare in curs.'; exit 1; }
read -r -t 30 -n 41 sha
read -r -t 30 -n 65 css_hash
read -r -t 30 -n 4000000 css_base64
[[ "$sha" =~ ^[a-f0-9]{40}$ && "$css_hash" =~ ^[a-f0-9]{64}$ ]]
[[ -n "$css_base64" && "$css_base64" =~ ^[A-Za-z0-9+/=]+$ ]]
temporary=$(mktemp -d /var/tmp/vaulty-deploy.XXXXXXXX)
in_maintenance=false
timers=()
cleanup() {
    result=$?
    if (( result != 0 )) && $in_maintenance; then
        php_app down --no-interaction >/dev/null 2>&1 || true
        systemctl stop vaulty-scheduler.service vaulty-horizon.service php8.3-fpm.service || true
        echo 'Deploy esuat. Aplicatia ramane in mentenanta; verifica din terminal.'
    elif ! $in_maintenance && (( ${#timers[@]} )); then
        systemctl start "${timers[@]}" || true
    fi
    rm -rf -- "$temporary"
}
trap cleanup EXIT
printf '%s' "$css_base64" | base64 --decode > "$temporary/app.css"
unset css_base64
[[ -s "$temporary/app.css" && "$(sha256sum "$temporary/app.css" | cut -d' ' -f1)" == "$css_hash" ]]
cd "$root"
[[ "$(as_operator git remote get-url origin)" == https://github.com/Tiberiu96/document-vault-app.git ]]
[[ "$(as_operator git branch --show-current)" == main ]]
[[ -z "$(as_operator git status --porcelain --untracked-files=normal)" ]]
[[ ! -f storage/framework/down && ! -e storage/app/backup-control/restore/incomplete ]]
[[ ! -e storage/app/backup-control/restore/request.json ]]
as_operator git fetch --no-tags origin main
if [[ "$(as_operator git rev-parse origin/main)" != "$sha" ]]; then
    echo 'Commit depasit de un push mai nou; deploy omis.'
    exit 0
fi
as_operator git merge-base --is-ancestor HEAD "$sha"
for timer in vaulty-backup.timer vaulty-backup-monitor.timer; do
    if systemctl is-active --quiet "$timer"; then
        timers+=("$timer")
        systemctl stop "$timer"
    fi
done
for unit in vaulty-backup.service vaulty-backup-monitor.service vaulty-restore.service; do
    state=$(systemctl show "$unit" --property=ActiveState --value)
    [[ "$state" == inactive || "$state" == failed ]]
done
php_app vault:services:check --no-interaction
# Copie verificata inainte de orice schimbare de cod/schema. Refuza deploy daca esueaza.
php_app vault:backup run --no-interaction
install -d -m 0700 /var/lib/vaulty-deploy
as_operator git rev-parse HEAD > /var/lib/vaulty-deploy/previous-commit
cp public/css/app.css /var/lib/vaulty-deploy/previous-app.css
in_maintenance=true
php_app down --no-interaction
systemctl stop vaulty-scheduler.service vaulty-horizon.service php8.3-fpm.service
[[ ! -e storage/app/backup-control/restore/request.json && ! -e storage/app/backup-control/restore/incomplete ]]
# Curata cache-urile cu vechiul cod functional, folosind proprietarul runtime.
php_app config:clear --no-interaction
php_app route:clear --no-interaction
php_app clear-compiled --no-interaction
php_app view:clear --no-interaction
as_operator git merge --ff-only "$sha"
# Composer instaleaza codul ca operator; operatiile Laravel ruleaza numai ca vaulty.
as_operator composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts
as_operator composer check-platform-reqs --no-dev
cat "$temporary/app.css" | as_operator /bin/sh -c 'umask 022; cat > public/css/app.css; chmod 644 public/css/app.css'
php_app package:discover --no-interaction
php_app migrate --force --no-interaction
php_app config:cache --no-interaction
php_app route:cache --no-interaction
php_app view:cache --no-interaction
php_app vault:services:check --no-interaction
systemctl start php8.3-fpm.service
systemctl is-active --quiet php8.3-fpm.service
php_app up --no-interaction
# Nu porni joburile pe versiunea noua pana cand aplicatia raspunde prin HTTPS.
curl --fail --silent --show-error --max-time 30 --retry 3 --retry-delay 2 "$health" >/dev/null
systemctl start vaulty-horizon.service vaulty-scheduler.service
sleep 2
for unit in php8.3-fpm.service vaulty-horizon.service vaulty-scheduler.service; do
    systemctl is-active --quiet "$unit"
done
printf '%s\n' "$sha" > /var/lib/vaulty-deploy/current-commit
in_maintenance=false
echo "Deploy reusit: $sha"
