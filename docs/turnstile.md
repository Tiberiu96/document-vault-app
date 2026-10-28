# Cloudflare Turnstile

Integrare pregatita pe login public, inregistrare PF/PJ si cererea emailului de
resetare. TURNSTILE_ENABLED=false implicit, inclusiv in .env.example. Cu flagul
oprit nu se incarca scriptul Cloudflare si nu se fac apeluri Siteverify. Adminul
ramane protejat prin autentificarea separata, MFA si limitele existente; uploadurile
si actiunile autentificate nu primesc provocari suplimentare.

## Activare pe productie

1. In contul Cloudflare, deschide Turnstile si creeaza un widget Managed dedicat
   productiei. Permite numai domeniile proprii (de exemplu domeniul canonic si www
   daca sunt folosite ambele). Nu este necesar ca site-ul sa fie proxiat de Cloudflare.
2. Salveaza direct in .env de productie:

```dotenv
TURNSTILE_ENABLED=false
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
TURNSTILE_HOSTNAMES=
```

Site key este publica; secret key ramane numai pe server. Nu trimite cheia secreta
in chat, Git sau capturi. TURNSTILE_HOSTNAMES poate contine hostname-uri exacte,
separate prin virgula, fara scheme, porturi, cai sau wildcard. Cand ramane gol,
serverul foloseste hostname-ul din APP_URL, niciodata antetul Host al solicitarii.
APP_URL trebuie sa fie configurat corect pentru productie.

3. Dupa completarea cheilor/domeniilor, seteaza TURNSTILE_ENABLED=true si ruleaza
   php artisan config:cache. Reincarca procesele persistente conform deploy-ului.
4. Verifica login, creare cont si cerere de resetare, inclusiv expirarea widgetului
   si o cerere directa fara token. Confirma Siteverify in analytics Cloudflare.
   Un widget bifat in browser nu confirma singur integrarea serverului.
5. Daca infrastructura aplica CSP, permite https://challenges.cloudflare.com la
   script-src, frame-src si connect-src pe aceste pagini. Pastreaza JS-ul oficial
   incarcat direct de la Cloudflare, fara proxy sau copie locala a lui.

Nu a fost testata o conexiune reala cu cheile tale. Testele automate mockuiesc
Siteverify; cheile publice dummy sunt refuzate in productie. Nu activa folosind
chei de test. Foloseste widgeturi separate pentru staging/productie.

## Comportament si securitate

Widget Blade reutilizabil, mod Managed ales in dashboard, tema light, limba ro/en/
hu/bg si dimensiune flexibila (compacta pe ecrane inguste). Codul local gestioneaza
incarcarea, erorile, expirarea si blocarea trimiterilor repetate. Daca JavaScript
este oprit/blocat sau verificarea nu s-a terminat, serverul refuza trimiterea.

Middleware-ul ruleaza inainte de autentificare, creare cont sau email reset.
Serverul cere token nenul de maximum 2048 bytes si apeleaza numai endpointul fix
Siteverify prin HTTPS, fara redirecturi, fara retry automat (token de unica
folosinta), connect timeout 2s, total timeout 5s. Cere success boolean true,
hostname din lista permisa si action exact: login, register sau password_reset.
Expirarea si refolosirea sunt validate de Cloudflare. Nu se memoreaza local
validari reusite pentru a reutiliza tokenul.

Se trimit catre Siteverify numai secretul si tokenul; nu se trimit parola, emailul,
continutul formularelor sau documente. Widgetul implica insa o conexiune a browserului
la Cloudflare pentru verificarea de securitate; reflecta integrarea in informarea
de confidentialitate a productiei. Nu o prezenta drept analytics publicitar.

Tokenurile sunt excluse din old input, nu apar in URL/loguri sau diagnostice.
Raspunsurile providerului nu sunt expuse utilizatorului. Cand integrarea este
activata dar configuratia lipseste, providerul esueaza sau raspunsul este invalid,
formularele raman blocate, cu mesaj localizat. Flagul false este o dezactivare
explicita de catre operator; nu exista fallback automat care ocoleste protectia.
Rate limiting si CSRF raman active. Turnstile nu inlocuieste MFA sau autorizarea.

Nu exista dependente Composer/Node noi. Vezi TurnstileTest pentru cazurile
pozitive/negative, izolarea contextului si evitarea trimiterilor externe in teste.

Surse:
- https://developers.cloudflare.com/turnstile/get-started/
- https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
- https://developers.cloudflare.com/turnstile/troubleshooting/testing/
