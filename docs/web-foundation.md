# Checkpoint web, localizare si autentificare

## Livrare

Pagina publica si miniatura statica a produsului pornesc de la cele sase referinte din design-layouts/, inspectate impreuna cu README-ul lor. Layout editorial, carduri, sidebar ilustrativ, navigare mobila, butoane, iconuri SVG, badge-uri, formulare, filtre, tabele, empty/error states si FAQ native details sunt componente Blade reutilizabile. Nu sunt folosite Node, Vite, CDN-uri de fonturi, tracking sau API-uri externe.

Pagina publica descrie produsul si stadiul dezvoltarii, fara statistici, preturi sau disponibilitate in magazine inventate. Pagina demo separata a fost eliminata; rutele ei raspund 404. Actiunile publice duc la inregistrare sau la panoul utilizatorului autentificat. Miniatura statica ramane etichetata ca exemplu de interfata.

Loginul este accesibil din antet si prin /ro/autentificare, /en/login, /hu/bejelentkezes. Panoul real este /ro/panou, /en/dashboard, /hu/iranyitopult, cu auth si noindex. Logout numai POST cu CSRF. Inregistrarea PF/PJ a fost adaugata in checkpointul registration.md. Resetarea si verificarea emailului sunt acum disponibile, vezi account-email.md. Transportul local Mailpit este verificat; livrarea pe internet necesita configurarea unui provider.

Autentificarea normalizeaza emailul, foloseste hashurile Laravel si regenereaza sesiunea la succes. Limite: 5 esecuri/minut per cont, agregate intre IP-uri, si 20 esecuri/minut per IP; cheile de rate limit contin hashuri, nu emailuri. Lockout HTTP 429 cu Retry-After; mesaj generic pentru credentiale gresite. Parola nu este pastrata in old input. Logout invalideaza sesiunea si regenereaza tokenul CSRF. Panoul arata numai membershipurile autorizate prin WorkspacePolicy; conturile globale nu primesc bypass pentru spatii personale straine.

## Limbi si rutare

Migratia languages si LanguageSeeder livreaza ro/en/hu/bg, valute RON/EUR/HUF/EUR. Seederul este idempotent si nu reactiveaza limbi dezactivate. DatabaseSeeder nu creeaza conturi.

Pachetul niels-numbers/laravel-localizer 1.4.1 inregistreaza rute traduse statice, cu prefix inclusiv ro. Numele interne home/features/pricing/faq/login/dashboard/logout raman stabile. SetLocale/RedirectLocale ruleaza in web inainte de bindings; admin si API nu sunt traduse. Root redirectioneaza catre defaultul activ din baza, independent de browser.

LanguageCatalog citeste disponibilitatea direct din baza, fara cache persistent: modificarile sunt reflectate imediat in middleware, selector, sitemap si hreflang. Manifestul config/vault.php defineste limbile cu rute/traduceri livrate. Un rand nou nu publica o limba. LanguageManager autorizeaza numai super_admin, blocheaza dezactivarea defaultului si schimba defaultul tranzactional sub lock. Indexul unic pe default_slot previne doua defaulturi simultane. Nu este inca expusa o interfata administrativa pentru creare/editare/stergere; aceasta necesita auth admin si MFA.

Dictionarele au chei imbricate reale. O problema initiala cu chei precum scan.title stocate literal a fost corectata; testele verifica fiecare cheie prin translator si resping cheile site.* nerezolvate din HTML.

## SEO si privacy

Metadata localizata pentru paginile publice: titlu/descriere, canonical fara query din APP_URL, hreflang si x-default numai pentru limbi active, OG/Twitter cu imagine locala si JSON-LD WebPage fara preturi inventate. Sitemap contine numai cele patru pagini publice per limba activa; auth/admin/API sunt excluse. robots.txt este generat de Laravel: staging/local disallow, productie allow plus sitemap. llms.txt reflecta doar functionalitatile/stadiul real.

Middleware-ul de indexare permite indexarea numai pentru rute marcate indexable in productie cu status 200. Auth, admin, health si erorile au noindex. Configuratia Nginx locala adauga intentionat noindex peste tot; nu este configuratie de productie.

