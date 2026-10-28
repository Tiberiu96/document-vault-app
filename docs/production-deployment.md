# Deploy Vaulty pe productie

Acesta este un ghid pentru server nou, nu o instalare efectuata sau validata pe
serverul de productie. Domeniul, distributia, arhitectura si providerul de email
se confirma inainte de aplicarea configuratiilor. Nu copia .env local pe server.

## Stack

Baza potrivita pentru configuratia verificata local: Linux Ubuntu 24.04 LTS,
Nginx, PHP 8.3 CLI + FPM + OPcache, Composer 2, MySQL si Redis. Laravel/pachetele
vin din composer.lock; foloseste composer install, nu composer update.
Extensii: ctype, curl, dom, fileinfo, filter, hash, mbstring, openssl, pcre, PDO,
pdo_mysql, session, tokenizer, xml, intl, bcmath, gd, zip, redis. Horizon CLI
cere pcntl si posix. CLI si FPM trebuie sa foloseasca extensii compatibile.

OCR: Tesseract ron/eng/hun, OCRmyPDF, Poppler (pdftotext/pdftoppm), qpdf si
Ghostscript. Nginx/FPM, Horizon si Scheduler sunt procese diferite. Nu este
nevoie de Node, Vite sau Docker pentru aplicatia web actuala.

Exemplu de pachete numai pentru Ubuntu 24.04, dupa verificarea distributiei:

```bash
sudo apt-get update
sudo apt-get -o DPkg::Lock::Timeout=120 install nginx mysql-server redis-server composer git curl unzip ca-certificates php8.3-cli php8.3-fpm php8.3-common php8.3-opcache php8.3-mysql php8.3-xml php8.3-mbstring php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-redis tesseract-ocr tesseract-ocr-ron tesseract-ocr-eng tesseract-ocr-hun ocrmypdf poppler-utils qpdf ghostscript
```

Nu este necesar Ollama daca AI este dezactivat sau alegi Groq. Ollama local cere
resurse separate, evaluate pe model; nu il instala implicit pe acelasi VPS.
Mailpit si serverul phone-preview sunt exclusiv pentru dezvoltare.

## Configurare privata

Creeaza o baza MySQL si un utilizator dedicat, cu acces numai la baza aplicatiei.
MySQL si Redis raman pe loopback/retea privata, fara porturi publice. Separa bazele
Redis pentru queue si cache; nu aplica politici de eviction care pierd joburi.
Secretele se completeaza pe server, niciodata in chat sau Git.

Campuri esentiale din .env, folosind valori reale configurate local:

```dotenv
APP_NAME=Vaulty
APP_ENV=production
APP_DEBUG=false
APP_URL=https://vaulty.example
APP_LOCALE=ro
APP_FALLBACK_LOCALE=ro
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_USERNAME=
REDIS_PASSWORD=
REDIS_DB=0
REDIS_CACHE_DB=1
FILESYSTEM_DISK=local
LOG_LEVEL=warning
MAIL_MAILER=array
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=Vaulty
REMINDERS_ENABLED=true
REMINDERS_EMAIL_ENABLED=false
AI_ENABLED=false
OCR_ENABLED=true
VAULT_FREE_AI_CALLS=10
```

vaulty.example este placeholder, nu un domeniu configurat. MAIL_MAILER=array este
un mod temporar fara livrare: confirma contul/providerul SMTP si setarile sale,
MAIL_SCHEME cand este necesar, SPF/DKIM/DMARC si adresa expeditorului. Testeaza,
apoi seteaza MAIL_MAILER=smtp si REMINDERS_EMAIL_ENABLED=true. Nu folosi log pentru
emailuri cu linkuri de autentificare. Nu porni public inregistrarea cu emailurile
oprite: conturile nu isi pot confirma adresa sau reseta parola.

Pentru AI se configureaza separat AI_ENABLED, AI_PROVIDER si cheia providerului
ales (de exemplu GROQ_API_KEY), cu un cont/cheie de productie si optiuni de
confidentialitate verificate. Nu copia exceptia locala VAULT_FREE_AI_CALLS=-1.
FCM, platile si analytics nu se activeaza implicit.

APP_KEY se genereaza numai la instalarea unei baze noi. Nu il afisa si nu il
regenera la git pull. Daca transferi o baza existenta, pastreaza cheia corespunzatoare
prin transfer securizat; OCR si rezultate deja criptate depind de ea. Backupurile
cheii si ale .env trebuie protejate separat, la fel ca baza si fisierele private.

## Prima instalare

