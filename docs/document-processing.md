# OCR si propuneri AI

Originalele raman private si nemodificate. Textul extras si rezultatele AI sunt
criptate in baza de date. Politicile workspaceului se aplica la pornire, executie,
stare si verificarea propunerilor. Joburile transporta identificatori, cu payload criptat.

## Flux si interfata

In primul pas se cere numai fisierul si optiunea AI, fara formular de metadate.
Titlul provizoriu este derivat server-side din numele fisierului si poate fi corectat ulterior.
La upload porneste OCR pe coada ocr. Optiunea AI apare imediat sub fisier,
bifata implicit conform cerintei utilizatorului. Valoarea 0 trimisa prin formular
opreste analiza AI; nu se presupune opt-in pentru clienti care omit campul.
Setarea AI_ENABLED=false ascunde optiunea si blocheaza analiza pe server.
Ollama este local; Groq optional transmite textul extras catre serviciul remote, cu informare in formular.
Dupa OCR, numai documentele cu ai_requested pornesc analiza pe coada ai.
Limba cererii originale este pastrata.

Pagina documentului arata progresul deasupra preview-ului: asteptare, OCR, analiza
si timpul scurs al etapei. Polling autorizat la doua secunde, maximum zece minute;
la pierderea conexiunii apare instructiunea de reincarcare. Nu se inventeaza procente.
Dupa upload cu AI, parametrul review=1 deschide automat formularul cu sugestii cand
rezultatul valid este gata. Fara AI se deschide direct formularul manual.
La avertismente sau erori se deschide formularul manual cu explicatia problemei, fara propuneri. Nicio metadada nu este salvata automat. Salvarea cere
confirmare explicita si verifica versiunea documentului, prevenind suprascrierea
unei editari mai recente. Utilizatorul poate respinge propunerile.

## Motoare si limite

- PDF cu text pe toate paginile: pdftotext, fara OCR inutil.
- JPEG/PNG: Tesseract ron+eng+hun.
- PDF scanat sau mixt: OCRmyPDF skip-text, apoi pdftotext; originalul ramane intact.
- Directoare temporare private, sterse in finally; procese cu timeout si argumente separate.
- Text UTF-8 maximum 500 kB. Protectiile de upload raman 10 pagini si 5 MiB.
- Free beta: 50 pagini OCR si 10 analize AI/luna, configurabile prin
  VAULT_FREE_OCR_PAGES / VAULT_FREE_AI_CALLS. Luna calendaristica UTC.
- Rezervari atomice per workspace, eliberate la eroare; finalizarea/retry nu dubleaza consumul.
  AI omis pentru text ilizibil nu consuma apeluri. Analiza executata cu avertismente consuma un apel.
- Workeri separati high/default/ocr/ai/low; OCR timeout job240s, AI100s, maxim doua incercari.
  Erorile permanente nu sunt reincercate; indisponibilitatea AI poate avea retry limitat.

## Calitatea rezultatelor

Textul OCR este input nefiabil. JSON are schema stricta, categorii si etichete din
catalogul global. Faptele propuse cer dovezi in text. Datele se normalizeaza numai
in raport cu date calendaristice prezente in sursa. Duplicate de etichete se elimina.
Text insuficient, continut nerelevant/incert, informatii fara dovezi
sau emitere viitoare produc avertisment si zero campuri pentru precompletare.
Expirarea in trecut este un avertisment neblocant: propunerile pot fi confirmate.
Nu se programeaza notificari de expirare pentru date deja trecute; modulul de remindere urmeaza.
O data de emitere in trecut este normala. AI nu certifica autenticitatea juridica.

Pentru texte peste bugetul providerului (3000 bytes Ollama / 9000 bytes Groq dupa compactarea spatiilor), analiza foloseste un extras de la inceput si final;
interfata declara aceasta limita. Nu este analiza integrala a unui document lung.
OCR bulgar dedicat nu este instalat; interfata bulgara si textul PDF nativ sunt acceptate,
dar pachetul OCR actual este ron/eng/hun. Calitatea extragerii nu este garantata.

## Performanta si verificare

Pe laptopul local, rulari recente au avut asteptare 1–3s in coada si analiza Qwen
aproximativ 14–24s; OCR a variat de la sub o secunda la cateva secunde pentru probele
scurte. Nu reprezinta SLA. Incarcarea rece, paginile multiple si cererile simultane
pot dura mai mult. Eliminarea cozii nu elimina timpul de inferenta si blocheaza HTTP.
Ollama pastreaza modelul incarcat 10 minute dupa apel. Pe placa actuala procesarea
este impartita CPU/GPU; accelerarea completa necesita o evaluare separata.

Testele automate folosesc OCR mock si Http::fake. Acopera izolarea, cotele,
idempotenta, eliberarea rezervarilor, opt-out, limba lantului OCR→AI, criptarea,
avertismentele, schema si confirmarea cu versiune. Probele reale locale au verificat
native_pdf, tesseract si ocrmypdf, precum si JSON Qwen acceptat de regulile de domeniu.
Nu se logheaza continutul documentelor sau corpurile HTTP.

