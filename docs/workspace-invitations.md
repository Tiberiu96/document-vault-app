# Membri si invitatii

Invitatiile sunt disponibile numai pentru spatii business. Spatiile personale raman private.
Pagina `/ro/membri` afiseaza echipa spatiului activ; `/ro/invitatii` listeaza invitatiile
primite de identitatea autentificata. Exista rute si traduceri en/hu/bg, toate noindex.

## Flux si acces

1. Proprietarul sau un admin invita o adresa de email, alegand membru sau contabil.
   Numai proprietarul poate acorda rolul admin. Rolurile workspace nu acorda acces global.
2. Invitatul primeste email; daca are deja cont, primeste si notificare in spatiul personal.
   La inregistrarea unui cont nou se asociaza invitatiile in asteptare la inboxul personal.
3. Linkul din email valideaza tokenul, apoi cere autentificare si email verificat.
   Un simplu GET nu accepta invitatia. Pentru un cont existent, acceptarea este disponibila
   si din lista/inbox, fara tokenul emailului, pe baza identitatii cu email verificat exact.
4. Acceptarea POST cu CSRF adauga membership-ul si activeaza spatiul business.
   Expeditorul primeste email si notificare in inboxul firmei. Repetarea acceptarii nu
   dubleaza membership-ul sau notificarile. Citirea notificarii nu accepta invitatia.

Invitatiile expira dupa sapte zile. Retrimiterea este permisa dupa 60 secunde, roteste
tokenul si actualizeaza expeditorul. Maximum 10 trimiteri/ora/spatiu, plus limitare HTTP
per utilizator. Anularea invalideaza invitatia. Proprietarul poate modifica roluri si
elimina membri cu confirmare; nu isi poate elimina propriul acces sau proprietarul.
Un admin nu poate modifica alt admin. Contabilul are permisiunile politicilor existente.

Nu exista acces la documentele firmei inainte de acceptare. Fiecare acces ulterior
verifica membership-ul si politica resursei. Eliminarea revoca accesul si invitatiile
inca neacceptate emise de membrul eliminat; o invitatie acceptata nu poate restaura
accesul pierdut. La acceptare se reverifica si drepturile actuale ale expeditorului.

## Date si concurenta

`workspace_invitations`: UUID, workspace_id, invited_by, email normalizat, role, locale,
token_hash SHA-256 unic, expires_at, last_sent_at, accepted_at/accepted_by, revoked_at,
timestamps. Relatii catre workspace si utilizatori; stergerea workspace-ului elimina
invitatiile. Membership-ul are deja unicitate `(workspace_id,user_id)`.

Tranzactiile blocheaza workspace-ul si invitatia pentru creare, retrimitere, acceptare
si schimbarea membrilor. Tokenul brut nu este stocat in tabela/inbox; exista doar in
email si payloadul criptat al jobului. Nu loga linkurile de invitatie.

Emailurile `InvitationMail` folosesc high, afterCommit, criptarea payloadului, trei
incercari, timeout si backoff. URL-urile provin numai din APP_URL. Joburile verifica
din nou starea si tokenul inainte de livrare, pentru a elimina emailurile devenite vechi.
Notificarile in-app folosesc Laravel Notifications, deduplicare determinista si pollingul
existent la 30 secunde. Notificarea primita se marcheaza citita la acceptare.

## Configurare si verificare

Migrarea `2026_10_28_000016_create_workspace_invitations` este necesara. Nu sunt necesare
pachete sau credentiale noi. Redis/Horizon trebuie pornit cu worker high, iar productia
necesita APP_URL HTTPS si un provider email configurat; Mailpit ramane exclusiv local.

`WorkspaceInvitationTest` acopera mail criptat, inbox, identitate verificata, roluri,
izolare, tokenuri vechi, expirare/anulare, retrimitere, revocare si acceptare idempotenta.
Verificarea vizuala locala foloseste conturi fictive si un workspace fara documente.

Limite ale etapei: fara transfer de proprietate, invitatii in masa sau notificari push
FCM. Nu exista notificari suplimentare pentru fiecare editare de rol; primirea invitatiei
si acceptarea au email si inbox. Invitatia poate fi ignorata sau anulata de manager.
