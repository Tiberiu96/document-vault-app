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
