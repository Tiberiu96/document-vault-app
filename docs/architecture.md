# Arhitectura si checkpoint

Etapa curenta: Laravel 13.39.0 si pachetele sunt integrate, cu migrari executabile pentru users/workspaces/companies/memberships, tokenuri Sanctum si comanda admin. Schema si controalele initiale au teste SQLite; migrarile MySQL, conexiunile MySQL/Redis, constrangerea de unicitate personala si cache/lock Redis sunt verificate local. Interfata publica si login/logout cu panou privat sunt implementate. WorkspacePolicy protejeaza lista spatiilor; inregistrarea PF/PJ si selectarea spatiului sunt livrate; confirmarea emailului si resetarea parolei sunt implementate prin Redis/high si Mailpit local; biblioteca privata de documente si conexiunea Ollama sunt livrate; OCR local si propuneri AI cu confirmare sunt implementate; Administrarea platformei cu guard separat, MFA, dashboard operational si configurarea cataloagelor/limbilor/limitelor Free este implementata; vezi admin.md.

Monolit Laravel pentru web/API/joburi, cu mobil separat. Folosim conventiile frameworkului si servicii de domeniu pentru operatii complexe. Nu introducem microservicii.

## Structura propusa

```text
app/
  Actions/{Auth,Workspaces,Documents,Admin}/
  Console/Commands/
  Contracts/{Ocr,Ai,ExchangeRates}/
  Enums/
  Http/{Controllers,Middleware,Requests,Resources}/
  Jobs/{Documents,Notifications,Maintenance}/
  Livewire/{Public,Workspace,Admin}/
  Models/
  Notifications/
  Policies/
  Services/{Documents,Quotas,Ocr,Ai,ExchangeRates,Analytics}/
bootstrap/  config/  database/{factories,migrations,seeders}/
lang/{ro,en,hu,bg}/
resources/{css,views/components,views/layouts}/
routes/{web,admin,api,console}.php
storage/app/private/
tests/{Feature,Unit}/
mobile/                 # creat doar la etapa 9
docs/
```

Crearea directoarelor de domeniu se face cand exista cod care le foloseste.

## Migrari initiale implementate

| Tabel | Coloane si constrangeri |
| --- | --- |
| users | id bigint PK, name varchar(255), email varchar(255) unique normalizat, email_verified_at nullable, password hash varchar(255), is_super_admin bool default false, remember_token nullable, timestamps |
| workspaces | id bigint PK, owner_id FK users restrict delete, type enum personal/business, name varchar(255), personal_owner_id generat unique, timestamps; index owner_id/type |
| companies | id bigint PK, workspace_id FK workspaces unique restrict delete, legal_name, cui normalizat, registration_number, registered_address (date structurate), country_code char(2), timestamps |
| memberships | id bigint PK, workspace_id FK workspaces cascade, user_id FK users cascade, role enum owner/admin/member/accountant, timestamps; unique(workspace_id,user_id), index(user_id,workspace_id) |

Ordine up: users, workspaces, companies, memberships; down in ordine inversa. Sesiunile, resetarea parolelor, cache/jobs/failed_jobs folosesc migrarile oficiale Laravel adaptate; nu duplica migrarea users a scheletului. Tokenurile Sanctum provin din migrarea pachetului, copiata in proiect.

Unicitatea unui workspace personal per utilizator foloseste personal_owner_id generat din owner_id numai pentru type=personal, cu index unic (testat SQLite si MySQL; testarea concurentei reale urmeaza); nu impune unique(owner_id,type), care ar interzice mai multe firme. Ownerul are membership owner creat in aceeasi tranzactie. Schimbarea proprietarului si iesirea ultimului owner cer workflow atomic. Datele companiei apartin numai unui workspace business; CUI validat server-side, fara integrare ANAF presupusa. Stergerea contului/workspaceului necesita workflow de retentie, nu stergere accidentala a documentelor.

Rolul global nu este mass-assignable. Nu exista bypass global automat pentru accesul la documente private; operatiile suport cer autorizare explicita si audit.

PF: tranzactie user + personal + owner membership. PJ: aceeasi tranzactie plus business + company + owner membership. Idempotenta si rollback complet la eroare.

## Ordinea livrarilor

1. Runtime si servicii confirmate, Laravel/lockfile, migrari, comanda admin, teste fundatie.
2. Design/Blade/Livewire, localizare si structura limbilor; SEO al primei pagini publice.
3. Autentificare/PF/PJ/policies/izolare.
4. Documente private/categorii/cote; apoi OCR, AI optional, notificari, moneda/abonamente, mobil, validare SEO/analytics si hardening.

Nu sunt necesare conturi AI, Firebase, plati, analytics sau hosting la instalarea runtime-ului.

