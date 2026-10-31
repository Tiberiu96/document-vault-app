# Vaulty

Platforma pentru documente personale si business, dezvoltata incremental cu Laravel 13, Blade, Livewire, Tailwind 4 standalone, MySQL si Redis.

## Disponibil acum

- Pagina publica, functionalitati, planuri si FAQ in romana fara diacritice, engleza, maghiara si bulgara.
- Interfata publica si biblioteca privata, cu upload, OCR, AI optional si remindere.
- Inregistrare PF/PJ cu creare atomica user/workspaces/company, login/logout, sesiuni regenerate si rate limiting.
- Panou privat si selectare de workspace, autorizate prin membership si WorkspacePolicy.
- Structura limbilor, activare/dezactivare si schimbare sigura a defaultului prin serviciu intern autorizat.
- Canonical, hreflang, Open Graph/social card, sitemap, robots pe medii si llms.txt.
- Comanda interactiva pentru primul administrator; accesul administrativ/Horizon ramane blocat pana la MFA.

Verificarea emailului, interfata admin pentru limbi, documentele reale, OCR/AI, reminderele si platile urmeaza in etapele urmatoare. Nu exista conturi implicite sau credentiale in surse.

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

Pe serverul de dezvoltare: [pagina publica](http://vaulty.test/ro), [autentificare](http://vaulty.test/ro/autentificare), [inregistrare](http://vaulty.test/ro/inregistrare). Nginx/hosts pentru `http://vaulty.test` sunt documentate separat in [docs/local-hosting.md](docs/local-hosting.md).

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

75 teste / 1038 asertiuni trecute, inclusiv cu rutele in cache. Serviciile externe sunt mockuite; testele folosesc SQLite in memorie. Probele locale MySQL/Redis sunt descrise in setup. Testul de traduceri verifica existenta fiecarei chei prin translator, nu doar paritatea dictionarelor.

## Git si configurare privata

Repository: Tiberiu96/document-vault-app. `composer.json` si `composer.lock` se versioneaza. `.env`, credentialele, `vendor/`, `.tools/`, cache-urile, logurile si continutul `storage/` sunt ignorate; doar fisierele `.gitignore` pentru structura runtime se pastreaza. CSS generat se construieste la instalare/deployment. Fonturile locale si licentele lor sunt versionate.

Reguli permanente: [AGENTS.md](AGENTS.md). Arhitectura: [docs/architecture.md](docs/architecture.md). Design: [docs/design-system.md](docs/design-system.md). Checkpoint UI/auth: [docs/web-foundation.md](docs/web-foundation.md).

Inregistrare si izolarea spatiilor: [docs/registration.md](docs/registration.md). Autentificare GitHub/push: [docs/github.md](docs/github.md). Pentru documentele private nu executa storage:link.

Bulgara: [pagina publica](http://vaulty.test/bg), [inregistrare](http://vaulty.test/bg/registratsiya), [autentificare](http://vaulty.test/bg/vhod). Textul functional are minimum 16px; antetul mobil are meniu compact si icon de profil. Vizualizarea directa prin instrumentul browser ramane blocata, chiar daca interfata setarilor arata Always allow.

Emailuri de cont: [confirmare/resetare si securitate](docs/account-email.md), [Mailpit si servicii locale](docs/integrations.md). Notificarile folosesc Redis/high si Horizon.

Biblioteca privata: [documente, cote si catalog](docs/document-library.md). AI local: [Ollama/Qwen si pagina de test](docs/local-ai.md).

Asistent privat optional: [configurare, securitate si pool PHP-FPM separat](docs/assistant.md). Streamingul nu trebuie rulat in productie pe acelasi pool limitat care serveste restul aplicatiei.
