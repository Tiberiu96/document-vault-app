# Checkpoint web, localizare si autentificare

## Livrare

Pagina publica si demo-ul pornesc de la cele sase referinte din design-layouts/, inspectate impreuna cu README-ul lor. Layout editorial, carduri, sidebar demo, navigare mobila, butoane, iconuri SVG, badge-uri, formulare, filtre, tabele, empty/error states si FAQ native details sunt componente Blade reutilizabile. Nu sunt folosite Node, Vite, CDN-uri de fonturi, tracking sau API-uri externe.

Pagina publica descrie directia produsului si stadiul dezvoltarii. Fara testimoniale, statistici, preturi sau disponibilitate in magazine inventate. Demo-ul foloseste numai patru documente fictive, fara upload si fara scriere de documente in baza. Cautarea foloseste Form Request, escaping si limita de 60/minut per IP. Nu confunda demo-ul cu panoul real.

Loginul este accesibil din antet si prin /ro/autentificare, /en/login, /hu/bejelentkezes. Panoul real este /ro/panou, /en/dashboard, /hu/iranyitopult, cu auth si noindex. Logout numai POST cu CSRF. Nu exista inca inregistrare sau resetare/verificare prin email; providerul de email trebuie configurat ghidat inainte de livrare reala.

Autentificarea normalizeaza emailul, foloseste hashurile Laravel si regenereaza sesiunea la succes. Limite: 5 esecuri/minut per cont, agregate intre IP-uri, si 20 esecuri/minut per IP; cheile de rate limit contin hashuri, nu emailuri. Lockout HTTP 429 cu Retry-After; mesaj generic pentru credentiale gresite. Parola nu este pastrata in old input. Logout invalideaza sesiunea si regenereaza tokenul CSRF. Panoul arata numai membershipurile autorizate prin WorkspacePolicy; conturile globale nu primesc bypass pentru spatii personale straine.

## Limbi si rutare

Migratia languages si LanguageSeeder livreaza ro/en/hu, valute RON/EUR/HUF. Seederul este idempotent si nu reactiveaza limbi dezactivate. DatabaseSeeder nu creeaza conturi.

Pachetul niels-numbers/laravel-localizer 1.4.1 inregistreaza rute traduse statice, cu prefix inclusiv ro. Numele interne home/features/pricing/faq/demo/login/dashboard/logout raman stabile. SetLocale/RedirectLocale ruleaza in web inainte de bindings; admin si API nu sunt traduse. Root redirectioneaza catre defaultul activ din baza, independent de browser.

LanguageCatalog citeste disponibilitatea direct din baza, fara cache persistent: modificarile sunt reflectate imediat in middleware, selector, sitemap si hreflang. Manifestul config/vault.php defineste limbile cu rute/traduceri livrate. Un rand nou nu publica o limba. LanguageManager autorizeaza numai super_admin, blocheaza dezactivarea defaultului si schimba defaultul tranzactional sub lock. Indexul unic pe default_slot previne doua defaulturi simultane. Nu este inca expusa o interfata administrativa pentru creare/editare/stergere; aceasta necesita auth admin si MFA.

Dictionarele au chei imbricate reale. O problema initiala cu chei precum scan.title stocate literal a fost corectata; testele verifica fiecare cheie prin translator si resping cheile site.* nerezolvate din HTML.

## SEO si privacy

Metadata localizata pentru paginile publice: titlu/descriere, canonical fara query din APP_URL, hreflang si x-default numai pentru limbi active, OG/Twitter cu imagine locala si JSON-LD WebPage fara preturi inventate. Sitemap contine numai cele patru pagini publice per limba activa; demo/auth/admin/API sunt excluse. robots.txt este generat de Laravel: staging/local disallow, productie allow plus sitemap. llms.txt reflecta doar functionalitatile/stadiul real.

Middleware-ul de indexare permite indexarea numai pentru rute marcate indexable in productie cu status 200. Auth, demo, admin, health si erorile au noindex. Configuratia Nginx locala adauga intentionat noindex peste tot; nu este configuratie de productie.

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

52 teste / 667 asertiuni trecute cu si fara route cache, Pint si composer validate. Teste pentru sluguri, head metadata, XML, noindex, limbi inactive, default idempotent, 429, XSS/input invalid, login/logout, sesiune si izolarea spatiilor. Nicio cerere AI platita. MySQL local are opt migrari aplicate si trei limbi seeduite.

Verificarea vizuala automata in browser a ramas blocata de o permisiune salvata a domeniului, inclusiv dupa confirmarea utilizatorului de a repermite accesul. Nu este raportata ca efectuata. Captura furnizata de utilizator a fost folosita pentru identificarea defectului de traduceri. CSS contine breakpoints responsive; verificarea vizuala desktop/mobil ramane necesara dupa deblocarea browserului.

Urmeaza inregistrarea PF/PJ, actiunile tranzactionale de creare a spatiilor, verificarea emailului, MFA admin si apoi managementul complet al limbilor/documentelor. Configureaza ghidat emailul inaintea livrarii reale de mesaje; nu afisa credentiale.
