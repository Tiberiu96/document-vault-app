# Groq - analiza remote optionala

GroqProvider implementeaza AiProviderInterface prin API HTTPS fix api.groq.com,
fara redirecturi, tools, streaming sau fallback catre alt provider.
Model initial: openai/gpt-oss-20b, reasoning_effort=low, schema JSON stricta.
Constrangerile de lungime/pattern sunt verificate suplimentar in serviciul de domeniu.
Timeout45s, maximum4096 tokenuri generate, buget64000 bytes pentru prompt+schema. Textul compactat se trimite integral, fara taierea mijlocului documentului. Bugetul este o limita conservatoare in bytes, nu un calcul exact de tokenuri. Documentele peste buget esueaza explicit cu PROMPT_TOO_LARGE inainte de apelul extern; OCR si completarea manuala raman disponibile. Analiza pe sectiuni nu este implementata. Ollama pastreaza extrasul de 3000 bytes pentru contextul local redus.

Configurarea se face numai local in .env (0600):

```dotenv
AI_ENABLED=true
AI_PROVIDER=groq
GROQ_API_KEY=
GROQ_MODEL=openai/gpt-oss-20b
```

Nu publica cheia si nu o introduce in conversatii, comenzi care o afiseaza sau loguri.
Daca este divulgata, revoc-o din consola Groq si inlocuieste-o local.
Dupa configurare: php artisan config:clear si systemctl --user restart vaulty-horizon.
Verificare conexiune/model: php artisan vault:ai:check.
Proba fictiva: php artisan vault:ai:check --infer.

OCR si originalele raman locale. Textul extras din document este transmis catre
Groq numai pentru analize solicitate. UI declara providerul remote langa optiunea AI.
Debifarea pastreaza numai OCR local si completarea manuala. Nu declara ca inferenta
remote ramane pe laptop sau ca datele sunt gazduite in UE.
Fiecare run fixeaza providerul ales la solicitare, inclusiv lantul OCR→AI;
runurile vechi fara provider raman pe Ollama. Schimbarea configuratiei nu redirectioneaza
joburi deja solicitate catre un alt provider.

Cotele workspaceului, validarile calitatii, avertismentele si confirmarea explicita
raman aplicabile. Coduri 401/403 sunt permanente; 429/erori temporare au retry limitat
si respecta Retry-After numeric. Exceptiile nu includ corpul HTTP sau credentiale.
Testele automate folosesc HTTP mock; phpunit forteaza cheia remote goala si providerul
Ollama, iar testele Groq seteaza exclusiv credentiale fictive.

Documentatie verificata la integrare:
- https://console.groq.com/docs/structured-outputs
- https://console.groq.com/docs/model/openai/gpt-oss-20b
- https://console.groq.com/docs/your-data

Planul gratuit are limite de utilizare specifice contului/modelului, fara SLA promis.
Pagina modelului listeaza $0.075/1M tokenuri input si $0.30/1M output; verifica tarifele
si limitele in consola inainte de productie. Groq declara lipsa retentiei implicite a
continutului de inferenta, cu exceptii pentru functii ce necesita stocare si investigatii
de fiabilitate/abuz; metadatele de utilizare sunt retinute. Revizuieste Data Controls,
termenii si acordurile de procesare inainte de documente confidentiale in productie.

Testarea locala foloseste VAULT_FREE_AI_CALLS=-1 la cererea utilizatorului (fara cota lunara AI); rate limits si limitele de fisiere raman aplicabile.

Diagnostic raspuns: AI_OUTPUT_TRUNCATED pentru limita de generare, AI_RESPONSE_REFUSED pentru refuz si AI_INVALID_<CAMP> pentru validarea unui camp cunoscut. Se pastreaza numai codul, niciodata raspunsul brut sau valori private. Raspunsurile incomplete nu completeaza formularul si nu se retrimit automat. Bugetul de generare mai mare nu garanteaza acceptarea fiecarui document. Politele folosesc prima totala, nu limita despagubirii, si separa data emiterii de perioada de valabilitate.
