# Vault — reguli permanente

1. Vault este un SaaS pentru documente personale, familii si firme din Romania. Numele produsului ramane configurabil.
2. Stack: Laravel stabil compatibil, PHP 8.3+, MySQL, Redis, Sanctum, Horizon, Scout, Scribe/OpenAPI. Verifica versiunile si compatibilitatea inainte de instalare; pastreaza lockfiles.
3. Backend: conventii Laravel, actiuni/servicii pentru operatii de domeniu, Form Requests, API Resources, Policies. Evita abstractizari premature.
4. Web: Blade, Livewire, Tailwind CSS 4 standalone; Alpine numai cand este necesar. Fara Vite/Node pentru frontend daca nu exista un motiv documentat.
5. Mobil: React Native, Expo development builds, TypeScript, Expo Router, SecureStore, TanStack Query. Fara Flutter. Nu genera aplicatia mobila in etapa fundatiei.
6. Patru limbi cerute: ro (implicit), en, hu, bg. Toate textele romanesti scrise de aplicatie, inclusiv documentatia, sunt FARA diacritice. Pastreaza diacriticele maghiare, alfabetul chirilic bulgar si continutul utilizatorilor/documentelor/OCR.
7. Rutele web pentru utilizatori au prefix de limba inclusiv ro si sluguri traduse; numele interne sunt stabile. API: /api/v1, fara sluguri traduse. Evalueaza niels-numbers/laravel-localizer si verifica route:cache.
8. Admin: /admin, fara prefix de limba; middleware ro, autorizare globala, MFA obligatoriu in productie, rate limiting. Rolul admin al unui workspace NU confera super_admin.
9. O identitate poate apartine mai multor workspaces. PF creeaza personal; PJ creeaza personal si business. Accesul la orice resursa cere apartenenta si Policy; testeaza izolarea intre workspaces.
10. Documente: originale pastrate privat; acces doar autorizat; valideaza continut, MIME, marime si pagini. Niciun URL public sau cale interna de stocare expusa.
11. PlanLimitsService centralizeaza limitele. Free: maximum 10 pagini/document sau scanare, initial 5 MB; cote configurabile pe workspace/perioada. Rezervari atomice si joburi idempotente; respinge limitele inainte de OCR/AI.
12. OCR: extractie PDF nativa, Tesseract ron/eng/hun, OCRmyPDF; joburi asincrone. AI optional, provideri interschimbabili, rezultate JSON validate, fara valori inventate. OCR este input nefiabil; evita transmiterea externa implicita.
13. Rate limits configurabile, specifice operatiilor, server-side; protejeaza autentificarea, upload, cautare, invitatii, OCR/AI si admin. Raspunsuri 429 stabile.
14. Redis: high, default, ocr, ai, low. Workeri separati pentru OCR/AI si high; auto-balancing nu garanteaza prioritate stricta. Horizon cere pcntl/posix, deci Linux/WSL2 pentru workeri pe Windows.
15. Joburi: timeout, incercari limitate, backoff, jitter/Retry-After cand este cazul, idempotenta. Nu reincerca erori permanente. Monitorizare fara secrete/continut privat; retry administrativ autorizat.
16. Preturi abonamente in bani RON intregi; fara FLOAT. Display ro=RON, en=EUR, hu=HUF, bg=EUR; aproximativ pentru conversii, plata RON. Pastreaza suma/moneda originala a documentului. Frankfurter zilnic, istoric MySQL, cache Redis; fara rate inventate.
17. Notificari prin Laravel, email si FCM configurate separat. Deduplicare, tokenuri sincronizate, oprire dupa rezolvare; amanarea/reinnoirea sunt tranzitii explicite.
18. SEO de la prima pagina publica: metadata localizata, canonical, hreflang doar limbi active si pregatite, HTML semantic. Auth, admin, documente si pagini interne: noindex plus autentificare/autorizare.
19. Sitemap numai pagini publice indexabile si limbi active; invalideaza la schimbari. robots.txt diferit pentru productie si medii interne; nu este control de acces.
20. /llms.txt reflecta numai informatii publice reale; niciun secret, document privat sau detaliu intern. Nu promite efecte SEO garantate.
21. Analytics independent de provider, oprit implicit, necesita configurare si consimtamant. Fara continut, nume fisiere private sau date sensibile; evenimente deduplicate.
22. Inaintea integrarii unui serviciu: explica scop/provider/costuri/limite; intreaba daca exista un cont/configurare; furnizeaza nume variabile si instructiuni locale; asteapta confirmarea configurarii, testeaza sigur, apoi implementeaza. Nu bloca lucrari independente; foloseste mocks/adaptoare locale.
23. Nu cere si nu afisa parole, chei, tokenuri sau JSON privat. .env si credentialele sunt ignorate de Git; .env.example contine doar valori goale/fictive. Administrator numai prin vault:admin:create interactiv, parola ascunsa >=16 caractere si confirmare, hash Laravel; fara conturi/parole implicite.
24. Testeaza comportamentele de securitate si regulile de domeniu, route cache, localizarea, limitele, retry/idempotenta, SEO si consimtamantul. Servicii externe mockuite; fara apeluri AI platite. Nu declara teste rulate fara runtime.
25. Lucreaza incremental conform docs/architecture.md. Dupa fiecare etapa raporteaza fisierele, verificarile, limitarile si configurarea urmatoare. Nu implementa intreaga platforma odata; nu initializa Git sau publica fara cererea utilizatorului. Mentine documentatia sincronizata.

Scripturile Bash temporare pentru instalare/verificare se sterg dupa utilizare. Pastreaza instructiunile reproductibile in documentatie; nu lasa instalatoare temporare in proiect.

Referintele vizuale pentru interfata sunt in design-layouts/: citeste README.md si inspecteaza imaginile relevante inainte de implementarea UI. Sunt prevazute 01-desktop-dashboard.png, 02-mobile-dashboard.png, 03-landing-page.png, 04-desktop-documents.png, 05-mobile-document-details.png si 06-mobile-reminders.png. Pastreaza aceste artefacte la integrare/curatenie; nu pretinde ca le-ai inspectat daca lipsesc.

Git este autorizat de utilizator pentru acest proiect. Pastreaza composer.json/composer.lock in istoric; ignora .env, vendor, credentiale, DB locale, loguri si runtime storage. Verifica fisierele staged pentru secrete inainte de commit/push.

UI: textul functional trebuie sa aiba minimum 16px (inclusiv navigatie, formulare si butoane). Miniatura ilustrativa a produsului poate pastra text proportional cu scara sa. Antetul mobil foloseste logo, login/profil si meniu compact; accesul la cont este un icon circular discret, cu aria-label si title localizate.

Emailuri de cont (confirmare/resetare/alerta parola): notificari asincrone pe high, afterCommit, payload criptat; linkuri numai din APP_URL si limba pastrata in job. Mailpit exclusiv local, loopback, nu este provider de productie. Nu loga URI/query care contin tokenuri de autentificare.
