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
Text insuficient, continut nerelevant/incert, informatii fara dovezi, expirare trecuta
sau emitere viitoare produc avertisment si zero campuri pentru precompletare.
O data de emitere in trecut este normala. AI nu certifica autenticitatea juridica.

Pentru texte de peste 3000 bytes, analiza foloseste un extras de la inceput si final;
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
