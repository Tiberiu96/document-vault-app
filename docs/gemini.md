# Gemini - analiza remote optionala

Provider independent, fara SDK nou: HTTPS fix generativelanguage.googleapis.com/v1beta. Cheia se transmite numai in headerul x-goog-api-key, niciodata in URL. Fara redirecturi, tools, cautare, fisiere incarcate separat sau fallback implicit catre alti provideri.

Configurare locala in .env (0600, ignorat de Git):

```dotenv
AI_ENABLED=true
AI_PROVIDER=gemini
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.5-flash-lite
```

Modelul implicit a fost ales dupa verificarea modelelor disponibile pentru proiect si o proba fictiva. Lista modelelor nu confirma cota de inferenta: 429 poate indica o limita a proiectului sau cote indisponibile. Nu roti chei pentru ocolirea limitelor. Modelul configurat se verifica prin GET /models/{model}; generateContent foloseste responseMimeType application/json si responseJsonSchema. Modelul 2.5 foloseste thinkingBudget=0; celelalte modele configurate folosesc thinkingLevel=low. Fara activare automata a facturarii.

Textul OCR integral este trimis pentru analizele solicitate, cu limita explicita de 256000 bytes pentru mesaje/schema. Nu se taie inceputul sau mijlocul documentului. Limita locala nu garanteaza incadrarea in cotele/tokenizarea providerului. Raspuns maximum 4096 tokenuri, timeout HTTP 60s, conexiune 5s. Trunchierea, refuzurile, JSON invalid sau campurile fara dovezi nu ajung in formular. Gandirea interna a modelului nu este folosita ca rezultat. Validarea de domeniu si confirmarea explicita a documentului raman obligatorii.

Procesare pe coada ai, payload criptat, afterCommit. Fiecare run fixeaza providerul la crearea sa; o schimbare de configuratie nu redirectioneaza joburi vechi. 429, 408 si 5xx au retry limitat; Retry-After si google.rpc.RetryInfo sunt limitate la 1-300s. 400/401/403/404/413 sunt permanente. Codurile diagnostice nu includ corp HTTP, cheia, OCR sau metadate private. Testele forteaza GEMINI_API_KEY gol si folosesc exclusiv chei fictive/HTTP mock.

Dupa configurare: php artisan config:clear si php artisan horizon:terminate. Verificare fara documente: php artisan vault:ai:check; proba fictiva: php artisan vault:ai:check --infer. Nu afisa sau copia cheia in conversatii/loguri.

## Cote, date si productie

Free Tier este disponibil numai pentru modelele si cotele atribuite proiectului; limitele efective se verifica in Google AI Studio. Pentru aplicatii oferite utilizatorilor din SEE/Elvetia/UK, termenii Google cer Paid Services; nu considera cheia gratuita o configuratie de productie. Pentru utilizatorii din SEE, regulile Paid Services privind utilizarea datelor se aplica inclusiv cotei gratuite; aceasta exceptie nu inseamna procesare exclusiv in UE sau lipsa oricarei retentii. Revizuieste configuratia proiectului si termenii inainte de lansare.

Surse verificate la integrare:
- https://ai.google.dev/api/generate-content
- https://ai.google.dev/gemini-api/docs/generate-content/structured-output
- https://ai.google.dev/gemini-api/docs/rate-limits
- https://ai.google.dev/gemini-api/terms

Proba locala 2026-10-28: Flash 3.8 a depasit timeoutul de 60s, 2.5 Flash nu a acceptat inferenta (404), Flash-Lite 3.5 a raspuns corect unei probe simple (~1s) si unei facturi fictive prin schema completa (~1.1s). Niciun document personal nu a fost folosit pentru aceste probe. Aceste masuratori nu garanteaza latenta sau acuratetea pe documente reale.
