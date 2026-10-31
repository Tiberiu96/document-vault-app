# Stripe Checkout si Billing

Integrarea foloseste Stripe Checkout pentru colectarea datelor de plata si Stripe
Billing pentru abonamente lunare. Vaulty nu primeste si nu stocheaza datele cardului.
Planul se activeaza numai dupa un webhook semnat, persistat si procesat; redirectul
browserului nu acorda acces.

## Costuri, limite si versiuni

SDK-ul oficial `stripe/stripe-php` este blocat prin Composer la seria 22.1, compatibila
cu PHP 8.3 si cu API-ul Stripe `2026-09-30.endive`. Schimbarea versiunii majore cere
citirea ghidului Stripe si testarea webhook-urilor in sandbox inainte de deploy.

Stripe publica pentru Romania tarife diferite dupa metoda de plata, tara cardului,
conversie si contract. Stripe Billing poate avea tarif separat. Valorile efective se
verifica in Dashboard; aplicatia nu calculeaza costul Stripe si nu il prezinta clientului.
Limitele API si disponibilitatea monedelor depind de cont. Retry-urile Stripe sunt
idempotente, limitate si nu se folosesc pentru erori permanente.

## Configurare

Integrarea este oprita implicit. Configureaza numai in `.env` local sau in secret
managerul mediului, fara a publica valorile:

```dotenv
STRIPE_ENABLED=false
STRIPE_ENVIRONMENT=sandbox
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
STRIPE_WEBHOOK_TOLERANCE=300
```

Sandboxul si productia folosesc chei si endpointuri webhook separate. `APP_URL` trebuie
sa fie URL-ul HTTPS public corect pentru URL-urile de succes/anulare. Endpointul este
`POST /stripe/webhook`. Evenimentele necesare sunt:

- `checkout.session.completed`;
- `customer.subscription.created`, `customer.subscription.updated`,
  `customer.subscription.deleted`;
- `invoice.paid`, `invoice.payment_failed`.

In `/admin/limite?plan=pro`, dupa MFA, se configureaza preturi lunare explicite pentru
Pro personal si Business in RON, EUR si HUF. Sumele sunt intregi in unitatea minima a
monedei; nu exista FLOAT, curs valutar sau pret inventat. Pretul Business are separat
pretul unui loc suplimentar. Activarea unui pret nu modifica abonamente existente.

## Flux si reguli comerciale

Numai proprietarul workspace-ului poate deschide sumarul si Checkout. Sumarul recalculeaza
pe server planul, moneda, locurile suplimentare si totalul. Aceleasi valori sunt trimise
ca pozitii recurente Stripe. Business include cinci membri; cantitatea pozitiei pentru
locuri este numarul total de locuri suplimentare. Stripe Tax nu este activat in aceasta
etapa, fapt afisat explicit in sumar.

Abonamentul pastreaza moneda aleasa. Schimbarea limbii nu schimba reinnoirea. Un workspace
nu poate crea un al doilea abonament cat timp exista unul activ, trial, incomplet sau
restant. Modificarea cantitatii, schimbarea monedei si anularea unui abonament existent
nu sunt expuse self-service in aceasta etapa; se reconciliaza in Stripe inaintea unui
workflow dedicat, pentru a nu ascunde proratarea sau autentificarea suplimentara.

`active` si `trialing` acorda Pro si capacitatea platita. `past_due`, `unpaid`, `canceled`
sau `incomplete` retrag drepturile platite, fara stergerea documentelor ori eliminarea
membrilor existenti; invitatiile noi sunt blocate de limita efectiva. `invoice.paid`
reactiveaza drepturile. Evenimentele mai vechi decat starea aplicata sunt ignorate.

## Webhook, retry si date

Semnatura se valideaza pe body-ul brut. Evenimentul este salvat in MySQL inainte de 2xx,
cu payload criptat prin `APP_KEY`, unic pe mediu si ID Stripe. Procesarea ruleaza pe coada
`high`, cu maximum cinci incercari si backoff. Nu se logheaza payloadul, query-uri cu
secrete sau date de plata. Dublurile returneaza 2xx fara a aplica tranzitia din nou.

## Verificare sandbox

1. Configureaza cheile sandbox si endpointul webhook, apoi activeaza `STRIPE_ENABLED`.
2. Configureaza si activeaza cel putin un pret comercial si sablonul Pro.
3. Verifica 5, 6 si 7 membri: cantitatea suplimentara trebuie sa fie 0, 1 si 2.
4. Verifica separat RON, EUR si HUF; totalul din sumar trebuie sa fie identic cu Checkout.
5. Testeaza plata reusita, anularea browserului, `invoice.payment_failed`, plata ulterioara,
   stergerea abonamentului si trimiterea repetata/dezordonata a aceluiasi eveniment.
6. Confirma ca un membru sau alt workspace primeste 403 si ca niciun secret nu apare in
   raspunsuri, loguri, commit sau capturi.

Testele automate folosesc un gateway fake si semnaturi webhook locale; nu fac apeluri
Stripe si nu produc plati.
