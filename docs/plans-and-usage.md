# Planuri si consum pe workspace

PlanLimitsService rezolva Free sau Pro pentru workspace-ul operatiunii, inclusiv pentru
membri invitati. Nu exista cote personale suplimentare care pot ocoli cota firmei.
Planul nu este mass-assignable din formularele clientului.

## Configurare si atribuire

- `/admin/limite`: limitele Free si sablonul Pro, cu versiune optimista pentru editari.
- Pro porneste dezactivat, cu aceleasi valori conservatoare ca Free: 10 pagini, 5 MiB,
  100 MiB stocare, 50 pagini OCR/luna, 10 analize AI/luna. Sunt valori de sablon,
  nu o oferta comerciala. Configureaza limitele si activeaza atribuirea numai cand sunt decise.
- `/admin/spatii`: buton Plan si consum pentru fiecare workspace. Atribuirea manuala
  cere admin global, MFA, confirmare si versiunea curenta; actiunea este auditata.
- Pro poate avea ultima zi de valabilitate optionala, in UTC. La 00:00 UTC in ziua
  urmatoare se aplica Free, fara cron suplimentar. Dezactivarea sablonului Pro are
  acelasi efect. Nu se sterg fisiere, nu se reseteaza consumul si nu se anuleaza
  automat rezervarile deja admise. Reducerea sub consumul existent opreste noi rezervari.
- Fara checkout, facturare, preturi inventate sau incasari. Atribuirea manuala nu este
  dovada unei plati. Integrarea comerciala ramane in TODO dupa pregatirea productiei.

Migrarea 000017 adauga enabled in platform_plan_limits si plan_code, plan_expires_at,
plan_version pe workspaces. Workspace-urile existente raman Free; nu se suprascriu
limitele Free existente. Nu sunt necesare credentiale externe noi.

## Aplicare si contabilizare

Uploadul, scanarea si confirmarea aplica limitele workspace-ului. Confirmarea verifica
limitele din nou sub lock, inclusiv cand planul s-a schimbat dupa analiza draftului.
Dimensiunea infrastructurii ramane maximum 5 MiB per fisier si maximum 50 pagini
configurabile; modificarea acestor plafoane cere analiza timeouturilor Nginx/PHP/OCR/AI.

Stocarea include originale, surse temporare inca existente si preview-uri. Rezervarea
se face sub lock pe workspace; curatarea elibereaza cota numai dupa stergerea efectiva.
OCR foloseste pagini, AI foloseste analize logice. Intr-o tranzactie cu workspace blocat,
used + reserved + solicitarea noua trebuie sa incapa in limita curenta. Joburile folosesc
aceleasi contoare indiferent cine a initiat procesarea. Retry-ul aceluiasi run nu rezerva
inca o data; finalizarea este idempotenta. Esecurile definitive elibereaza rezervarea,
conform fluxului existent. AI pornit si anulat se contorizeaza ca incercare consumata.

Cotele lunare sunt lunile calendaristice UTC, pe cheia workspace_id + period.
Reinnoirea selecteaza noua perioada, fara a sterge istoricul; joburile care traverseaza
schimbarea lunii se deconteaza in perioada in care au fost rezervate. Stocarea nu expira.
Valorile -1 pentru OCR/AI sunt admise numai local; productia foloseste cote finite.

## Interfata si monitorizare

`/ro/plan-si-consum` (si variantele en/hu/bg) afiseaza planul efectiv, stocarea, consumul,
rezervarile si disponibilul OCR/AI, urmatoarea reinnoire si expirarea atribuirii Pro.
Pagina este privata/noindex, disponibila membrilor workspace-ului activ. Headerul de
cont si dashboardul ofera acces; navigatia mobila de jos ramane neschimbata.

Adminul vede agregate ale procesarilor lunii dupa tip/provider/stare: unitati solicitate
si incercari. Nu se expun continut OCR/AI sau nume de fisiere. Acestea nu sunt costuri
reale de la provider: numarul de analize logice, retry-uri si sectiuni poate diferi de
numarul cererilor API. Facturarea/tokenurile si reconcilierea banilor sunt in etapa de plati.

## Verificare

