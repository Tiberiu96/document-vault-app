# Biblioteca privata de documente

Etapa 4 este implementata pentru web, in ro/en/hu/bg: dashboard cu date reale,
lista cu cautare/filtre/paginare, upload drag-and-drop, metadate, sumar, categorii,
etichete, descarcare privata, cos/restaurare si stergere definitiva autorizata.

## Limite si securitate

Free: 10 pagini per document si 5 MiB per fisier, conform deciziei utilizatorului.
PlanLimitsService este autoritatea pentru limite. Cota beta de stocare este
configurabila prin VAULT_FREE_STORAGE_BYTES, implicit 100 MiB per workspace.
Contorul include originalele, imaginile de previzualizare si documentele din cos.
Rezervarile folosesc tranzactii si lockForUpdate pe workspace. Test MySQL real:
doua uploaduri simultane la o cota de un singur fisier, unul acceptat, unul respins,
contor corect. Datele acelui test au fost eliminate.

- Tipuri: PDF, JPEG si PNG, verificate prin continut si extensie, nu doar header HTTP.
- qpdf verifica structura PDF, paginile, criptarea si actiunile active. PDF-urile
  cu parola, JavaScript, formulare, actiuni automate sau atasamente sunt respinse.
- Imagini: decodare reala GD, maximum 25 megapixeli.
- Fisierele sunt in storage/app/private/workspaces/{id}/documents/{uuid}/.
  Nu exista URL public, storage:link sau expunere a storage_path in HTML/JSON.
- Accesul cere autentificare, email confirmat, workspace activ si Policy.
  Rolurile Owner/Admin gestioneaza documentele; Member isi modifica propriile
  documente; Accountant are acces de citire. Un super_admin nu ocoleste izolarea.
- Sumele originale se pastreaza in minor units intregi, impreuna cu moneda originala.
- Descrierea scurta are maximum 240 caractere si apare ca ultima coloana Sumar.
  Descrierea detaliata este separata. Nu exista rezumate AI inventate.

## Previzualizare si stergere

GenerateDocumentPreview pe default rasterizeaza PDF-ul in JPEG private cu
pdftoppm, maximum 1400 px, pana la 20 MiB total. Originalul nu se modifica.
Jobul este idempotent, cu maximum doua incercari si timeout 180s. Supervisorul
default are timeout 200s, sub retry_after Redis 360s. Cota insuficienta este
permanenta si nu se reincerca. Imaginile JPEG/PNG pot fi afisate direct dupa Policy.

Stergerea normala este reversibila (cos). Stergerea definitiva necesita confirmare
si Owner/Admin; PurgeDocument ruleaza pe low, elimina originalul si preview-urile,
apoi elibereaza cota. Un retry nu scade contorul de doua ori. Nu exista retentie
automata care sa elimine documente fara o politica aprobata.

## Catalog comun

CategorySeeder: 16 categorii personale si 14 business, cu system_key stabil.
TagSeeder: 8 etichete globale, cu system_key stabil. Traducerile schimba numai
eticheta afisata. Catalogul nu variaza in functie de raspunsul unui model AI.
Categoriile/etichetele globale sunt read-only in workspace; administrarea lor va fi
in panoul platformei, dupa MFA. Personalizarile locale raman private workspace-ului.
Stergerea unei categorii sau etichete nu sterge documentele asociate.
AI va alege implicit din catalogul global si nu va crea automat categorii sau
etichete. Utilizatorul va confirma/corecta propunerile; procesarea este descrisa in document-processing.md.

## Verificari

DocumentLibraryTest acopera PDF 10 pagini acceptat/11 respins, continut activ,
criptare, formate false, cote, duplicate, permisiuni, metadate/sume, catalog,
preview si purge idempotente. Browserul integrat a verificat uploadul real,
detaliile si preview-ul raster. Verificare responsive: sase pagini la sapte latimi
(320, 390, 515, 768, 1024, 1440, 1920 px), fara overflow orizontal al paginii.
Numele lungi de workspace se impacheteaza fara trunchiere. Sunt simulari de viewport,
nu certificare pe hardware/Safari. Artefactul HTML temporar de QA a fost eliminat.

OCR si propunerile AI optionale sunt documentate in document-processing.md.
Nu se pretinde scanare nativa mobila sau protectie antivirus completa.
