# Migrare completa Vaulty - Ubuntu 24.04

Fisierul standalone este `deploy/bootstrap/migrare_finala_vaulty_24.04.sh`.
Copia pentru utilizator este pe Desktop, cu acelasi nume. Nu necesita celelalte
fisiere din repository pentru pornire: contine componentele comprimate si hashurile
lor. Nu contine credentiale. Varianta 24.04 este numai pentru Ubuntu 24.04 LTS x86_64
cu systemd; nu pretinde suport pentru alte versiuni sau ARM.

## Ce automatizeaza

- Runtime-ul deja folosit: PHP 8.3, Composer, MySQL, Redis, Nginx, OCR/Tesseract
  ron/eng/hun, OCRmyPDF, PDF tools, Restic, Certbot si Tailwind standalone verificat.
- Checkout Git la SHA-ul exportat; instalarea noua fara export foloseste main.
- Cont runtime vaulty fara login; operator SSH existent, ACL pentru .env/storage.
- Baza/utilizator MySQL dedicat, parola aleatorie noua, fara afisare sau argumente CLI.
- APP_KEY pastrat la import; cheie noua numai la instalare fara date importate.
- Configurare SMTP, Gemini, R2 si Turnstile cu intrebari locale; secrete ascunse.
  La import se poate pastra configurarea veche. Nu cere credentiale in chat.
- Nginx cu HTTP/2, certificat TLS, reinnoire automata si hook reload Nginx.
  Access log fara URI/query, pentru a nu expune tokenuri auth. Documentele sunt private;
  nu creeaza storage:link.
- Pool FPM, Horizon si scheduler systemd. Workeri separati conform config/horizon.php,
  OMP_THREAD_LIMIT=1 si oprire Horizon cu 450 secunde pentru drenarea joburilor.
- Backup zilnic la 23:30 Europe/Bucharest, monitor independent si restore runner;
  se activeaza numai dupa acces R2 si un backup verificat. Retentie ultimele doua copii.
- CD optional: helper root-owned adaptat noului operator/domeniu, cheie SSH restrictionata,
  secrete GitHub trimise direct prin gh, fara copierea cheii private in conversatie.
  Nu elimina protectiile unui environment existent. AUTO_DEPLOY_ENABLED ramane false
  pana la confirmarea activarii. Workflow-ul livreaza numai dupa CI verde.

## 1. Verificare fara modificari

```bash
bash migrare_finala_vaulty_24.04.sh --dry-run
bash migrare_finala_vaulty_24.04.sh --verify
```

--verify verifica pachetul inclus si sintaxa componentelor. Nu este o instalare sau
o migrare reala si nu contacteaza serviciile externe.

## 2. Export de pe serverul vechi

Sursa trebuie sa fie productie/single-host confirmat, cu MySQL local dedicat si
APP_KEY din .env identic cu cel incarcat in configuratie. Preflight-ul ruleaza inainte
de oprirea serviciilor; nu se exporta o instalare locala de dezvoltare.
Planifica o fereastra de mentenanta. Nu modifica simultan codul sau datele prin alte
terminale. CD trebuie oprit; scriptul il poate opri prin gh daca operatorul este deja
autentificat, altfel cere confirmarea opririi din GitHub.

```bash
sudo bash migrare_finala_vaulty_24.04.sh --export
```

Se cer parola arhivei (minimum 24 caractere) si confirmarea ei. Scriptul ia lockurile
de deploy/restore, opreste timerele/path unit, pune down, dreneaza si dezactiveaza
workerii/schedulerul, opreste FPM. Backupurile/restaurarile active blocheaza operatia.
Foloseste bariera si snapshot-ul DB/documente din aplicatie; valideaza cheia din .env
fata de configuratia incarcata. Exportul include setarile .env, SQL, workspaces,
APP_KEY, SHA Git, lista migrarilor si hashurile fisierelor. Arhiva este criptata GPG
cu AES256; parola nu este salvata. Niciun fisier de log cu continut privat nu este exportat.

Rezultatul este in `/var/backups/vaulty-migration/*.tar.gz.gpg`. Operatorul are ACL
de citire numai pentru arhiva criptata; continutul decriptat temporar se elimina la iesire.
Scriptul afiseaza calea si SHA-256. Pastreaza parola separat, in afara ambelor servere.
La intreruperea brusca a VM, verifica directoarele private temporare ramase in
/var/tmp/vaulty-migration.*; nu expune continutul lor.

Sursa ramane in mentenanta, cu workerii/timerele dezactivate inclusiv dupa reboot.
Nu o redeschide in paralel cu destinatia. Daca migrarea este anulata, --resume-source
cere confirmare explicita ca destinatia este oprita. Daca destinatia a primit date noi,
nu reveni la sursa veche fara un export invers/reconciliere: altfel acele date se pierd.
CD si DNS trebuie reconfigurate separat daca revii la serverul vechi.

## 3. Transfer privat si instalare pe VM noua

Transfera prin SCP/SFTP fisierul .sh si arhiva criptata; verifica identitatea SSH a
destinatiei. Exemple, cu propriul utilizator/IP si calea afisata de export:

```bash
scp migrare_finala_vaulty_24.04.sh utilizator@IP_NOU:~/
scp /var/backups/vaulty-migration/NUME_EXPORT.tar.gz.gpg utilizator@IP_NOU:~/
```

