#!/usr/bin/env bash
# Installer permanent: cheia CI sta separat de authorized_keys gestionat de Google.
set -Eeuo pipefail
umask 077

operator=''
public_key=''
expected_fingerprint=''
dry_run=false
while (( $# )); do
    case "$1" in
        --user) operator=${2:?}; shift 2 ;;
        --public-key) public_key=${2:?}; shift 2 ;;
        --fingerprint) expected_fingerprint=${2:?}; shift 2 ;;
        --dry-run) dry_run=true; shift ;;
        *) echo 'Argument necunoscut.' >&2; exit 1 ;;
    esac
done
[[ $EUID -eq 0 ]] || { echo 'Ruleaza prin sudo.' >&2; exit 1; }
[[ "$operator" =~ ^[a-z_][a-z0-9_-]*$ && "$expected_fingerprint" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]] || exit 1
id "$operator" >/dev/null
[[ -f "$public_key" && ! -L "$public_key" ]] || { echo 'Fisierul public lipseste sau este symlink.' >&2; exit 1; }
actual_fingerprint=$(ssh-keygen -E sha256 -lf "$public_key" | awk '{print $2}')
[[ "$actual_fingerprint" == "$expected_fingerprint" ]] || { echo 'Amprenta nu corespunde cheii CI cunoscute. Nu am modificat SSH.' >&2; exit 1; }
read -r key_type key_body _ < "$public_key"
[[ "$key_type" == 'ssh-ed25519' && "$key_body" =~ ^[A-Za-z0-9+/]+={0,2}$ ]] || exit 1

helper=/usr/local/libexec/vaulty-deploy
[[ -f "$helper" && ! -L "$helper" && -x "$helper" && $(stat -c '%u' "$helper") == 0 ]] || { echo 'Helperul root-owned de deploy lipseste.' >&2; exit 1; }
helper_mode=$(stat -c '%a' "$helper")
(( (8#$helper_mode & 8#022) == 0 )) || { echo 'Helperul permite scriere nepermisa.' >&2; exit 1; }
/usr/sbin/sshd -t

key_directory=/etc/ssh/vaulty-authorized-keys
key_target="$key_directory/$operator"
config_target=/etc/ssh/sshd_config.d/00-vaulty-deploy-key.conf
[[ -d /etc/ssh/sshd_config.d ]] || exit 1
[[ ! -L "$key_directory" && ! -L "$key_target" && ! -L "$config_target" ]] || exit 1
# Nu suprascrie o politica SSH personalizata sau alte surse de chei.
existing_sources=$(/usr/sbin/sshd -T | awk '$1 == "authorizedkeysfile" {$1=""; sub(/^ /, ""); print}')
case "$existing_sources" in
    '.ssh/authorized_keys .ssh/authorized_keys2'|'.ssh/authorized_keys .ssh/authorized_keys2 /etc/ssh/vaulty-authorized-keys/%u') ;;
    *) echo 'AuthorizedKeysFile are valori personalizate; este necesara revizuire.' >&2; exit 1 ;;
esac

if $dry_run; then
    echo 'Verificari preliminare reusite. Instalarea va verifica si eventualele fisiere existente.'
    echo 'Nu s-au modificat fisiere sau servicii.'
    exit 0
fi

temporary=$(mktemp -d /var/tmp/vaulty-deploy-key.XXXXXXXX)
new_key=false
new_config=false
success=false
reloaded=false
cleanup() {
    result=$?
    if ! $success; then
        if $new_config; then rm -f -- "$config_target"; fi
        if $new_key; then rm -f -- "$key_target"; fi
        if $reloaded; then /usr/sbin/sshd -t && systemctl reload ssh.service || true; fi
    fi
    rm -rf -- "$temporary"
    exit "$result"
}
trap cleanup EXIT
printf 'restrict,command="sudo -n /usr/local/libexec/vaulty-deploy" %s %s vaulty-github-actions\n' "$key_type" "$key_body" > "$temporary/key"
printf '# Vaulty CI: cheie root-owned separata de cheile temporare Google.\nAuthorizedKeysFile .ssh/authorized_keys .ssh/authorized_keys2 /etc/ssh/vaulty-authorized-keys/%%u\n' > "$temporary/config"
if [[ -e "$key_target" ]] && ! cmp -s "$temporary/key" "$key_target"; then
    echo 'Exista deja alta configuratie pentru cheia CI. Nu am suprascris-o.' >&2; exit 1
fi
if [[ -e "$config_target" ]] && ! cmp -s "$temporary/config" "$config_target"; then
    echo 'Exista deja alta configuratie SSH. Nu am suprascris-o.' >&2; exit 1
fi
install -d -o root -g root -m 0755 "$key_directory"
if [[ ! -e "$key_target" ]]; then new_key=true; fi
# Cheia este publica. sshd o citeste in contextul utilizatorului, fara drept de scriere.
install -o root -g root -m 0644 "$temporary/key" "$key_target"
if [[ ! -e "$config_target" ]]; then new_config=true; fi
install -o root -g root -m 0644 "$temporary/config" "$config_target"
/usr/sbin/sshd -t
effective_sources=$(/usr/sbin/sshd -T -C "user=$operator,host=localhost,addr=127.0.0.1" | awk '$1 == "authorizedkeysfile" {print}')
[[ "$effective_sources" == *'/etc/ssh/vaulty-authorized-keys/'* ]] || { echo 'Noua sursa nu este activa; rollback.' >&2; exit 1; }
reloaded=true
systemctl reload ssh.service
success=true
echo 'Cheia CI a fost instalata separat, cu restrictie la deploy. SSH a fost reincarcat, fara restart.'
echo 'Cheile Google si fisierele din home nu au fost modificate. Nu s-au schimbat secretele GitHub.'
