# Emailuri si recuperarea contului

AccountMail este notificarea comuna pentru bun venit/confirmare email, resetarea
parolei si alerta de parola schimbata. Blade HTML cu tabele, CSS inline, fallback
Arial/Georgia, text minimum 16px si alternativa text simplu. Paleta foloseste
crem/bronze/sage, fara dependinte externe sau pixeli de tracking. Toate mesajele
au traduceri ro/en/hu/bg; limba cererii este pastrata in notificarea din coada.

## Comportament

- Inregistrarea emite Registered dupa tranzactia PF/PJ. Contractul MustVerifyEmail
  declanseaza automat notificarea personalizata. Panoul ramane accesibil pentru
  administrarea initiala si arata avertizarea pentru email neconfirmat.
- Confirmarea cere utilizator autentificat, id/email propriu si semnatura relativa
  cu expirare la 60 minute. Accesul altei persoane si semnaturile modificate sunt
  respinse. Confirmarea repetata este idempotenta. Documentele viitoare vor cere
  middleware verified; panoul nu este o dovada de email confirmat.
- Resetarea foloseste brokerul Laravel: token hashuit in DB, expirare 60 minute,
  consum unic si raspuns generic indiferent de existenta contului.
- Parola noua: 16-72 caractere, litere mari/mici, cifre, simboluri, maximum 72 bytes.
  Hash explicit inclusiv pentru input care seamana cu un hash existent.
- Resetarea roteste remember_token, revoca tokenurile Sanctum si sterge sesiunile
  DB. Middleware auth.session invalideaza la urmatoarea cerere sesiunile vechi
  care au memorat hashul anterior. Nu conecteaza automat utilizatorul.
- Confirmarea parolei si parola nu se pastreaza in old input; nici tokenul reset.
- Pagini auth: noindex, no-store si Referrer-Policy no-referrer pentru linkuri.
  URL-urile emailurilor folosesc numai APP_URL, nu Host primit de la client.

## Coada si limite

Toate cele trei tipuri folosesc ShouldQueue, ShouldBeEncrypted, high si afterCommit.
Se foloseste conexiunea de coada configurata (local Redis); worker Horizon high
este separat de OCR/AI. Nu folosi sync in mediul real. Timeout notificare 30s,
SMTP 15s, maximum 3 incercari, backoff 10/60/180s. Joburile pentru utilizatori
stersi sunt eliminate. Confirmarile pentru email deja verificat si resetarile cu
token invalid/consumat/expirat nu se mai livreaza la procesare.

SMTP are semantica cel putin o data: o intrerupere dupa acceptarea mesajului, dar
inainte de confirmarea jobului, poate produce un duplicat. Nu promitem exactly-once.
Nu extindem automat valabilitatea tokenului de reset la reincercare.

- Cerere reset: 3/ora/adresa normalizata si 20/ora/IP, comune intre limbi.
- Aplicare reset: 10/minut/IP.
- Retrimitere confirmare: 1/minut si 6/ora/utilizator.
- Acces confirmare: 10/minut/utilizator.

## Verificare

`vendor/bin/phpunit --filter=AccountSecurityTest` testeaza localizarea, semnaturile,
expirarea, proprietarul, resetarea, reutilizarea, parola, revocarea, coada high,
criptarea declarata, traducerile si escaparea HTML. Testele automate folosesc
SQLite/queue fake/array mailer si nu livreaza emailuri reale.

Local s-au trimis prin Redis/Horizon/high spre Mailpit emailuri pentru un cont
fictiv `preview-...@example.test`. Mesajele sunt pastrate intentionat pentru
inspectia utilizatorului. Parola aleatoare nu este salvata in clar sau comunicata.
Contul este unul de test local, fara drepturi de admin. Nu contine documente.

Vezi integrations.md pentru instalarea si administrarea serviciilor.
