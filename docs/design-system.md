# Design system — Light Editorial Premium

Tokenuri si componente in resources/css/app.css, compilate cu Tailwind 4.3.3 standalone. Pagini publice, demo, login si panou privat sunt implementate; detalii in web-foundation.md.

| Rol | Valoare |
| --- | --- |
| Background | #F7F5F0 |
| Surface | #FFFFFF |
| Text | #242724 |
| Secondary | #77766F |
| Bronze | #8B7355 |
| Sage | #879184 |
| Border | #E6E2DA |

Titluri DM Serif Display si interfata DM Sans, gazduite local cu licente OFL. Glyphurile ro/hu sunt verificate. Fara requesturi catre font providers.

Tokenurile de brand secondary/bronze nu garanteaza contrast pentru orice marime/context. Foloseste text-muted mai inchis pentru text mic, action mai inchis pentru butoane, focus vizibil; verifica WCAG AA la componentele reale. Touch targets recomandate 44px, navigare tastatura, labels explicite, erori text si respect pentru reduced motion.

Componente incrementale: layouts/sidebar/nav, butoane/formulare, tabele/carduri/dropdown/search/filter/paginare, upload/preview, notificari/reminders/modals, loading/empty/error. Fara gradient decorativ, umbre excesive sau animatii inutile. Mobile reutilizeaza aceleasi valori semantice in TypeScript la etapa 9.

Referinte solicitate: design-layouts/README.md si imaginile 01-desktop-dashboard.png, 02-mobile-dashboard.png, 03-landing-page.png, 04-desktop-documents.png, 05-mobile-document-details.png, 06-mobile-reminders.png. Directorul si README.md sunt prezente; toate cele sase imagini au fost inspectate. Sunt referinte vizuale, nu ecrane functionale; preturile, statisticile, numele si datele sunt demonstrative. Pastreaza-le la curatenie.


## Observatii din referinte

- Desktop: sidebar persistenta cu selector workspace, cautare in antet, titluri serif ample, actiuni scanare/upload, patru carduri de sumar, tabel de documente recente si coloana pentru remindere/procesare.
- Lista documente desktop: sumar pe categorii, filtre compacte, selectie de randuri si panou lateral de preview cu metadata, paginare PDF si actiuni document/reminder.
- Mobile: antet cu marca/workspace, actiuni de scanare/upload vizibile, carduri compacte, navigare fixa jos cu scanare centrala si respect pentru safe area.
- Detalii mobile: preview document, status, campuri OCR/AI editabile cu provenienta marcata, confirmare manuala si actiuni clare. Nu considera sugestiile AI drept date verificate automat.
- Remindere mobile: sumar, taburi toate/active/rezolvate, carduri cu scadenta si actiuni rezolvare/amanare/deschidere.
- Landing: hero editorial cu preview produs, functionalitati si sectiuni web/mobile/planuri. Nu publica testimoniale, statistici, disponibilitate App Store sau preturi demonstrative drept afirmatii reale.

Foloseste paleta si structura vizuala, cu text ro fara diacritice, contrast verificat si componente accesibile. Texturile/elementele decorative din mockup nu justifica animatii, gradienturi sau dependente noi. Scanarea nativa ramane o facilitate mobila; actiunile web trebuie sa reflecte numai capabilitatile implementate.


## Ajustari cerute dupa prima livrare

Text functional minimum 16px pe desktop si mobil, inclusiv labels, hinturi, badge-uri, tabele, navigatie si butoane. Exceptie vizuala: miniatura HTML a produsului este o ilustratie cu role=img si text proportional, nu o interfata interactiva. Titlurile isi pastreaza ierarhia editoriala.

Contul din antet este acum un icon de utilizator intr-un cerc de 44px, cu accent bronz, aria-label/title localizate si stare pentru pagina activa. Pe mobil si tablete pana la 1120px, antetul are un singur rand cu logo, login/profil si un meniu details nativ. Meniul contine navigatia, limbile si actiunea de creare cont/logout. Nu este necesar un framework JS nou.

BG foloseste Noto Serif/Noto Sans self-hosted in subseturi WOFF2, cu licente OFL incluse, descarcate de la Google Fonts. Au fost verificate glyphurile alfabetului bulgar, inclusiv U+040D/U+045D, pe toate cele trei fisiere. Checksumuri: Noto Sans 400 4ddba4e887629f9ac1a1d44f91e32ad32aa713e07a69c1a8652a3e7bb98b8b97; Noto Sans 700 704540f0618003c75deb13712b740099744e82918a88fd2c90e06065d8f1d060; Noto Serif 0ec886d8ef321fb3231c4df38ca39a59ecf79d2368fa094207b179137587cdd0. Fonturile DM pentru ro/en/hu sunt pastrate.

Verificarea vizuala directa ramane neefectuata: instrumentul refuza originea chiar dupa captura cu Always allow. Nu raporta o verificare in browser reusita pana la un rezultat real; nu ocoli politica prin alte mecanisme.
