# Integrari si configurare ghidata

Pentru fiecare serviciu: scop, provider recomandat, free tier adecvat, costuri/limite/retentie verificate la activare; intreaba daca utilizatorul are cont/instanta; explica variabilele si configurarea locala; asteapta confirmarea; testeaza sigur; apoi integreaza. Nu cere secrete in conversatie si nu imprima .env.

| Serviciu | Scop | Stare / campuri planificate |
| --- | --- | --- |
| MySQL local | Date tranzactionale | Instalat, conexiune locala verificata; DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD |
| Redis local | Cache, cote, queues | Instalat, conexiune locala verificata; REDIS_HOST, REDIS_PORT, REDIS_USERNAME, REDIS_PASSWORD, REDIS_DB, REDIS_CACHE_DB |
| Email | Verificare, invitatii, reminders | Fara provider; MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS |
| Firebase | Push mobil | Dezactivat; FIREBASE_PROJECT_ID, FIREBASE_CREDENTIALS_PATH, fisier privat in afara public/ |
| Gemini | AI beta optional | Dezactivat; GEMINI_API_KEY |
| OpenAI/OpenRouter | AI optional ulterior | Dezactivat; OPENAI_API_KEY, OPENROUTER_API_KEY |
| Ollama | AI local optional | Neconfigurat; OLLAMA_BASE_URL |
| Frankfurter | Curs valutar | Neintegrat; verifica API-ul si conditiile la etapa 8 |
| Analytics/plati/hosting | Crestere si productie | Fara configurare; decizie ghidata cand devin necesare |

MAIL_MAILER=array este transport local fara livrare externa; nu pretinde ca emailul real functioneaza. Toate cheile de provideri sunt goale si activarile sunt false. Campurile viitoare din .env.example sunt rezervari, nu integrari implementate.

Analytics: contract independent de provider, consimtamant inainte de dispatch, oprit implicit. Evenimente planificate: sign_up, login, document_uploaded, document_scanned, ocr_completed, reminder_created, subscription_started, purchase. Payload allowlist cu event_id pentru deduplicare, timestamp si proprietati nesensibile strict necesare; purchase foloseste identificator de tranzactie opac pentru deduplicare. Fara nume de fisiere, continut, OCR, date personale sau identificatori workspace/user exportati implicit catre ads. Schema si teste de consimtamant se implementeaza inaintea primului provider.
