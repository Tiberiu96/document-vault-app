# SEO si continut public

Paginile indexabile sunt home/features/pricing/faq, cu sluguri localizate si canonical din APP_URL, fara query strings. Au titlu/descriere localizata, OG/Twitter, social card PNG local, HTML semantic si JSON-LD WebPage fara afirmatii/preturi inventate. hreflang si x-default includ doar versiuni active si pregatite. Pagina de prezentare descrie sincer functionalitatile planificate.

Paginile login/panou/demo, admin, endpointurile tehnice si erorile au noindex. Productia permite indexarea numai rutelor marcate indexable cu status 200; local/staging au noindex global. Autentificarea si Policies raman controale de acces; robots nu este securitate.

Sitemap XML deriva din registrul config/vault.php si LanguageCatalog; exclude auth/admin/API/demo/private. Disponibilitatea se citeste fara cache persistent, astfel ca dezactivarea unei limbi elimina imediat paginile din sitemap, selector si hreflang, chiar cu route cache cald.

robots.txt este generat de Laravel, fara fisier public static: local disallow, productie allow si URL sitemap. Nginx local adauga noindex intentionat; nu reutiliza aceasta configuratie pentru productie.

/llms.txt contine numele configurabil, descrierea reala a proiectului si linkuri publice localizate. Nu include documente private, admin sau detalii interne; nu promite efecte de ranking.

Testele valideaza canonical, hreflang, XML, disabled locale, noindex pe medii, excluderea auth/admin si llms.txt. Search Console/analytics nu sunt configurate; necesita workflow ghidat si consimtamant inainte de activare. Vezi web-foundation.md pentru checkpoint.