## Incarcari neconfirmate si anulare

Uploadul web creeaza status=draft, pentru stocare temporara privata si procesare.
Biblioteca, cautarea si statisticile de documente/pagini includ numai status=stored.
Spatiul fizic ramane rezervat inclusiv pentru drafturi, prevenind abuzul de stocare.
Draftul este accesibil numai celui care l-a incarcat, chiar daca workspaceul are alti admini.
PATCH dupa confirmarea formularului trece atomic in stored; erorile de validare pastreaza draftul.
Clientul nu poate modifica statusul prin input. Documentele existente nu sunt reinterpretate
sau sterse automat, pentru ca lipsa editarilor nu dovedeste ca utilizatorul a renuntat.

Renunta trimite DELETE cu CSRF la documents.discard, autorizat si verificat sub lock.
Nu este un simplu link spre lista. Operatia anuleaza rezervarile procesarilor in asteptare,
elimina draftul/fisierele si elibereaza spatiul; daca stergerea fizica esueaza, PurgeDocument
reincearca pe low. Fisierul devine inaccesibil imediat. Joburile tardive nu il pot recrea.
Consumul OCR/AI deja efectuat nu se ramburseaza prin anularea incarcarii.
O incercare AI deja pornita ramane consumata la anulare, deoarece apelul remote
poate fi deja facturat; anularile repetate nu pot ocoli cota.

ExpireDocumentDraft este programat pe low la 24h dupa upload, folosind Redis delayed jobs;
nu necesita un cron separat. Verifica din nou starea si varsta sub lock si nu sterge
un document confirmat intre timp. Workerul low trebuie sa functioneze pentru expirare.
Uploadurile interne folosite pentru importuri/fixtures pot crea direct stored prin serviciu;
fluxul web impune intotdeauna draft=true.

O analiza succeeded este reutilizata, inclusiv dupa acceptare/respingere sau avertisment.
Rutele repetate nu lanseaza joburi noi si nu rezerva cota suplimentara. Butonul AI este
ascuns dupa succes; numai un esec poate fi reincercat explicit. Originalele sunt imuabile.

## Scanner web multipagina

Ruta localizata documents.scan ofera captura, galerie, miniaturi, selectie, rotire si
crop rectangular manual. Paginile se normalizeaza JPEG in browser, latura maxima1800px,
cu maximum10 pagini/5MiB cumulat. Fotografiile nu se trimit pana la confirmarea scanarii.
Conexiunile HTTPS pot folosi getUserMedia video fara audio; pe HTTP camera este ceruta
prin input capture. Permisiunea camerei ramane la utilizator. Streamul se opreste la
alegerea unei imagini, captura, submit sau parasirea paginii.

DocumentScanRequest valideaza paginile si drepturile; FileInspector verifica decodarea
reala si pixelii. ScanPdf reencodeaza imaginile cu GD si creeaza un PDF raster cu FPDF1.9
in director temporar0700, eliminat in finally. PDF-ul trece prin inspectia si limitele
obisnuite, este un draft privat si urmeaza OCR/AI. Nu exista detectie automata a marginilor,
corectie de perspectiva sau scanare nativa Expo in acest checkpoint. Verificarea pe un
telefon fizic ramane necesara; browserul desktop poate verifica selectia si editarea imaginilor.

## Scanner compact si calitatea textului tabelar

Scannerul foloseste un singur card, actiuni centrate, miniaturi si patru colturi
care se pot trage direct pe fotografie (pointer events cu pointer capture), plus
ajustare din tastele sageti. Selectia este rectangulara; nu se corecteaza perspectiva.
Cropul este pastrat separat pe fiecare pagina si se aplica explicit sau la Continua.
Bara Continua este fixa deasupra navigatiei mobile; inaltimea navigatiei este masurata,
nu presupusa. Camera se deschide numai printr-o actiune a utilizatorului.

TextQuality exclude spatiile din calculul proportiei alfanumerice, pentru ca alinierea
coloanelor din pdftotext -layout nu inseamna text ilizibil. Pentru prompt se compacteaza
spatiile orizontale, fara modificarea OCR-ului original criptat. Raspunsurile AI efective
finalizate nu se refac; un rezultat omis local (skipped_ai) poate fi reprocesat numai
daca textul trece acum evaluarea de calitate.

Cota lunara AI poate fi dezactivata prin VAULT_FREE_AI_CALLS=-1. Aceasta configurare
este folosita numai in .env local la cererea utilizatorului; valoarea implicita din
cod/example ramane 10. Rate limits, limitele documentelor si rezervarile atomice raman.
ProcessingQuotaExceeded produce status quota_exceeded si explicatie clara, inclusiv
cand cota este atinsa intre OCR si AI, fara a pretinde ca documentul nu a fost inteles.
