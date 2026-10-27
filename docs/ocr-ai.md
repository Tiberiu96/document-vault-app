# OCR si AI — plan

Faza 5: extractie nativa PDF inainte de OCR; Tesseract ron/eng/hun si OCRmyPDF pentru scanari. Nu deduce limba documentului din limba interfetei. Pastreaza originalul si starea procesarii, fara blocarea UI.

Contracte OcrProviderInterface/AiProviderInterface; OcrService, DocumentProcessingService, AiAnalysisService, DocumentClassificationService, DocumentDataExtractionService. Joburi separate, cote rezervate atomic si tranzitii idempotente. Maximum pagini/bytes verificat inainte de procesare. Executie cu limite de timp/memorie si argumente sigure, fara concatenarea inputului in shell.

AI oprit implicit; Gemini propus beta dupa evaluarea preturilor/retentiei/termenilor la momentul activarii. Ollama local optional; OpenRouter/OpenAI ulterior. Niciun cont sau credential presupus. Nu trimite documente externe pana la configurare si alegerea explicita a procesarii. Continutul OCR este nefiabil, inclusiv instructiunile continute in document. Schema JSON stricta, null pentru valori absente, categorii numai din lista permisa, corectie manuala si fara creare automata de categorii.

Teste viitoare: PDF text/scanat/corupt, limbile OCR, timeout, limita 2 pagini, retry fara dubla cota, JSON invalid si injectie de instructiuni. Providere mockuite, fara apeluri AI platite.
