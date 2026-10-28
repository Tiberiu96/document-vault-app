# Backup productie: Restic si Cloudflare R2

Implementare pregatita, oprita implicit. Nu s-a accesat R2 si nu s-a efectuat o
restaurare reala de productie. Nu activa pe laptop sau pe staging. Turnstile este
un serviciu separat si nu este implementat/activat prin configuratia de backup.

## Configurare externa obligatorie

R2 Standard ofera nivel gratuit limitat; depasirile se factureaza. Creeaza un
bucket privat dedicat si un token S3 Object Read & Write limitat la acel bucket.
Nu activa acces public/r2.dev sau domeniu public. Foloseste cont Cloudflare cu MFA.
Pentru rezidenta stricta UE creeaza bucket cu jurisdictia EU si seteaza
BACKUP_R2_JURISDICTION=eu; un simplu location hint nu echivaleaza cu jurisdictia.

Configureaza direct pe server, fara valori in chat/Git:

- BACKUP_R2_ACCOUNT_ID, BACKUP_R2_BUCKET, BACKUP_R2_JURISDICTION (default sau eu).
- BACKUP_R2_ACCESS_KEY_ID si BACKUP_R2_SECRET_ACCESS_KEY: credentiale S3 dedicate.
- BACKUP_RESTIC_PASSWORD: parola separata de minimum 24 caractere; copiaza privat
  in managerul de parole. Restic nu poate recupera parola pierduta.
- BACKUP_ALERT_EMAIL: destinatar operational; providerul MAIL de productie trebuie
  configurat si verificat separat. Alertele sunt criptate in queue, pe high.
- BACKUP_UPLOAD_KIB=2048: plafon initial 2 MiB/s, de ajustat dupa masurare.
- BACKUP_PROCESS_TIMEOUT=3600: buget total al rularii, maximum configurabil 14400.

Pastreaza .env/config cache cu acces OS restrictiv. Nu copia configuratia secreta
in public sau in loguri. Credentialele proceselor sunt in environment/option file
0600, niciodata in argumentele CLI, stdout sau jurnalul systemd. Root si procesele
cu acces OS suficient raman de incredere; criptarea nu protejeaza un host compromis.

## Topologie acceptata si coerenta

Numai un server Linux, o instanta MySQL, Redis, stocare privata locala si workeri
Horizon/queue:work daemon din acelasi checkout, utilizator OS si filesystem de lock.
Fara workeri externi, octane, queue:work --once, scrieri directe in DB/storage de la
alte noduri sau schimbari concurente prin CLI/deploy. Configuratia actuala refuza
DB_URL si alti driveri DB/queue. Instalati runtime-ul verificat in ghidul de deploy.

BackupBarrier foloseste flock partajat: cererile HTTP si joburile in curs pot
rula concurent in mod normal. Inaintea snapshotului, un marker opreste workerii
la Looping, INAINTE de pop; nu consuma incercari si nu pierde joburi. Se asteapta
inchiderea operatiilor existente (maximum 360 secunde), apoi se obtine lock exclusiv.
In aceasta fereastra cererile HTTP primesc 503 + Retry-After 30, iar dispatchul
reminderelor se amana pana la urmatorul minut. Nu se promite zero impact sau
snapshot fara pauza: durata depinde de volumul real si viteza discului.

Cu lock exclusiv se exporta MySQL (--single-transaction/quick, fara lock global
al tabelelor) si se copiaza storage privat workspaces/ cu cp --reflink=auto.
Symlinkurile sunt refuzate. Se verifica spatiul liber pentru DB estimata, fisiere
si rezerva; fereastra totala de scanare/export/copiere are buget de 120 secunde. Datele temporare sunt 0700/0600, fara
cache, loguri, dependente, OCR temporar sau .env. recovery.json contine APP_KEY si
identitatea aplicatiei; este inclus NUMAI in backupul criptat, nu in starea admin.

Dupa snapshot se elibereaza lockul si markerul; HTTP/workerii revin INAINTE de
upload. Restic ruleaza cu nice/ionice, GOMAXPROCS=1, doua conexiuni S3 si plafon
upload. unitatea systemd limiteaza CPU/memorie si omoara tot grupul de procese la
oprire. Partea locala copiaza fisierele in staging chiar daca Restic deduplica
uploadul; pentru volume mai mari se va proiecta snapshot de filesystem separat.

APP_KEY este necesara decriptarii datelor Laravel. Pastreaza si o copie separata
in managerul de parole. Stagingul plaintext se sterge in finally; monitorul il
curata dupa intrerupere, numai cand lockul de rulare nu mai este detinut. Recuperarea
poate intarzia pana la urmatoarea verificare a monitorului. Swap/discul se protejeaza
separat la nivel OS. Copiile de fisiere originale nu se sterg de backup.

## Activare numai dupa verificarea serverului

1. Instaleaza Restic >=0.16, mysqldump compatibil cu serverul MySQL, coreutils si
   util-linux. Pe Ubuntu 24.04, dupa verificare: sudo apt install restic mysql-client
   coreutils util-linux. Aceasta instalare NU a fost executata local.