WorkspacePlanTest acopera fallback Free, expirare, upload/scanare pe Pro, ignorarea
campurilor client pentru upgrade, cote comune membrilor, rezervari si finalizare
idempotenta, schimbarea lunii, downgrade fara pierdere, izolarea paginii si atribuirea
administrativa cu MFA/confirmare/versiune/audit. Testele standard nu apeleaza provideri AI.
Lockurile folosesc mecanismul MySQL din fluxurile existente. Verificare locala suplimentara:
doua procese au cerut simultan 70 si 40 bytes din 100 bytes disponibili; o rezervare a
fost acceptata si una respinsa, fara depasire. Workspace-ul fictiv a fost eliminat.
Acest control nu inlocuieste testarea de incarcare pe infrastructura de productie.

## Locuri Business - 30 octombrie 2026

Fiecare workspace business include maximum cinci utilizatori, inclusiv proprietarul.
Toate rolurile consuma un loc. Invitatiile active, neacceptate si nerevocate,
rezerva un loc pana la acceptare, expirare sau revocare. PlanLimitsService
centralizeaza capacitatea. Crearea, retrimiterea si acceptarea invitatiilor se
verifica sub acelasi lock pe workspace, fara suprarezervare concurenta.
Acceptarea transforma rezervarea in membership; repetarea acceptarii nu consuma
un loc nou. Rolurile si verificarile de email/expeditor raman neschimbate.

Compatibilitate cu beta existenta: limita de cinci se aplica tuturor spatiilor
business, inclusiv celor care folosesc momentan Free. Nu se creeaza un abonament
comercial nou. Sablonul platit intern ramane Pro; pentru firme, administrarea il
prezinta ca Pro / Business. Numai Pro activ poate adauga capacitate suplimentara.
Spatiile personale raman limitate la proprietar, fara invitatii.

In /admin/limite?plan=pro se configureaza pretul lunar al unui loc suplimentar,
in bani RON intregi, fara FLOAT (100 bani = 1 RON). Valoare initiala NULL,
fara pret inventat; gol blocheaza acordarea locurilor suplimentare. Zero este
permis numai ca gratuitate configurata explicit. Campul foloseste versiunea
optimista si auditul planului. Nu se modifica facturi istorice si nu se incaseaza bani.

In /admin/spatii/{workspace}/plan, adminul global cu MFA/parola verificata,
confirmare explicita si versiune curenta poate acorda 0-1000 locuri suplimentare.
Clientii si administratorii workspace-ului nu le pot modifica. Pretul trebuie
configurat inaintea acordarii; planul Pro trebuie activat explicit. Schimbarea
locurilor este auditata si nu reseteaza consumul. Nu se activeaza automat Pro.

La expirarea/dezactivarea Pro, locurile suplimentare devin inactive. Numarul
atribuit este pastrat, fara eliminarea membrilor, invitatiilor sau documentelor.
Daca ocuparea depaseste noul plafon, se blocheaza invitatiile/acceptarile noi pana
la eliberarea sau acordarea capacitatii necesare. Membrii existenti isi pastreaza
permisiunile. Invitatia expirata necesita o rezervare noua la retrimitere.
Cotele OCR/AI/stocare raman comune workspace-ului, indiferent de numarul locurilor.

Migrarea 000019 adauga platform_plan_limits.extra_member_price_bani si
workspaces.additional_member_seats (implicit 0). Pagina Membri si Plan si consum
arata membri, invitatii rezervate, locuri incluse si suplimentare active.
BusinessMemberSeatsTest acopera plafonul, rezervarile, acceptarea idempotenta,
expirarea/revocarea, izolarea, fallback-ul si acordarea administrativa.
Migrarea locala, compilarea Blade/CSS si route:cache sunt verificate separat;
testele automate nu au fost rulate local, conform preferintei utilizatorului.

## Decizii pentru integrarea ulterioara Stripe

Stripe este numai planificat; nu este instalat/configurat si nu exista apeluri,
checkout, incasari, facturi comerciale sau credentiale Stripe folosite aici.
Utilizatorul are deja un cont. Va fi confirmat sandbox-ul separat al proiectului
si configuratia privata inaintea integrarii. Abonamente recurente, cu planul de
baza si locuri suplimentare ca pozitii ale aceluiasi abonament.

