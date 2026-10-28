# Notificari web

Centrul localizat /ro/notificari (en/hu/bg au sluguri proprii), cu noindex si acces
numai prin autentificare, email verificat si spatiu autorizat. Un clopotel Livewire
in antet afiseaza ultimele cinci evenimente si numarul de necitite din spatiul activ.
Pagina completa are filtrare Toate/Necitite, paginare si preferinte per utilizator.

## Livrare si actualizare

Laravel Notifications persista mesajele in MySQL. Canalul database este extins
numai pentru DocumentReminder/WorkspaceAlert: workspace_id indexat si ID determinist
pentru deduplicare. Payloadurile contin ID-uri, tip si date necesare; nu stocheaza
text OCR, cai interne, nume de fisiere sau exceptii. Titlul documentului se citeste
la afisare numai dupa autorizare. Notificarile pentru documente sterse nu se mai
afiseaza si se elimina in fluxul de stergere. Migrarea 000015 atribuie workspace
si ciclu notificarilor reminder deja existente, numai pentru destinatarul corect.

Clopotelul foloseste Livewire 4 deja instalat, fara WebSocket, Reverb, servicii
externe sau dependente noi. Un interval de 30 secunde apeleaza refreshInbox numai
cand document.hidden este false si browserul este online. Timerul se curata la
distrugerea componentei. Inchiderea/deschiderea listei nu marcheaza citit.
Nu exista cerere de permisiune pentru push de browser. Livewire transporta
actualizarea prin POST cu CSRF; contextul utilizator/spatiu/limba este verificat
server-side la fiecare apel, inclusiv dupa revocarea apartenentei. Proprietatile
workspaceId/locale sunt Locked; snapshoturile vechi dupa schimbarea spatiului se
refuza. Middleware auth.session/verified/workspace/locale sunt persistente.
Limite separate pentru polling si actiuni; sesiunea de asistenta ramane read-only.

## Evenimente livrate

- Reminder la 10/3/1 zile: creat din DeliverReminder, pe high. Deschiderea detaliilor
  marcheaza citit; bifarea Citit pastreaza istoricul si programarile.
- Procesare OCR/AI esuata definitiv: numai document confirmat si destinatar cu
  acces actual. O notificare per processing run/utilizator, fara eroare bruta.
  Drafturile raman in fluxul lor de procesare si nu aglomereaza inboxul.
- Stocare cel putin 90%: la confirmarea documentului, cel mult o notificare per
  utilizator/spatiu/luna UTC. Fara mesaje la fiecare upload sau procesare reusita.

Invitatiile, notificarea pragurilor OCR/AI si push/FCM vor veni odata cu fluxurile
respective; nu sunt simulate ca integrari functionale in aceasta etapa.

## Citit, rezolvare si preferinte

Citit este per destinatar si nu opreste reminderul. Marcheaza toate citite se
aplica numai notificarilor vizibile ale utilizatorului din spatiul curent.
Marcheaza rezolvat/Opreste/Amana folosesc ReminderService si aceleasi lockuri,
Policies si reguli de ciclu. Ciclul din notificare trebuie sa coincida cu cel
curent: un mesaj vechi nu poate inchide un reminder reinnoit. Istoricul ramane.

Preferinte pentru remindere in aplicatie/email, procesari esuate si stocare.
Se aplica evenimentelor viitoare din toate spatiile contului; emailurile de
securitate nu sunt afectate. Emailul ramane conditionat si de configuratia globala
reminders.email_enabled. Cu ambele canale oprite, livrarea este skipped, fara
marcare falsa in_app_at/email_sent_at. Reactivarea nu retrimite sloturile trecute.

/admin/notificari ofera administratorului global cu MFA o lista de programari,
stari si rezultat pe canale, numai ID-uri si date operationale. Nu expune continutul
emailurilor sau al documentelor si nu retrimite livrarile SMTP incerte.

## Verificare

NotificationInboxTest verifica izolarea, contextul Livewire, deduplicarea,
separarea citit/rezolvat, ciclurile vechi, oprirea/amanarea, preferintele, stergerea
si traducerile. Serviciile externe sunt mockuite. Testarea vizuala foloseste un
cont local fictiv, fara modificarea documentelor personale sau apeluri AI.
