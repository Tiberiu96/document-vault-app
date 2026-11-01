# Administrarea platformei

URL: /admin/autentificare. Romana fara diacritice, fara prefix de limba.
Nu exista conturi implicite. Foloseste contul creat interactiv prin
`php artisan vault:admin:create`; utilizatorii existenti nu sunt promovati automat.
Administratorul de workspace nu primeste rol global.

## Acces si MFA

Guard de sesiune separat `admin`. Parola deschide numai etapa MFA pentru maximum
10 minute. La prima accesare, configureaza o aplicatie TOTP existenta prin QR sau
cheie manuala. QR-ul se genereaza local, fara servicii externe. Nu este necesar
un cont la un provider MFA. Biblioteci: pragmarx/google2fa si bacon/bacon-qr-code,
versionate in composer.lock. Datele TOTP raman in browserul administratorului si
in stocarea criptata Laravel; nu trimite cheia/QR-ul in conversatii sau capturi.

MFA este obligatoriu in toate mediile, inclusiv local. Productia cere suplimentar
HTTPS si SESSION_SECURE_COOKIE=true. Configureaza proxy-urile de incredere numai
pentru infrastructura proprie. Pastreaza APP_KEY in secret si in backup separat;
pierderea sa face secretele criptate inutilizabile. Secretul pending din sesiune
este criptat; secretul confirmat si hashurile codurilor de recuperare sunt
criptate in DB. Campurile MFA sunt ascunse din serializarea modelului User.

Dupa inrolare, opt coduri de recuperare apar o singura data. Salveaza-le intr-un
manager de parole. Fiecare cod se consuma atomic o singura data; utilizarea unui
cod invalideaza celelalte sesiuni administrative. Codurile TOTP deja folosite nu
pot fi refolosite. Generarea unui set nou cere parola curenta si un TOTP nou si
invalideaza setul vechi si celelalte sesiuni. Formularul nu repopuleaza secretele.

Sesiunea expira dupa o ora sau 30 de minute de inactivitate. Schimbarea parolei,
retragerea rolului global si resetarea MFA invalideaza accesul. Loginul si MFA au
limite separate de incercari, cu raspuns 429 in romana. Toate operatiile web au
CSRF; paginile sunt noindex/no-store, cu no-referrer. Erorile nu afiseaza debug.

Recuperare cu acces autorizat la server, numai in terminal interactiv:

```bash
php artisan vault:admin:mfa-reset
```

Comanda cere email, parola curenta ascunsa si confirmare explicita. Nu are
argument cu parola, nu promoveaza utilizatori si nu schimba parola. Apoi refa
inrolarea MFA in browser. Protejeaza accesul SSH si backupurile serverului.

## Ecrane livrate

- Prezentare: numar utilizatori/spatii/documente, stocare, remindere, joburi esuate,
  adancimea cozilor, semnal Horizon si heartbeat Scheduler (maximum 3 minute).
  Lipsa conexiunii se afiseaza necunoscuta, fara presupuneri de functionare.
- Utilizatori: lista paginata, creare client PF cu spatiu personal si emailuri
  pentru alegerea parolei/confirmare pe high, editare nume/email si stergere
  confirmata a conturilor goale. Schimbarea emailului revoca sesiunile/tokenurile,
  elimina reseturile vechi si cere o noua confirmare. Parola curenta a adminului
  este obligatorie la creare/editare/stergere/accesarea contului. Conturile globale
  sunt excluse din aceste operatii. Nu se afiseaza sau alege parola clientului.
  Conturile cu documente/procesari/firma sunt protejate la stergere, pentru un flux
  ulterior explicit de retentie/transfer. Spatiile de lucru sunt numai citire.
