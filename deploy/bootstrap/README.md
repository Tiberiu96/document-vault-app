# Installer runtime pentru VM beta

`install-ubuntu-24.04.sh` este un installer permanent cerut explicit de utilizator,
nu un script temporar cu credentiale. Tinta: VM noua Ubuntu 24.04 LTS x86_64,
cu systemd. Nu se ruleaza pe laptop sau peste aplicatia deja instalata.

Copia locala pentru upload este `/home/tiberiu/Desktop/vaulty-install-server.sh`.
In terminalul SSH din Google Cloud, foloseste Upload file si selecteaza acest fisier.
Apoi, pe server, din directorul unde a ajuns fisierul (de regula home):

```bash
bash vaulty-install-server.sh --dry-run
sudo bash vaulty-install-server.sh
sudo bash vaulty-install-server.sh --check
```

Nu descarca alte instalatoare prin curl | bash. Verifica rezultatul final; daca
scriptul se opreste, nu continua deploy-ul orb. Nu sterge lockurile apt/dpkg.

Instaleaza din Ubuntu: PHP 8.3 CLI/FPM si extensii, Composer, Nginx, MySQL, Redis,
Tesseract ron/eng/hun, OCRmyPDF, Poppler, qpdf, Ghostscript, Git, curl, Python,
cron, tmux, rsync, ACL, jq, Certbot si Restic. Tailwind standalone 4.3.3 x64 provine
din release-ul oficial GitHub, cu SHA-256 fix verificat inainte de instalare.
Fara Node/Vite/Docker, Ollama sau Mailpit. Nu executa codul aplicatiei ca root.

MySQL/Redis sunt configurate pentru loopback. Redis foloseste AOF/everysec si
noeviction. Scriptul refuza modificarea unui Redis existent cu date sau autentificare
necunoscuta; nu migreaza persistenta pe o instanta folosita. Configuratiile schimbate
sunt salvate root-only in /var/backups/vaulty-bootstrap, fara afisarea continutului.
Nu activeaza firewallul si nu schimba accesul SSH. Regulile VPC trebuie verificate
separat; 3306/33060/6379 nu se deschid public.

PHP permite upload 5 MiB si POST 6 MiB; CLI nu are limita de executie PHP, iar FPM
pastreaza 60 secunde. Timeouturile joburilor sunt gestionate ulterior de Horizon.
Se pornesc serviciile sistem, dar nu workeri sau schedulerul aplicatiei. Cron instalat
nu inseamna ca exista deja un cron Laravel. Systemd va supraveghea Horizon, deci nu
este necesar Supervisor in paralel.

Tailwind este instalat root-owned la /usr/local/bin/vaulty-tailwindcss. Dupa clone,
cu utilizatorul de deploy, din proiect:

```bash
mkdir -p .tools
ln -s /usr/local/bin/vaulty-tailwindcss .tools/tailwindcss
composer css:build
```

Daca .tools/tailwindcss exista deja, verifica-l inainte; nu il suprascrie orb.

Ramane un pas separat: checkout Git, utilizator/pool FPM dedicat si permisiuni,
baza/utilizator MySQL Vaulty, .env, APP_KEY, migrari/seed initiale, domeniu/IP static,
TLS/Nginx sanitizat, workeri Horizon si Scheduler, email, configurare AI/Turnstile,
backup extern si restaurare de proba. Nu se creeaza parole sau conturi implicite.
Nu copia .env sau baza de pe laptop fara un transfer privat aprobat.

Validare locala a installerului: bash -n, --dry-run si identitatea copiei Desktop.
Nu reprezinta o instalare verificata pe VM: rezultatul real se confirma dupa rulare.


## Cheie de deploy persistenta pe Google Cloud

`install-deploy-ssh-key.sh` este installer permanent, separat de installerul
runtime. Refoloseste cheia publica ED25519 existenta, verifica amprenta asteptata
si o instaleaza root-owned in `/etc/ssh/vaulty-authorized-keys/`, cu comanda
fortata spre helperul de deploy. Pastreaza cheile Google si verifica sshd inainte
de reload. Nu foloseste credentiale private si nu schimba secrete GitHub.
Parametri, rollback si reluarea deploy-ului: `docs/ci-cd.md`.