Cerinta aprobata pentru etapa de plati inlocuieste plata numai in RON: moneda
implicita este ro=RON, en=EUR, hu=HUF, bg=EUR. Clientul poate alege explicit RON,
EUR sau HUF inainte de checkout. Pretul final afisat si suma/moneda trimise catre
Stripe trebuie sa coincida, inclusiv pentru locuri suplimentare. Abonamentul
pastreaza moneda confirmata; schimbarea limbii nu schimba renovarile existente.
Preturile comerciale pe monede vor fi configurate explicit si versionate, fara
rate inventate sau conversii aproximative facturate. Actualul camp RON este
configurare manuala pregatitoare; nu reprezinta implementare multicurrency.

Webhook: validarea semnaturii pe body brut, persistenta durabila in MySQL inainte
de raspuns 2xx, deduplicare pe eveniment/cont/mediu si payload minim criptat.
Procesarea efectiva pe joburi, cu reluare din inbox/outbox durabil daca Redis sau
workerul cade. Tranzitii idempotente, protectie la evenimente neordonate, retry
limitat/backoff, monitorizare sanitizata si reconciliere periodica cu Stripe.
Niciun acces platit acordat exclusiv pe baza redirectului de succes din browser.
Sandbox si credentiale live separate; local se vor testa plata initiala,
reinnoirea cu ceas simulat, esecul, autentificarea suplimentara, anularea,
modificarile de locuri si evenimentele duplicate/dezordonate. Fara plati reale.

Referinte oficiale pentru etapa viitoare:
- https://docs.stripe.com/sandboxes
- https://docs.stripe.com/currencies
- https://docs.stripe.com/products-prices/manage-prices
- https://docs.stripe.com/billing/subscriptions/quantities
- https://docs.stripe.com/billing/subscriptions/overview
- https://docs.stripe.com/webhooks
- https://docs.stripe.com/billing/testing


## Solicitarea upgrade-ului din Plan si consum

Zona de upgrade se adapteaza planului efectiv si tipului spatiului: Free personal
propune Pro, Free business propune Business (cod intern Pro), Business activ
propune locuri suplimentare; Pro personal indica planul maxim disponibil.
Nu exista o trecere implicita de la workspace personal la business.

Numai proprietarul poate trimite cererea. Formularul POST pe ruta localizata
Plan si consum foloseste auth/auth.session/verified/membership/Policy/CSRF si
limita configurabila plan_requests_per_minute (implicit 5). Workspace-ul vine
din sesiunea verificata; campurile client pentru ID/plan/pret nu sunt folosite.
Planul, locurile si consumul nu se schimba prin trimiterea cererii.

Migrarea 000020 creeaza workspace_upgrade_requests. Sub lock pe workspace se
pastreaza maximum o cerere pending, inclusiv la cereri simultane. Locurile
solicitate sunt numarul total suplimentar peste cele cinci incluse, nu un delta.
Pentru Business deja activ trebuie sa fie mai multe decat cele atribuite.
Preturile si disponibilitatea se confirma manual, inclusiv pentru sablonul Pro
inca dezactivat; cererea nu este rezervare de capacitate sau dovada de plata.

Admin: /admin/spatii afiseaza marcajul Upgrade solicitat si un filtru pentru
cereri pending, pe lista paginata existenta. Pagina planului arata cererea.
Atribuirea manuala Pro, cu MFA/parola verificata/confirmare/versiune/audit, o
marcheaza approved numai daca si locurile solicitate sunt acordate. Refuzul
cere confirmare si este auditat. Istoricul approved/rejected ramane in baza.
Nu se trimit emailuri si nu exista integrare Stripe in aceasta etapa.

Textele sunt localizate in ro/en/hu/bg, cu romana fara diacritice. Interfata
respecta minimum 16px si se adapteaza pe mobil. WorkspaceUpgradeTest acopera
cererea fara activare, deduplicarea, proprietarul, izolarea si recomandarile.
Migrarea locala, compilarea Blade/CSS si route:cache sunt efectuate; testele
raman pentru CI, fara rulare locala. Verificarea vizuala in browser este inca
indisponibila din setarile Work.
