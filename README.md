# Vaulty

Platforma pentru documente personale si business, dezvoltata incremental cu Laravel 13, Blade, Livewire, Tailwind 4 standalone, MySQL si Redis.

## Disponibil acum

- Pagina publica, functionalitati, planuri si FAQ in romana fara diacritice, engleza si maghiara.
- Demo cu date fictive, cautare si filtre; nu incarca sau proceseaza fisiere reale.
- Login/logout pentru conturi existente, sesiuni regenerate si rate limiting pe cont/IP.
- Panou privat cu spatiile accesibile utilizatorului, autorizate prin WorkspacePolicy.
- Structura limbilor, activare/dezactivare si schimbare sigura a defaultului prin serviciu intern autorizat.
- Canonical, hreflang, Open Graph/social card, sitemap, robots pe medii si llms.txt.
- Comanda interactiva pentru primul administrator; accesul administrativ/Horizon ramane blocat pana la MFA.

Inregistrarea PF/PJ, verificarea emailului, interfata admin pentru limbi, documentele reale, OCR/AI, reminderele si platile urmeaza in etapele urmatoare. Nu exista conturi implicite sau credentiale in surse.

## Rulare locala

MySQL/Redis locale sunt configurate si verificate; opt migrari sunt aplicate. Un clone nou necesita configurarea locala a serviciilor, `.env` cu permisiuni restrictive si APP_KEY generat fara afisare. Nu copia credentialele de dezvoltare in Git.

```bash
composer install
php artisan migrate
php artisan db:seed --class=LanguageSeeder
composer run css:build
php artisan serve --host=127.0.0.1 --port=8000
```

Binarul Tailwind trebuie instalat local in `.tools/` conform [docs/web-foundation.md](docs/web-foundation.md), inainte de build. Fara Node/Vite.

Pe serverul de dezvoltare: [pagina publica](http://vaulty.test:8000/ro), [autentificare](http://vaulty.test:8000/ro/autentificare), [demo](http://vaulty.test:8000/ro/demo). Nginx/hosts pentru `http://vaulty.test` sunt documentate separat in [docs/local-hosting.md](docs/local-hosting.md).

Primul administrator se creeaza in terminal, cu parola ascunsa:

```bash
php artisan vault:admin:create
```

Comanda nu activeaza accesul admin/MFA si nu creeaza automat un workspace.

## Verificari

```bash
composer test
vendor/bin/pint --test app bootstrap/app.php config database lang routes tests
composer validate --no-check-publish
composer check-platform-reqs
php artisan route:cache
php artisan test --compact
php artisan route:clear
```

52 teste / 667 asertiuni trecute, inclusiv cu rutele in cache. Serviciile externe sunt mockuite; testele folosesc SQLite in memorie. Probele locale MySQL/Redis sunt descrise in setup. Testul de traduceri verifica existenta fiecarei chei prin translator, nu doar paritatea dictionarelor.

## Git si configurare privata

Repository: Tiberiu96/document-vault-app. `composer.json` si `composer.lock` se versioneaza. `.env`, credentialele, `vendor/`, `.tools/`, cache-urile, logurile si continutul `storage/` sunt ignorate; doar fisierele `.gitignore` pentru structura runtime se pastreaza. CSS generat se construieste la instalare/deployment. Fonturile locale si licentele lor sunt versionate.

Reguli permanente: [AGENTS.md](AGENTS.md). Arhitectura: [docs/architecture.md](docs/architecture.md). Design: [docs/design-system.md](docs/design-system.md). Checkpoint UI/auth: [docs/web-foundation.md](docs/web-foundation.md).