Pe destinatie trebuie sa existe deja un cont SSH normal cu sudo si home; nu se
activeaza root login. Necesita o VM dedicata, libera, cu suficient spatiu pentru
arhiva, datele decriptate, DB si documente. Recomandarea folosita in beta este 2 vCPU,
8 GB RAM si cel putin 30 GB disc, ajustate la datele reale. Scriptul nu redimensioneaza VM.

```bash
sudo bash migrare_finala_vaulty_24.04.sh --install
```

Alege import, da calea absoluta a arhivei si parola ei. La instalare fara import
raspunde nu: se creeaza o instalare noua fara conturi/parole implicite. Scriptul cere
operatorul, domeniul, IPv4 public, emailul Certbot si numele noii baze/utilizatorului.
Importul pastreaza APP_KEY si conturile/MFA; nu copia credentialele MySQL vechi.

La pasul DNS schimba A spre IP-ul nou si asteapta propagarea. Scriptul verifica A;
refuza AAAA deoarece aceasta varianta configureaza IPv4, fara sa pretinda verificarea
unui IPv6 public necunoscut. Elimina AAAA sau configureaza IPv6 separat inainte de TLS.
Deschide 80/443 in firewall/VPC; SSH port 22 trebuie accesibil runnerilor pentru CD.
Scriptul nu modifica regulile providerului, daemonul SSH sau firewall-ul existent.
Certificatele sunt emise din nou prin Certbot, supuse limitelor Let's Encrypt.

Se verifica schema SQL, migrarile, APP_KEY, campurile criptate si hashurile tuturor
documentelor confirmate. Importul foloseste contul MySQL al aplicatiei, nu root.
Sesiunile/tokenurile vechi sunt invalidate; utilizatorii se autentifica din nou.
Conturile administratorilor pastreaza MFA. Instalarea noua ofera comanda interactiva
vault:admin:create; parola ramane ascunsa si este hashuita de Laravel.

## Cozi si notificari la migrare

Redis nu este exportat/importat. Scriptul avertizeaza explicit asupra numarului de
joburi ramase si cere acordul pentru continuare. Emailurile queued, inclusiv cele
de confirmare/invitatie, trebuie retrimise explicit; nu sunt trimise automat a doua oara.
Livrarile de reminder deja preluate devin uncertain, pentru verificare. Reminderele
viitoare pending si istoricul existent sunt pastrate. Procesarile OCR/AI neterminate
devin failed cu MIGRATION_REQUIRES_REVIEW, fara rezervari de cota blocate.
Se reconstruieste numai curatarea idempotenta a stergerilor solicitate/drafturilor si
preview-urile neterminate. Nu se reiau implicit apeluri AI externe sau plati.

Nu se importa istoricul Horizon, logurile sistemului, joburile Redis, fisierele din
home sau cheile SSH ale serverului vechi. R2 ramane acelasi repository extern daca
pastrezi configuratia lui; nu copia manual obiectele Restic si nu schimba parola lui.

## Reluare dupa eroare

Orice esec opreste instalarea. Daca aplicatia destinatiei a fost deja creata, scriptul
pastreaza mentenanta si opreste/dezactiveaza workerii/timerele. Nu foloseste chmod 777
si nu incearca rollback orb sau stergerea datelor instalate anterior.

```bash
sudo bash migrare_finala_vaulty_24.04.sh --resume
```

Configuratia pentru reluare este `/var/lib/vaulty-migration/target.json`, root-only
0700/0600, cu credentialele generate. Nu afisa si nu trimite acest fisier in chat.
Reluarea poate corecta domeniul/IP/emailul sau setarile providerilor prin prompturi,
dar nu genereaza alta APP_KEY sau parola DB. Daca importul nu a fost finalizat, cere
din nou arhiva/parola. Dupa import reusit nu mai necesita decriptarea ei.
Etapele runtime/DB/date/TLS au marcaje; importul incomplet poate fi reluat numai pe
destinatia creata de script, in mentenanta. O instalare finalizata nu este reinstalata
cu --resume: actualizeaz-o prin CD. O alta instalare existenta este refuzata.

## Verificare finala

Scriptul verifica DB/Redis, HTTPS cu HTTP/2, FPM/Horizon/scheduler si reinnoirea TLS
prin dry-run. Emailul real si inferenta AI sunt teste optionale cu acord explicit;
inferenta consuma cota providerului. Backupul R2 se testeaza real doar daca a fost
configurat, apoi se activeaza timerele. Prima livrare CD ramane de confirmat in Actions.
Testeaza manual login/MFA, un document existent si un upload nou inainte de a inchide
vechea VM. Nu sterge sursa sau arhiva criptata doar pentru ca installerul a terminat.

## Mentenanta scriptului

Surse: deploy/bootstrap/migration/, runtime/helper-ele existente si template-uri
systemd. Regenereaza standalone dupa modificarea oricarei componente incluse:

```bash
python3 deploy/bootstrap/build-migration.py
python3 -m unittest discover -s tests/Infrastructure -p 'test_migration.py' -v
```

CI verifica reproducibilitatea pachetului, arhive malitioase (traversari/linkuri),
hashuri, citarea .env, transmiterea credentialelor numai prin stdin, refuzul DB
existente si criptarea/decriptarea GPG cu date fictive. Nu executa instalari root,
nu modifica servicii si nu face apeluri SMTP/AI/R2 reale. Un --verify local si teste
offline reusite nu sunt declarate drept migrare completa verificata pe VM.