- Acceseaza contul: sesiune web separata pentru client, fara parola sau preluarea
  cookie-urilor/sesiunilor sale existente. Banner vizibil si oprire prin POST cu
  CSRF. Numai consultare; toate scrierile si confirmarea emailului sunt blocate,
  iar adminul este inaccesibil pana la oprire. Actorul ramane global/MFA verificat,
  cu timeout/idle si revocare la retragerea rolului. Start/stop/expirare sunt
  auditate cu ID-uri. Oprirea nu schimba parola/tokenul remember al clientului.
  Inaintea adaugarii de API-uri accesibile prin sesiune, aplica aceeasi protectie
  si acolo; nu exista momentan un API de scriere pentru aceasta sesiune.
- Categorii/etichete globale: creare, editare traduceri ro/en/hu/bg, stergere
  confirmata. Cheile sunt imuabile. Nu se editeaza catalogul privat al unui
  workspace. Stergerea categoriei elimina asocierea; nu sterge documentele.
- Limbi: creare inactiva, editare, disponibilitate si inlocuirea sigura a limbii
  implicite. Inregistrarea DB nu livreaza traduceri/rute. Limbile din manifest se
  elimina numai prin deploy; cele suplimentare inactive pot fi sterse. Selectorul,
  sitemapul si hreflang folosesc disponibilitatea actuala, inclusiv cu route cache.
- Limite Free: suprascrieri DB centralizate in PlanLimitsService, cu versiune pentru
  prevenirea suprascrierilor concurente. Fara stergerea fisierelor existente.
  Cota lunara -1 este permisa numai local; in productie se refuza si se aplica
  fallback sigur daca baza a fost copiata din local. Uploadul ramane plafonat la
  5 MiB pana la ajustarea explicita a infrastructurii Nginx/PHP. Setarile DB au
  prioritate fata de configuratia initiala, inclusiv pentru workerii persistenti.
- Consum asistent AI: filtre validate pe perioada, utilizator si workspace,
  totaluri care disting consumul complet, partial si necunoscut, stari separate
  pentru cereri finalizate, esuate si intrerupte si agregare paginata pe
  utilizator/workspace/provider/model. Nu afiseaza continut privat sau costuri.

Horizon brut ramane inchis: payloadurile si exceptiile sale pot contine date
private. Panoul propriu afiseaza numai ID-uri si coduri de eroare cunoscute.
Reprocesarea cere confirmare, eroare reluabila, document confirmat, email verificat
si permisiunea normala de modificare in workspace, pe langa MFA si rolul global.
Nu ocoleste cotele, deduplicarea sau izolarea. Un super_admin nu primeste acces
implicit la continutul documentelor. Reincercarile folosesc fluxul obisnuit.

Auditul pastreaza actor ID, actiune, tip/ID resursa si data. Nu pastreaza parole,
QR, coduri, text OCR, nume private de fisiere, payloaduri sau exceptii brute.
Executa seederele initiale la instalare; rerularea lor poate recrea elemente
predefinite sterse intentionat din catalog. Nu sunt parte din fiecare git pull.

Livrari notificari: /admin/notificari afiseaza paginat programarea, starea, destinatarii prin ID si rezultatul in-app/email, fara continut privat.

Liste si filtre: spatii (nume/proprietar/email, tip, plan atribuit), utilizatori
(nume/email, confirmare email, rol global), livrari (stare, ID spatiu, interval
programare), catalog (nume/cheie si tip), limbi (nume/cod si disponibilitate).
Procesarile esuate si auditul au filtre si paginatoare independente failed_page/audit_page.
Tabelul agregat de consum are filtru tip/stare si paginare. Listele pastreaza query-ul
filtrelor, folosesc ordine stabila si nu schimba permisiunile sau datele afisate.
Tabelele admin impun incadrarea textului in celula; cel de spatii are coloane
proportionale si derulare orizontala pe ecrane inguste.

Logo-ul Vaulty din antetul admin deschide pagina publica home in romana intr-un
tab nou (target=_blank, rel=noopener noreferrer). Panoul ramane deschis.
