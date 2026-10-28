# Remindere si notificari — etapa web/email

## Reguli

Utilizatorul a ales notificari cu 10, 3 si 1 zi inaintea expirarii, la 09:00,
Europe/Bucharest. Nu exista trimitere implicita in ziua expirarii. Orele sunt
calculate in fusul local, apoi pastrate UTC, inclusiv la schimbarea orei.
Numai datele strict viitoare si documentele stored genereaza un reminder.
Sloturile deja trecute la confirmare sunt omise, fara recuperare in masa.

Confirmarea/editarea datei de expirare creeaza sau actualizeaza reminderul in
aceeasi tranzactie. Destinatarul initial este utilizatorul care il activeaza;
nu se notifica automat intreaga firma/familie. Numele destinatarului este vizibil
in document. Calitatea de membru, emailul verificat si Policy view se verifica din
nou inaintea livrarii. Pentru documentele existente nu se face backfill automat:
activarea explicita se face in sectiunea Remindere a documentului.

## Stari si actiuni

- active/completed/disabled sunt independente de statusul OCR/AI al documentului.
- Marcheaza rezolvat si Nu ma mai notifica anuleaza sloturile pending/processing,
  inclusiv joburile puse in coada, sub acelasi lock de workspace/document/reminder.
- Editarea unui titlu sau sumar nu reactiveaza un reminder rezolvat.
- Amana cere o data viitoare inaintea expirarii: anuleaza ciclul vechi, programeaza
  o livrare la 09:00 in ziua aleasa si pastreaza sloturile regulate ulterioare.
  Slotul intern days_before=0 inseamna amanare explicita, nu notificare de expirare.
- Am reinnoit schimba expirarea, incrementeaza metadata_version si ciclul,
  anuleaza programul vechi si creeaza noul program. Istoricul trimis se pastreaza.
- Formularul include cycle pentru a refuza actiuni vechi asupra unui ciclu nou.
- Stergerea documentului blocheaza accesul si livrarea. Purge elimina reminderul,
  livrarile si notificarile in-app asociate; joburile vechi nu recreeaza date.

## Livrare si deduplicare

ReminderService::dispatchDue, apelat in fiecare minut, pune DeliverReminder pe
high. Jobul este encrypted, afterCommit, unic temporar, cu 3 incercari,
timeout 45s si backoff 15/60/180s. Payloadul contine numai UUID-ul livrarii.
Unicitatea persistenta reminder/cycle/days_before, starile atomice si ID-ul stabil
al notificarii database protejeaza impotriva relivrarilor. Nu se pastreaza continutul
documentului in payload, log sau notificarea database; emailul contine titlul,
data si un link autorizat construit exclusiv din APP_URL.

Jobul reverifica starea si apartenenta inaintea trimiterii. Finalizarea obtine
aceleasi lockuri ca livrarea. Un email deja acceptat de SMTP nu poate fi retras.
Intentia processing este comisa inainte de SMTP. Dupa un timeout/crash sau un
raspuns SMTP incert, livrarea devine uncertain, cu cod generic; nu se retransmite
orb, deoarece SMTP nu ofera exact-once. Retry-urile automate sunt sigure inaintea
efectului extern; reconcilierile/manual retry pentru SMTP incert cer o etapa
administrativa ulterioara. In-app poate fi livrat chiar daca emailul este incert.
Schedulerul marcheaza processing abandonat dupa doua minute ca uncertain.
Sloturile dintr-o zi anterioara sunt skipped, evitand avalanse dupa o intrerupere.

## Configurare si operare

- REMINDERS_ENABLED=true permite dispatch-ul.
- REMINDERS_EMAIL_ENABLED=false implicit; activarea necesita provider verificat.
- Local, SMTP Mailpit pe loopback era deja configurat/verificat; emailurile sunt
  activate local. Mailpit nu este provider de productie si nu este expus in LAN.
- Modificarile .env cer config:clear si repornirea Horizon/Scheduler.
- Serviciul utilizatorului vaulty-scheduler foloseste artisan schedule:work,
  ruleaza local si este enabled. Instalare reproductibila:

```text
install -m 0644 deploy/systemd/vaulty-scheduler.service ~/.config/systemd/user/vaulty-scheduler.service
systemctl --user daemon-reload
systemctl --user enable --now vaulty-scheduler
systemctl --user status vaulty-scheduler vaulty-horizon
php artisan schedule:list
php artisan vault:reminders:dispatch
```

In productie foloseste Scheduler cron/supervizat, un singur mecanism activ,
monitorizare, secret management si provider de email configurat explicit.
Nu activa SMTP de productie prin presupunere. FCM/push, preferintele individuale
pe canal si administrarea retry-urilor incerte raman pentru etapa urmatoare.

## Interfata si verificari

/ro/remindere (en/reminders, hu/emlekeztetok, bg/napomnyaniya) are liste
Active/Rezolvate/Oprite/Toate, scadente, urmatoarea trimitere si actiuni. Sectiunea
din document listeaza toate trimiterile viitoare si istoricul. Acasa are urmatoarele
remindere; meniul contului si sidebar-ul ofera acces. Bottom navigation ramane
Acasa/Scaneaza/Documente. Toate paginile sunt private, noindex, scoped pe workspace.
Procesare document este la finalul paginii, dupa preview, metadate si remindere.

ReminderTest acopera DST, intervalele 10/3/1, date trecute/lipsa, drafturi,
idempotenta, finalizare dupa enqueue/claim, reinnoire/amanare/ciclu invechit,
izolare, roluri, revocare, email neverificat, esec SMTP incert, Scheduler oprit,
ferestre omise, email dezactivat cu livrare in-app, limba dezactivata si UTC/local
la miezul noptii. Testele folosesc transporturi in memorie, fara SMTP extern.
Un test separat real local a verificat Redis/high/Horizon, o singura livrare
Mailpit si in-app, apoi finalizarea cu anularea celor doua sloturi viitoare.
