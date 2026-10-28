# Cozi — plan

Conexiunea Redis este verificata si queue/cache Redis activate local; in exemplul de mediu queue=sync numai pentru fundatia locala, nu pentru OCR/AI. high pentru email/notificari urgente, default pentru lucru normal, ocr si ai pentru procesare, low pentru mentenanta. Supervisori separati, capacitate rezervata high; nu presupune prioritate stricta prin auto-balancing.

Bugete initiale propuse, de validat pe fisiere reale: high 60s, default 200s, ai 420s, ocr 300s, retry_after Redis > cel mai mare timeout, de exemplu 480s. Numai joburile cu timeout sub retry_after pot intra pe acea conexiune. Supervisor stopwaitsecs trebuie sa depaseasca durata maxima a jobului. Concurenta configurabila separat, OCR initial 1 proces pentru memorie.

Incercari limitate, backoff exponential/jitter; Retry-After respectat. Validare permanenta si credentiale invalide nu sunt retry orb. Lockuri/unicitate si stari atomice; taxare/cote/notificari deduplicate la retry. Inregistreaza nume job/coada/attempt/id document-workspace/durata/timp/eroare sanitizata, niciodata payload privat. Revizuieste failed_jobs: payloadurile serializate pot contine date sensibile; joburile transporta ID-uri, nu continut.

Horizon dashboard numai pentru admin autorizat, romana/noindex si MFA in productie. Scheduler cu protectie de suprapunere/single server unde este cazul; monitorizeaza workeri, latency, depth si esecuri. Retry manual autorizat si auditat.

Windows: Horizon necesita Linux/WSL2 din cauza pcntl/posix; fara Docker si fara ignorarea platform requirements. Configuratia finala va fi publicata din pachetul instalat si verificata prin teste de retry/idempotenta si test Redis real.

Configuratia supervisorilor este livrata in config/horizon.php; retry_after Redis=480s, timeout maxim AI=420s (job 400s), after_commit=true. In fiecare grup exista un worker separat, inclusiv high. Testele verifica bugetele si cozile. Workerii locali sunt supravegheati prin systemd user. Horizon brut ramane inchis; dashboardul propriu /admin afiseaza statistici sanitizate dupa autentificare globala si MFA.

Notificarile de cont AccountMail sunt in high, afterCommit si ShouldBeEncrypted;
3 incercari, timeout 30s, backoff 10/60/180s. Workerii locali Horizon ruleaza prin
serviciul utilizatorului vaulty-horizon, separat de Mailpit. Configurarea si testele
sunt descrise in account-email.md si integrations.md.

DeliverReminder ruleaza pe high cu payload encrypted, afterCommit si timeout45s.
SMTP timeout15s. Schedulerul local ruleaza ca serviciu user vaulty-scheduler,
dispatch in fiecare minut cu withoutOverlapping. Detalii despre claim, deduplicare
si evitarea retrimiterilor SMTP incerte in reminders.md.