RegisterRequest/RegisterUser implementeaza company numai pe business, creare atomica PF/PJ si membership owner sincronizat. Endpointurile localizate de inregistrare si selectare a spatiului sunt livrate; detalii in registration.md.

Checkpoint web/auth si structura limbilor sunt livrate conform web-foundation.md. Git este autorizat de utilizator pentru commit/push; .env, runtime si dependentele instalate raman excluse.

Checkpoint biblioteca: document-library.md. Integrare Ollama si diagnostic local: local-ai.md.

Checkpoint procesare: document-processing.md. Acces temporar de pe telefon: phone-testing.md.

Provider remote optional Groq si configurare: groq.md. Joburile fixeaza providerul la solicitare.

Scanner web multipagina: captura/galerie, rotire, crop manual si PDF privat prin FPDF1.9; flux draft cu confirmare. Nu inlocuieste scannerul nativ Expo sau corectia automata de perspectiva.

Checkpoint sursa draft: migrarea 000012 adauga source_released_at/source_revision.
Analiza elimina copia temporara; confirmarea retrimite originalul identic.
Lockurile si reviziile protejeaza fisierul final de joburi tardive; detalii si
limitarile retinerii in memoria browserului in document-processing.md.

Checkpoint notificari: docs/reminders.md. Migrarea 000013 adauga reminderul per
document, cicluri/livrari deduplicate si notificari database Laravel. Scheduler
local, high, email Mailpit si actiuni web; programul aprobat este 10/3/1 zile.

Checkpoint inbox: Laravel Notifications + Livewire pentru clopotel, polling la 30s numai in tab vizibil, centru si preferinte localizate. Migrarea 000015; detalii in notifications.md.

Checkpoint backup productie: runner Restic/R2, bariera filesystem single-host pentru snapshot coerent, monitor separat si pagina admin. Configurarea externa/conexiunea si restaurarea reala raman de verificat in productie; vezi backups.md.

Checkpoint colaborare: migrarea 000016, invitatii business cu token hash, email verificat,
acceptare atomica, roluri si revocare. Notificari email high si inbox pentru primire si
acceptare; vezi workspace-invitations.md.

Checkpoint cote: migrarea 000017, plan efectiv per workspace, sablon Pro dezactivat,
atribuire manuala auditata si pagina de consum ro/en/hu/bg. Upload/scanner/confirmare
si rezervarile OCR/AI folosesc acelasi plan. Vezi plans-and-usage.md; integrarea Stripe
este livrata ulterior, oprita implicit si separata de atribuirile manuale.

Checkpoint cont: sectiunea privata Contul meu, nume editabil, email blocat,
schimbare parola cu parola actuala si avatar unic privat de maximum 5 MiB.
Migrarea 000018, GD pentru reencodare si cleanup low; vezi account-profile.md.

Checkpoint Business: cinci locuri incluse cu proprietarul si invitatiile active
rezervate; capacitate suplimentara manuala Pro / Business din admin, cu pret
lunar in bani RON configurabil, MFA/confirmare/audit. Migrarea 000019.
Stripe este o etapa separata livrata prin migrarea 000022: plata in RON/EUR/HUF,
preselectie dupa limba, alegere explicita, pret final egal cu suma facturata si
inbox durabil pentru webhookuri.
Detalii si restrictii: plans-and-usage.md.

Checkpoint upgrade: Plan si consum ofera solicitari private adaptate planului
si tipului spatiului; numai proprietarul trimite, adminul aproba/refuza manual.
Migrarea 000020, cerere pending deduplicata sub lock, fara activare/plata automata.
Vezi plans-and-usage.md.

Checkpoint navigatie: antet si meniu autentificat comune pe public/home si in
aplicatie, cu selector de workspace autorizat, clopotel si avatar circular ca
unic declansator al meniului pe desktop si mobil.
Dropdown-urile independente au fost eliminate din layouturile autentificate;
paginile publice personalizate folosesc auth.session si cache privat. Detalii
si verificari in web-foundation.md.

Checkpoint asistent: `laravel/ai` cu Ollama/Gemini configurabil, unelte strict
scopate la utilizatorul si workspace-ul autentificat, SSE sanitizat, limite Redis,
consum de tokenuri si panou Blade/JavaScript localizat. Administrarea consumului
foloseste agregari SQL filtrate si paginate, cu provider/model si stare pentru
cererile noi; istoricul necunoscut nu este completat artificial. Conversatia nu
este persistata. Configurare si operare: docs/assistant.md.

Checkpoint Stripe: SDK oficial, preturi lunare explicite RON/EUR/HUF administrate cu
MFA, sumar server-side, Checkout si inbox webhook criptat/idempotent. Drepturile Pro si
locurile platite se sincronizeaza numai din webhookuri. Integrarea este oprita implicit;
configurare, politici si verificare sandbox in docs/stripe.md.
