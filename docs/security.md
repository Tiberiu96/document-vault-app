# Securitate

Fundatie livrata: hashing si comanda admin, flag global protejat la mass assignment, fara seed de conturi, stocare fara serve automat, Horizon inchis inclusiv local, Scribe fara rute publice si noindex global temporar. Login/logout web, rate limiting si WorkspacePolicy sunt implementate pentru panoul privat. MFA si restul operatiilor workspace/documente urmeaza; admin/Horizon raman blocate.

Private storage in afara public/, descarcare prin controller/policy, identificatori opaci unde sunt expusi. Scopare workspace plus Policy pe fiecare operatiune si job; niciun workspace_id din client considerat autorizare. Teste negative pentru IDOR.

Nu loga OCR, nume private, fisiere, tokenuri sau payloaduri AI. .env exclus din Git; exemplul nu contine credentiale. Nu crea storage:link spre documente. Uploadurile cer MIME/continut/marime/pagini si limite de resurse; unelte PDF/OCR izolate si actualizate inainte de productie.

## Initializare admin implementata

php artisan vault:admin:create solicita nume, email normalizat, parola ascunsa si confirmare ascunsa. Parola minimum 16 caractere si maximum 72 bytes (bcrypt), validata prin Laravel; Hash::make, fara argument CLI/env/log cu parola. Tranzactie; respinge email duplicat, fara promovare implicita a utilizatorilor existenti; non-interactiv refuzat. Niciun seeder admin.

Admin in productie refuzat pana la inrolare si verificare MFA. Comanda nu marcheaza MFA verificat. Inrolarea MFA trebuie sa fie accesibila intr-un flux separat securizat pentru a evita blocarea bootstrapului. Rute /admin prin auth, global gate, MFA, throttling, ro si noindex. Sesiuni securizate/HTTPS in productie, CSRF pentru web. Workspace admin nu este super_admin.

Teste necesare: parola slaba/neconfirmata, email duplicat, hash, lipsa credentialelor implicite, escaladare prin mass assignment, MFA neconfigurat, admin workspace respins, cross-workspace respins.

Inainte de productie: backup criptat, restaurare verificata, retentie/stergere explicita si audit fara continut privat. Nu declara conformitate juridica/GDPR fara evaluare dedicata.
