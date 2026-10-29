#!/usr/bin/env bash
# Runner privilegiat fix, fara argumente de la HTTP. PHP ruleaza numai ca vaulty.
set -Eeuo pipefail
umask 077
[[ $EUID -eq 0 && $# -eq 0 ]] || exit 1
root=/var/www/document-vault-app
control="$root/storage/app/backup-control/restore"
request="$control/request.json"
[[ -f "$request" && ! -L "$request" ]] || exit 0
[[ "$(readlink -f -- "$control")" == "$control" ]] || exit 1
exec 9>/run/vaulty-restore.lock
flock -n 9 || exit 1
php_app() { /usr/sbin/runuser -u vaulty -- /usr/bin/php "$root/artisan" "$@"; }
reject_request() {
    if [[ -f "$request" ]]; then
        /usr/sbin/runuser -u vaulty -- mv -- "$request" "$control/rejected-$(date +%s%N).json"
    fi
}
trap 'reject_request' EXIT
cd "$root"
php_app vault:backup restore-validate --no-interaction || exit 1
# Nu intrerupe o copie sau un monitor deja in executie.
for unit in vaulty-backup.service vaulty-backup-monitor.service; do
    state=$(systemctl show "$unit" --property=ActiveState --value)
    [[ "$state" == inactive || "$state" == failed ]] || exit 1
done
active_timers=()
for timer in vaulty-backup.timer vaulty-backup-monitor.timer; do
    if systemctl is-active --quiet "$timer"; then
        active_timers+=("$timer")
        systemctl stop "$timer"
    fi
done
php_app down --no-interaction
systemctl stop vaulty-scheduler.service vaulty-horizon.service php8.3-fpm.service
# Revalideaza dupa oprire: nicio fereastra de scriere HTTP/joburi in timpul importului.
if ! php_app vault:backup restore-apply --no-interaction; then
    echo 'Restaurarea nu a reusit. Mentenanta si serviciile oprite sunt pastrate. Verifica starea si copia locala de siguranta.'
    exit 1
fi
php_app vault:services:check --no-interaction
php_app up --no-interaction
if ! systemctl start php8.3-fpm.service vaulty-horizon.service vaulty-scheduler.service; then
    php_app down --no-interaction
    systemctl stop vaulty-scheduler.service vaulty-horizon.service php8.3-fpm.service
    exit 1
fi
sleep 1
for unit in php8.3-fpm.service vaulty-horizon.service vaulty-scheduler.service; do
    if ! systemctl is-active --quiet "$unit"; then
        php_app down --no-interaction
        systemctl stop vaulty-scheduler.service vaulty-horizon.service php8.3-fpm.service
        exit 1
    fi
done
if ! php_app vault:backup restore-finish --no-interaction; then
    php_app down --no-interaction
    systemctl stop vaulty-scheduler.service vaulty-horizon.service php8.3-fpm.service
    exit 1
fi
if (( ${#active_timers[@]} )); then
    systemctl start "${active_timers[@]}"
fi
echo 'Restaurare aplicata si servicii repornite.'