2. Valideaza SMTP, permisiunile, spatiul pe disc, topologia single-host si faptul ca
   toti workerii folosesc noul cod. Seteaza BACKUP_SINGLE_HOST_CONFIRMED=true si
   BACKUP_ENABLED=true numai pe productie, apoi config:cache si restart FPM/Horizon/
   scheduler. Nu activa timerul inainte de acest restart.
3. Ca utilizatorul OS al aplicatiei, ruleaza php artisan vault:backup init. Cere
   confirmare interactiva, nu sterge/forteaza repository existent.
4. Ruleaza manual php artisan vault:backup run, intr-o fereastra aprobata. Masoara
   pauza snapshotului si durata totala. Prima copie poate depasi 15 minute chiar
   daca backupurile incrementale ulterioare sunt rapide. Ajusteaza bugetul/unitatea
   numai dupa masurare; nu se garanteaza durata sau incadrarea in free tier.
5. Adapteaza deploy/systemd/vaulty-backup{,-monitor}.{service,timer}: utilizator,
   grup, cale, PHP si numele serviciilor reale. Nu copia modelele locale systemd.
   Instaleaza system-wide, daemon-reload, apoi enable --now pentru ambele timers.
   Backup: 23:30 Europe/Bucharest, Persistent=false (fara recuperare automata ziua a unei rulari ratate). Monitor: la fiecare minut,
   proces separat, fara dependenta de schedulerul Laravel. Nu crea si cron duplicat.
6. Verifica systemctl list-timers si /admin/backup (global admin + MFA). Nu activa
   pe productie fara testul de restaurare si confirmarea conexiunii R2.

Cand exista deja un backup se pot folosi aceleasi credentiale; confirma bucketul
si repository-ul, nu initializa alt repository peste el. Nu trimite valorile in chat.

## Retentie si esecuri

Fiecare run porneste cu tag candidate si tag unic. Numai exit 0, summary valid si
restic check reusit permit tagul verified. Tag modifica ID-ul snapshotului; se
reciteste ID-ul final. Abia apoi se aplica forget/prune pe verified, host stabil,
grupare dupa host (nu calea staging sau tagul unic), keep-last=2. Copiile comune
raman referentiate corect. O eroare de cleanup produce cleanup_warning, pastrand
ultima reusita. Nu expira obiectele R2 arbitrar dupa doua zile: ar rupe blocurile
partajate. Sunt doua COPII ZILNICE REUSITE; la esec pot ramane mai vechi de 48 ore.

Snapshoturile candidate dupa o intrerupere se pastreaza pentru reconciliere manuala
si pot consuma spatiu suplimentar. Nu sunt numarate drept copii verificate. Cheia
S3 Read & Write permite si stergere; acest backend simplu NU este append-only si
nu ofera protectie garantata la ransomware. Pastreaza backupul independent al
hostului si accesul Cloudflare separat, cu MFA. Nu activa Bucket Lock arbitrar
peste repository: poate impiedica operatiile Restic de lock/prune.

## Monitorizare

Stare sanitizata locala, scriere atomica sub lock: inceput/final, etapa, ultima
reusita, durata, bytes procesati/adaugati, cod diagnostic. Fara output brut Restic,
DB, cai interne, parole sau emailuri private in pagina admin. Monitorul observa
rulare >900s (avertizare, nu oprire), lipsa pornirii dupa 23:45, lipsa unei reusite
>27h, esec sau proces intrerupt. Un run-lock eliberat de OS identifica oprirea;
se elibereaza markerul si stagingul. Email high deduplicat per run/motiv/fereastra.
Daca Redis/email nu functioneaza, alerta poate esua; panoul/jurnalul sanitizat si
monitorizarea externa raman necesare. Un server complet oprit nu poate alerta
singur; heartbeat extern nu este configurat prin aceasta etapa.

## Restaurare de proba, obligatorie inainte de lansare

Pe mediu izolat, cu email, AI, push, scheduler si workerii opriti, foloseste
repository/parola din managerul de parole si restaureaza snapshot verified intr-un
director privat gol (restic restore ID --target DIRECTOR). Structura include calea
absoluta staging; identifica database.sql, documents/ si recovery.json acolo.
Nu restaura direct peste productie. Importa SQL intr-o baza noua, copiaza documents/
in storage privat workspaces/, seteaza APP_KEY salvata si credentiale noi pentru
mediu, apoi migrate/status, verifica login/decriptare/fisiere si cote/remindere.
Revoca/invalideaza sesiunile/tokenurile restaurate conform procedurii de incident;
nu reporni automat livrarile istorice incerte. Redis nu este inclus; reconcilierea
joburilor/reminderelor se face inainte de restart, pentru evitarea duplicatelor.
Compara numarul documentelor si hashurile originalelor. Noteaza durata recuperarii.
O lista de snapshoturi sau un check de metadata nu inlocuieste acest test.

Surse primare:
- https://developers.cloudflare.com/r2/pricing/
- https://developers.cloudflare.com/r2/get-started/
- https://restic.readthedocs.io/en/stable/
- https://developers.cloudflare.com/r2/api/tokens/
- https://developers.cloudflare.com/r2/reference/data-location/
