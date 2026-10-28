# Biblioteca privata de documente

Etapa 4 este implementata pentru web, in ro/en/hu/bg: dashboard cu date reale,
lista cu cautare/filtre/paginare, upload drag-and-drop, metadate, sumar, categorii,
etichete, descarcare privata, stergere definitiva autorizata, cu confirmare explicita.

## Limite si securitate

Free: 10 pagini per document si 5 MiB per fisier, conform deciziei utilizatorului.
PlanLimitsService este autoritatea pentru limite. Cota beta de stocare este
configurabila prin VAULT_FREE_STORAGE_BYTES, implicit 100 MiB per workspace.
Contorul include originalele si imaginile de previzualizare pana la eliminarea fizica.
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
  Formularul foloseste numai descrierea scurta. Descrierile detaliate existente
  se pastreaza la editare pentru a evita pierderea datelor. Nu exista rezumate AI inventate.

## Previzualizare si stergere

Dupa confirmare, GenerateDocumentPreview pe default rasterizeaza PDF-ul in JPEG private cu
pdftoppm, maximum 1400 px, pana la 20 MiB total. Originalul nu se modifica.
Jobul este idempotent, cu maximum doua incercari si timeout 180s. Supervisorul
default are timeout 200s, sub retry_after Redis 360s. Cota insuficienta este
permanenta si nu se reincerca. Imaginile JPEG/PNG pot fi afisate direct dupa Policy.

Stergerea este definitiva: linkul Sterge deschide un ecran cu titlul documentului,
consecintele si actiunile Da/Nu. GET nu modifica date; DELETE cere confirm=1, CSRF
si Policy delete sub lock de workspace. Nu exista rute de restaurare sau cos public.
Dupa confirmare se opresc procesarile si accesul dispare. PurgeDocument elimina
imediat originalul, preview-urile si inregistrarea cu relatiile sale. Numai la esec
IO se reincearca pe low; contorul scade dupa eliminarea efectiva. Marcajul intern
soft-delete este exclusiv pentru curatarea sigura si nu permite restaurare.
Doua inregistrari din vechiul cos, anterioare schimbarii, nu sunt eliminate automat;
o schimbare de interfata nu autorizeaza stergerea retroactiva a fisierelor existente.

Pe mobil, lista are carduri separate cu titlu/furnizor, categorie si suma daca exista,
sumar optional, format/pagini si data. Campurile goale si statusul repetitiv Stocat
nu sunt afisate. Tabelul desktop ramane disponibil la peste 800px.

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

Uploadul web este temporar pana la confirmare; drafturile nu apar in biblioteca. Renunta le elimina, iar cele abandonate expira dupa 24h prin coada low. Detalii in document-processing.md.

Copia de analiza a draftului este eliminata dupa procesare, iar browserul pastreaza
originalul in memorie pentru retrimiterea la confirmare. Drafturile nu genereaza
preview pe server. Originalele confirmate raman private; reselectarea trebuie sa
aiba acelasi SHA-256, MIME si numar de pagini.
