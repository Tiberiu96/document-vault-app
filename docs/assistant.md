# Asistentul Vaulty

Asistentul privat foloseste `laravel/ai` 1.3, Blade si JavaScript fara Vite/Node.
Este oprit implicit si apare numai utilizatorilor autentificati, cu email confirmat
si workspace activ. Conversatia ramane exclusiv in memoria paginii; nu se salveaza.

## Configurare

```dotenv
AI_ENABLED=true
ASSISTANT_ENABLED=true
ASSISTANT_PROVIDER=ollama
ASSISTANT_MODEL=
ASSISTANT_CACHE_STORE=redis
```

Providerii acceptati sunt `ollama` si `gemini`. Ollama foloseste
`OLLAMA_BASE_URL` si `ASSISTANT_OLLAMA_MODEL`; Gemini foloseste cheia privata
`GEMINI_API_KEY` si `ASSISTANT_GEMINI_MODEL`. Schimbarea providerului nu cere
modificari de cod. Pentru Gemini se aplica scopul, costurile, cotele si conditiile
din `docs/gemini.md`; cheia ramane numai in headerul `x-goog-api-key`. Nu activa
procesarea remote inainte de confirmarea contului, cotelor si termenilor.

Endpointul POST localizat foloseste sesiunea web, CSRF, email confirmat si
`EnsureActiveWorkspace`. Streamingul SSE emite numai `tool_call`, `tool_result`
fara rezultat intern, `text`, `error` si `done`. Istoricul trimis providerului are
maximum zece mesaje, raspunsul maximum 500 tokenuri. Prefixul fix al uneltelor
poate fi cache-uit de provider; instructiunile includ data si nu sunt marcate
pentru cache explicit.

Uneltele nu accepta `user_id` sau `workspace_id`. Ele citesc identitatea din
sesiunea autentificata, revalideaza membershipul workspace-ului si aplica Policy
documentului. Sunt expuse numai `ListMyDocuments`, `GetDocument` si
`CreateReminder`; nu exista unealta de stergere sau editare distructiva.
Rezultatele OCR sunt tratate ca input nefiabil, iar documentele peste limita
explicita esueaza fara taiere tacita.

Limitele sunt configurate in `config/assistant.php`: limita zilnica per utilizator
si plan, un raspuns activ per utilizator, maximum cinci raspunsuri globale si
timeout. Lockurile folosesc store-ul Redis configurat. Consumul de tokenuri se
scrie in `assistant_usage`, fara prompturi, continut sau nume de fisiere.

## Operare

Streamingul tine un worker PHP-FPM ocupat pana la raspuns sau timeout. In productie
configureaza un pool PHP-FPM separat si o locatie Nginx dedicata rutelor localizate
ale asistentului, cu buffering dezactivat si timeout corelat cu
`ASSISTANT_TIMEOUT_SECONDS`/`config/assistant.php`. Pool-ul general nu trebuie
epuizat de conversatii lente. Validarea exacta Nginx/pool ramane specifica gazdei.

Testele folosesc fake-urile `laravel/ai` si nu fac apeluri platite. Dupa schimbarea
configurarii: `php artisan config:clear`; in productie reincarca in siguranta FPM.
