# Urmatoarele etape

## Productie inainte de plati

- Pregatire host, domeniu/HTTPS si provider email real; Mailpit ramane local.
- Configurare securizata MySQL, Redis, Horizon, scheduler si monitorizare.
- Activare Turnstile si backup R2/Restic numai dupa configurare si verificare restaurare.
- Validare upload/OCR/AI, limite infrastructura, notificari si securitate in staging/productie.

## Plati si abonamente comerciale — amanat la cererea utilizatorului

Dupa mutarea pe productie si alegerea procesatorului de plati:

- Stabilire preturi si limite comerciale Free/Pro; preturi in bani RON intregi.
- Configurare cont/credentiale local, fara secrete in conversatii sau Git.
- Checkout, webhookuri semnate, idempotenta, reconciliere si evidenta abonamentului real.
- Reinnoire, anulare, expirare, incasari esuate, facturi si drepturi verificate server-side.
- Separarea granturilor administrative de abonamentele confirmate de procesator.
- Evidenta costurilor/tokens AI si politica de retry pentru erori care pot genera costuri.

Nu activa plati sau abonamente recurente doar pe baza unei atribuiri manuale Pro.
