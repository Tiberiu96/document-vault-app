# Localizare

ro implicit, en, hu, bg; ro fara diacritice numai pentru textul aplicatiei. Nu transforma datele utilizatorilor. hu pastreaza diacriticele.

## Rutare livrata pentru paginile disponibile

| Nume intern | ro | en | hu |
| --- | --- | --- | --- |
| home | /ro | /en | /hu |
| pricing | /ro/preturi | /en/pricing | /hu/arak |
| login | /ro/autentificare | /en/login | /hu/bejelentkezes |
| dashboard | /ro/panou | /en/dashboard | /hu/iranyitopult |

niels-numbers/laravel-localizer este selectia propusa: versiunea stabila 1.4.1 declara Laravel 13 si rute statice compatibile cu route:cache. mcamara indica acelasi succesor pentru proiecte noi. Pachetul 1.4.1 este instalat; config/localizer.php declara ro/en/hu/bg si prefixul implicit vizibil. Paginile publice, demo-ul, loginul si panoul au sluguri traduse; testele lor au trecut si cu route:cache. Prefixul limbii implicite ramane vizibil (hide_default_locale=false); numele interne nu se traduc.

Rutele pentru limbile livrate sunt inregistrate static din fisiere versionate, fara query DB la route:cache. Un middleware verifica la fiecare request daca limba este activa si pregatita; o limba dezactivata raspunde 404 inclusiv cu cache de rute. Selectorul, sitemap si hreflang folosesc aceeasi sursa de disponibilitate, invalidata la schimbare.

Tabela languages implementata: locale unique, display_name, native_name, default_currency, is_active, is_default, translation_readiness. Un nou rand NU expune limba pana la traduceri/rute si deployment verificat. Modificarile defaultului necesita tranzactie/lock si exact un default activ/pregatit. Nu sterge/dezactiva defaultul fara inlocuitor valid. Management numai platform admin.

Admin in routes/admin.php, prefix /admin, middleware ro, fara selector. API /api/v1 cu chei/coduri stabile, mesaje traductibile. Endpointurile tehnice sunt neutre. Teste: toate slugurile si linkurile dupa route:cache, selector, fallback, limba dezactivata cu cache cald, admin fara prefix, API fara traducerea cheilor.

Surse: [pachet propus](https://packagist.org/packages/niels-numbers/laravel-localizer), [alternativa evaluata](https://packagist.org/packages/mcamara/laravel-localization).

Structura languages/LanguageSeeder si serviciile LanguageCatalog/LanguageManager sunt livrate. Disponibilitatea se citeste direct din DB, fara cache persistent; un middleware refuza limbile inactive inclusiv cu rute in cache. Interfata admin CRUD completa asteapta MFA. Implementarea si probele sunt in web-foundation.md.


Bulgara (bg) a fost solicitata explicit suplimentar celor trei limbi initiale. Manifestul, localizer si LanguageSeeder livreaza acum patru limbi. Sluguri bg: /bg, /bg/funktsii, /bg/tseni, /bg/chesto-zadavani-vaprosi, /bg/demo, /bg/vhod, /bg/registratsiya, /bg/tablo, POST /bg/izhod si /bg/prostranstva/izbor. Limba implicita ramane ro; admin ramane numai ro. Interfata bulgara pastreaza alfabetul chirilic. Inregistrarea PJ ramane pentru firme romanesti, indiferent de limba UI.

BG afiseaza EUR, corespunzator monedei actuale: Bulgaria a adoptat euro la 1 ianuarie 2026 ([BCE](https://www.ecb.europa.eu/press/pr/date/2026/html/ecb.pr260101~c830245e42.en.html)). Plata ramane RON conform planului; nu au fost implementate conversii valutare sau plati. Sitemap are 16 URL-uri cand toate cele patru limbi sunt active. Testele verifica dezactivarea BG, textele, metadata, moneda si inregistrarea/logarea localizata.