1. Configureaza acces Git read-only pentru deploy si cloneaza proiectul. Un git pull
   necesita deja un checkout si nu instaleaza automat runtime-ul sau serviciile.
2. Configureaza .env cu permisiuni restrictive pentru utilizatorul PHP/workeri.
3. Ruleaza cu utilizatorul de deploy, din directorul proiectului:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
```

key:generate este numai pentru o instalare goala, fara date criptate existente.
Seeder-ele creeaza limbile/categoriile/etichetele, fara conturi sau parole implicite.
Administratorul se creeaza interactiv cu php artisan vault:admin:create; panoul web
admin ramane blocat pana la implementarea MFA/autorizarii complete.

CSS NU este inclus in Git. Instaleaza binarul Tailwind standalone versionat dupa
instructiunile si checksumul din web-foundation.md (Linux x64), apoi ruleaza:

```bash
composer css:build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Pentru ARM foloseste binarul corespunzator si checksum verificat; nu cel x64.
Alternativ, construieste CSS in CI si livreaza public/css/app.css ca artefact.
Lipsa CSS-ului generat impiedica randarea actualelor layouturi.

## Nginx, TLS si permisiuni

- Server block pentru domeniul real, cu document root numai la public/ si PHP prin
  socketul poolului FPM dedicat; nu servi radacina proiectului.
- Nu copia direct deploy/nginx/vaulty.local.conf: este legat de loopback si forteaza
  noindex peste tot. In productie paginile publice trebuie sa poata fi indexate;
  aplicatia pastreaza noindex pentru auth/admin/documente.
- Configureaza HTTPS dupa DNS/domeniu si certificat; redirectioneaza HTTP spre HTTPS.
  Certbot poate fi folosit dupa configurarea domeniului; nu exista certificat creat
  prin acest ghid. Daca exista proxy/CDN, configureaza trusted proxies explicit.
- Nginx client_max_body_size 6m; PHP upload_max_filesize 5M si post_max_size 6M.
  Limitele aplicatiei raman server-side. Dimensioneaza procesele FPM/OCR dupa RAM.
- User dedicat PHP/worker, cod fara drepturi de scriere pentru procesul web, dar
  storage/ si bootstrap/cache/ scriibile. .env se citeste numai de userii autorizati;
  nu folosi chmod 777.
- Acces/error logs fara URI/query autentificare sau continut privat. Foloseste
  formatul sanitizat din configuratia locala drept model, nu log_format combined.
- Documentele sunt in storage/app/private; nu rula storage:link pentru documente.

## Workeri si Scheduler

Horizon se supravegheaza prin systemd sau Supervisor, cu cozi separate
high/default/ocr/ai/low conform config/horizon.php, retry_after Redis=360s si timp
de oprire cel putin 330s. Foloseste un user dedicat si Restart=always/autorestart.
Nu copia nemodificate unitatile locale: userul, calea si dependenta Mailpit trebuie
adaptate, iar serviciile de sistem folosesc multi-user.target.

Scheduler: fie un cron per minut care ruleaza php artisan schedule:run, fie un
serviciu supravegheat schedule:work. Nu ambele. deploy/systemd/vaulty-scheduler.service
este un model local; adapteaza userul, directorul si instalarea system-wide pentru
productie. Monitorizeaza procesele si schedulerul, nu numai raspunsul HTTP /up.

## Actualizari ulterioare

Inainte de deploy: backup DB + storage privat + chei, cu restaurare verificata.
Pentru instalarea simpla, in fereastra de mentenanta, din checkout-ul curat:

```bash
php artisan down
git pull --ff-only origin main
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
composer css:build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan horizon:terminate
php artisan up
```

Opreste/coordoneaza Schedulerul si workerii inainte de migrari incompatibile si
reporneste-i pe noul cod. horizon:terminate este graceful si cere un supraveghetor
care reporneste procesul. Restarteaza si schedule:work, reincarca FPM/OPcache cand
este cazul; foloseste unitatile specifice instalarii reale. Daca un pas esueaza,
nu continua orb: mentine mentenanta si verifica rollbackul, mai ales pentru schema.
Nu folosi migrate:fresh, key:generate sau reset --hard la update. Nu sterge storage.

Verifica HTTPS, /up, login, upload privat/OCR si livrare email reala dupa deploy.
Rutinele de backup, monitorizarea si hardeningul de productie raman de configurat;
acest ghid nu declara platforma complet auditata pentru lansare comerciala.

Surse: https://laravel.com/framework/docs/deployment si https://packages.ubuntu.com/noble/php/.
