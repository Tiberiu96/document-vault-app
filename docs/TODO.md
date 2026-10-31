# Urmatoarele etape

## Productie inainte de plati

- Pregatire host, domeniu/HTTPS si provider email real; Mailpit ramane local.
- Configurare securizata MySQL, Redis, Horizon, scheduler si monitorizare.
- Activare Turnstile si backup R2/Restic numai dupa configurare si verificare restaurare.
- Validare upload/OCR/AI, limite infrastructura, notificari si securitate in staging/productie.

## Plati si abonamente comerciale

Stripe Checkout/Billing este implementat, oprit implicit. Inainte de activarea productiei:

- Stabilire preturi si limite comerciale Free/Pro; preturi in bani RON intregi.
- Configurare credentiale sandbox/live si endpoint webhook separat, fara secrete in Git.
- Verificare completa sandbox pentru checkout, reinnoire, esec si evenimente duplicate.
- Workflow self-service pentru modificarea/anularea abonamentului, cu proratare explicita.
- Reconciliere periodica cu Stripe si monitorizare operationala a inboxului webhook.
- Separarea granturilor administrative de abonamentele confirmate de procesator.
- Evidenta costurilor/tokens AI si politica de retry pentru erori care pot genera costuri.

Nu activa productia pana la finalizarea acestor verificari. Atribuirea manuala Pro nu
este dovada unei plati si ramane separata de starea Stripe.
