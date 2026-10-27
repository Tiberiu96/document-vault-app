# Configurare locala Vaulty

## Mediu verificat

Linux Mint 22.3: PHP 8.3.6, Composer 2.7, MySQL, Redis, Git, Tesseract ron/eng/hun, OCRmyPDF, qpdf, Ghostscript si pdftotext. Extensiile necesare sunt disponibile, inclusiv pdo_mysql/pdo_sqlite, redis, pcntl/posix. Laravel 13.39, Livewire 4.4.8 si celelalte versiuni rezolvate sunt pastrate in composer.lock. Fara Node/Vite pentru web.

Reteaua a fost initial restrictionata; ulterior accesul HTTPS/Packagist a fost verificat. Nu dezactiva TLS sau cerintele de platforma Composer. Instalatoarele temporare .bootstrap, scripturile Bash si utilitarul PHP MySQL au fost eliminate dupa utilizare.

## Servicii si credentiale

Utilizatorul a confirmat crearea MySQL locale de la zero. Baza si contul dedicate au fost create prin root local pe socket; parola aleatorie a fost scrisa direct in .env fara afisare. .env are 0600. Nu contine parola administratorului si nu este versionat. Nu regenera APP_KEY la fiecare instalare si nu folosi --show.

Campuri de configurat local pe o instalare noua: DB_CONNECTION=mysql, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD; REDIS_CLIENT=phpredis, REDIS_HOST, REDIS_PORT, REDIS_USERNAME, REDIS_PASSWORD, REDIS_DB, REDIS_CACHE_DB. Nu trimite valorile in conversatie. Nu folosi root MySQL in aplicatie si nu expune porturile serviciilor public. Providerii cloud/email/AI necesita configurare ghidata separata.

Conexiunile MySQL SELECT 1 si Redis PING sunt verificate prin Laravel. Cache-ul si un lock Redis au fost verificate real; CACHE_STORE=redis si QUEUE_CONNECTION=redis sunt active local. Exemplul de mediu pastreaza adaptoare locale pana la configurare. MAIL_MAILER=array nu livreaza emailuri. Nu loga continut privat.

```bash
php artisan config:clear
php artisan vault:services:check
php artisan migrate
php artisan db:seed --class=LanguageSeeder
```

Sunt opt migrari aplicate pe MySQL. Unicitatea workspaceului personal si posibilitatea mai multor firme au fost verificate cu date de proba anulate prin rollback. Seederul initial creeaza numai cele trei limbi, fara conturi; nu reactiveaza limbi dezactivate.

## Primul administrator

```bash
php artisan vault:admin:create
```

Terminal interactiv, nume/email/parola ascunsa/confirmare. Parola: minimum 16 caractere si maximum 72 bytes pentru bcrypt, litere mari/mici, cifre si simboluri. Comanda verifica conexiunea/schema inainte de prompturi, refuza email existent si nu promoveaza conturi implicit. Nu crea un admin prin seeder sau credentiale env. Loginul web este disponibil pentru conturile existente; admin/Horizon raman blocate pana la MFA. Inregistrarea PF/PJ si livrarea/verificarea emailului nu sunt inca implementate.

## Web si verificare

```bash
composer run css:build
php artisan serve --host=127.0.0.1 --port=8000
```

Pagina: http://vaulty.test:8000/ro; login: /ro/autentificare; demo: /ro/demo; cont: /ro/panou. Domeniul fara port necesita activarea Nginx din local-hosting.md. APP_URL este http://vaulty.test; paginile servite pe 8000 sunt numai pentru verificare locala. Fonturile sunt locale si CSS se construieste cu binarul Tailwind ignorat din .tools (instructiuni/checksum in web-foundation.md).

52 teste / 667 asertiuni au trecut inclusiv cu route cache; nu exista apeluri AI platite. Pentru verificare: composer test, Pint --test, composer validate, composer check-platform-reqs, route:cache, php artisan test --compact, route:clear. Testele standard folosesc SQLite in memorie si mocks externe; nu executa migrate:fresh pe baza reala.

## Windows 10 fara Docker

PHP/Composer nativ compatibil, MySQL local si binar Tailwind standalone Windows. Pentru CSS ruleaza .tools/tailwindcss.exe cu aceleasi argumente de build. Horizon necesita pcntl/posix, deci Redis/workeri Horizon/OCR prin WSL2 Linux; fara --ignore-platform-reqs. Confirma WSL2 inainte de instalare. Poolul PHP-FPM local din deploy este specific Linux Mint, nu Windows/production. Expo va necesita Node LTS la etapa mobila; buildurile iOS cer Mac sau provider separat configurat ghidat.