## Asseturi si build

Tailwind standalone Linux x64 4.3.3, SHA-256 dc61b3ac6b8c9ca874c0cc4c57b2409791a64c5540404ca5f5367360babc313a. Binar ignorat in Git; instalare reproductibila fara scripturi temporare:

```bash
mkdir -p .tools public/css
curl -fL https://github.com/tailwindlabs/tailwindcss/releases/download/v4.3.3/tailwindcss-linux-x64 -o .tools/tailwindcss
printf 'dc61b3ac6b8c9ca874c0cc4c57b2409791a64c5540404ca5f5367360babc313a  .tools/tailwindcss\n' | sha256sum -c -
chmod u+x .tools/tailwindcss
composer run css:build
```

Windows foloseste binarul oficial pentru Windows din acelasi release si verifica checksumul sau; nu folosi checksumul Linux. CSS sursa este versionat, outputul public/css/app.css este generat si ignorat. La schimbarea componentelor CSS trebuie recompilat. Query-ul de versiune din layout foloseste data buildului.

DM Serif Display si DM Sans sunt gazduite local, cu licente SIL OFL incluse. Surse: google/fonts/ofl/dmserifdisplay si google/fonts/ofl/dmsans pe GitHub. SHA-256 fonturi: DM Serif Display 8cc3643535edf039aa5d95440a8542735e9197e4f4b8d9303e980fefbf5ab616; DM Sans 8cd08d97e89c24d0aa92edd2f0f4c8ee6195eee9b7c9f154865a58b02f0c1c0d. Glyphurile ro/hu au fost verificate prin fontconfig. Social card este un PNG original 1200x630; se regenereaza la rebranding. Numele din interfata provin din APP_NAME.

## Verificari si limitari

52 teste / 667 asertiuni trecute cu si fara route cache, Pint si composer validate. Teste pentru sluguri, head metadata, XML, noindex, limbi inactive, default idempotent, 429, XSS/input invalid, login/logout, sesiune si izolarea spatiilor. Nicio cerere AI platita. MySQL local are opt migrari aplicate si patru limbi seeduite.

Verificarea vizuala automata in browser a ramas blocata de o permisiune salvata a domeniului, inclusiv dupa confirmarea utilizatorului de a repermite accesul. Nu este raportata ca efectuata. Captura furnizata de utilizator a fost folosita pentru identificarea defectului de traduceri. CSS contine breakpoints responsive; verificarea vizuala desktop/mobil ramane necesara dupa deblocarea browserului.

Inregistrarea PF/PJ si actiunile tranzactionale sunt acum livrate (registration.md). Verificarea emailului este livrata; urmeaza MFA admin si managementul complet al limbilor/documentelor. Configureaza ghidat emailul inaintea livrarii reale de mesaje; nu afisa credentiale.


## Home - referinta vizuala actualizata (30 octombrie 2026)

Hero-ul din `resources/views/pages/home.blade.php` reproduce compozitia din
fotografia furnizata: titlu pe trei randuri, badge introductiv, actiuni de
scanare/prezentare, nota de autentificare, beneficii si card de scanare suprapus
peste miniatura produsului. Telefonul si documentul sunt ilustratii HTML/CSS,
fara date personale. Butoanele de scanare trimit la ruta protejata existenta;
prezentarea duce la beneficiile de pe aceeasi pagina. Antetul home foloseste
acces circular la cont. Textele sunt localizate in ro/en/hu/bg, romana fara
diacritice. Stilurile sunt limitate la home, cu adaptare pentru mobil.

Fisiere: pagina home, layout public, componenta icon, `lang/*/site.php` si
`resources/css/app.css`. Recompilare: `composer run css:build` (Tailwind standalone).
Buildul CSS a reusit. Nu au fost rulate teste, conform preferintei de lucru.
Verificarea vizuala desktop/mobil ramane necesara: browserul local este dezactivat
in setarile Work. Nu se afirma potrivire pixel cu pixel fara aceasta verificare.
Nu este necesara configurarea unui serviciu extern.
