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

## Email local: Mailpit

Configurat Mailpit v1.32.0, binar oficial Linux amd64, SHA256 arhiva verificat:
`4b5993c978e8604c70d43927e2ad103b311bba3b1822fa0cf1d4ee4ec890a93c`.
Sursa: https://github.com/axllent/mailpit/releases/tag/v1.32.0
Documentatie: https://mailpit.axllent.org/docs/install/

Binarul este in `~/.local/bin/mailpit`, baza cu emailuri in
`~/.local/share/vaulty-mailpit/` (director 0700, fisiere create cu umask 0077).
Serviciul utilizatorului se configureaza din `deploy/systemd/vaulty-mailpit.service`.
Nu necesita cont extern, parola sau privilegii root. Gratuit pentru dezvoltare;
nu livreaza mesaje pe internet. Capturile pot contine linkuri de autentificare:
nu exporta mesajele in Git sau in conversatii. Limita locala: 200 de mesaje.

- Interfata: http://127.0.0.1:8025/
- SMTP: `127.0.0.1:1025`, fara autentificare/TLS, numai loopback.
- `.env`: `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`,
  `MAIL_SCHEME=null`, `MAIL_URL=null`, `MAIL_USERNAME=null`, `MAIL_PASSWORD=null`,
  `MAIL_FROM_ADDRESS=no-reply@vaulty.test`, `MAIL_FROM_NAME="${APP_NAME}"`.
- `APP_URL` trebuie sa fie exact originea accesibila. Cu artisan serve este
  `http://vaulty.test:8000`; dupa confirmarea Nginx devine `http://vaulty.test`.
  Dupa schimbare: `php artisan config:clear` si
  `systemctl --user restart vaulty-horizon`. Retrimite mesajele, cele vechi nu se modifica.

Serviciile se instaleaza local fara Bash temporar:

```bash
mkdir -p ~/.config/systemd/user ~/.local/share/vaulty-mailpit
chmod 700 ~/.local/share/vaulty-mailpit
install -m 0644 deploy/systemd/vaulty-mailpit.service ~/.config/systemd/user/
install -m 0644 deploy/systemd/vaulty-horizon.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now vaulty-mailpit vaulty-horizon
systemctl --user status vaulty-mailpit vaulty-horizon
php artisan horizon:status
```

Serviciile utilizatorului pornesc la sesiunea lui si nu sunt configuratie de productie.
Nu porni in paralel un al doilea proces manual Horizon. Serviciul reporneste automat
la iesire; dupa deploy restartul lui incarca codul nou. Dashboardul Horizon ramane
inchis pana la implementarea MFA, chiar daca workerii functioneaza.

Providerul de email real pentru beta/productie se alege si configureaza separat,
cu domeniu verificat, SPF/DKIM/DMARC si credentiale numai in mediul securizat.
