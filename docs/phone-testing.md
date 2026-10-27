# Test temporar pe telefon

Telefonul si laptopul trebuie sa fie in aceeasi retea de incredere. Serviciul de
previzualizare PHP este doar pentru dezvoltare, nu inlocuieste Nginx in productie.

Template: deploy/systemd/vaulty-phone-preview.service. Copia utilizatorului este in
~/.config/systemd/user/, cu EnvironmentFile=~/.config/vaulty/phone-preview.env (0600).
Acest fisier contine VAULT_PHONE_HOST=IP_LAN_AL_LAPTOPULUI, fara credentiale.
Serviciul asculta numai pe acel IP, port8080, accepta upload5MiB/post6MiB si nu
inregistreaza URL-uri care pot contine tokenuri. Nu este activat automat la boot.

Pornire/oprire: systemctl --user start vaulty-phone-preview / stop vaulty-phone-preview.
Deschide http://IP_LAN_AL_LAPTOPULUI:8080/ro/documente/incarca si autentifica-te.
APP_URL local trebuie sa foloseasca acelasi origin pentru linkurile din email;
dupa schimbare: php artisan config:clear si systemctl --user restart vaulty-horizon.
La schimbarea IP-ului actualizeaza fisierul local si reporneste serviciul.

Mailpit si Ollama raman exclusiv loopback. Nu le publica pe LAN. Traficul HTTP local
nu este criptat; foloseste date fictive pe reteaua de test. In productie se cere HTTPS.
Documentele raman private, fara storage:link.
