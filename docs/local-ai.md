# Ollama si Qwen local

Ollama v0.40.11 instalat din arhiva oficiala Linux amd64, SHA256 verificat:
7bedab90dd122661be78798e9f0edf37fccf0cccf2699d2c58ea1a3afdc04b6d.
Sursa: https://github.com/ollama/ollama/releases/tag/v0.40.11
Model: qwen3.5:4b, aproximativ 3.3 GB, identificator local d8b0f5e9760c.
Model si licenta: https://ollama.com/library/qwen3.5:4b

Runtime: ~/.local/share/ollama/runtime; comanda: ~/.local/bin/ollama.
Modele: ~/.local/share/vaulty-ollama/models (0700, umask 0077).
Serviciu utilizator: deploy/systemd/vaulty-ollama.service.
API numai loopback: http://127.0.0.1:11434, fara cont sau cheie API.
OLLAMA_NO_CLOUD=1 dezactiveaza modelele cloud si cautarea web.
Nu se adauga proxy Nginx public, CORS larg sau acces pe LAN.
Documentatie: https://docs.ollama.com/faq si https://docs.ollama.com/linux

## Configurare Laravel

.env local: AI_ENABLED=true, AI_PROVIDER=ollama,
OLLAMA_BASE_URL=http://127.0.0.1:11434, OLLAMA_MODEL=qwen3.5:4b,
OLLAMA_TIMEOUT=90, OLLAMA_NUM_CTX=8192. .env.example ramane AI_ENABLED=false.
Dupa schimbare: php artisan config:clear si systemctl --user restart vaulty-horizon.

AiProviderInterface separa transportul. OllamaProvider accepta numai IP loopback,
fara redirecturi HTTP sau modele cloud. Raspunsurile JSON trebuie validate de
apelant conform regulilor de domeniu. LocalAiProbe valideaza schema reply si nu
foloseste documente sau metadate private implicit. Probele scurte au un buget
conservator de input; documentele lungi necesita segmentare explicita in etapa AI.
Erorile de provider sunt sanitizate; prompturile/corpurile HTTP nu apar in exceptii.

## Testare

- php artisan vault:ai:check: conexiune si prezenta modelului, fara inferenta.
- php artisan vault:ai:check --infer: intrebare fictiva si JSON validat; fara date private.
- http://vaulty.test/ro/test-ai: pagina de proba numai local, Owner/Admin cu email confirmat.
  Variante en/hu/bg localizate. POST trimite job criptat pe ai; rezultatul este
  criptat in cache, legat de utilizator/workspace si expira in 10 minute.
- Toate aceste rute web raspund 404 in non-local, inclusiv cu route cache si POST
  fara CSRF. Guard global inaintea sesiunii/CSRF plus middleware local pe rute.
- Testele automate folosesc Http::fake si nu cer model instalat sau apeluri platite.

## Verificare hardware

Dupa restart, NVIDIA GTX 1650 este detectata corect, 4096 MiB VRAM.
Proba reala a primit JSON validat; ollama ps a raportat aproximativ 35% CPU / 65% GPU
cu context 8192. Nu se presupune incarcare integrala pe GPU. Prima incarcare rece
a depasit timeoutul clientului; dupa incarcarea runnerului, proba a reusit.
Modelele se descarca de pe internet, dar inferenta locala nu necesita trimiterea
prompturilor catre un provider extern. Analiza documentelor este locala si optionala, cu propuneri validate si confirmate explicit.
Optiunea de upload este bifata implicit la cererea utilizatorului si poate fi debifata.
Clientul pastreaza modelul incarcat 10 minute dupa analiza, pentru a reduce reincarcarile.
