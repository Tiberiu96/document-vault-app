# CI/CD pentru beta

Status: CI este activ prin `.github/workflows/ci.yml`, la push pe main, pull request
si pornire manuala. CSS-ul se construieste inainte de testele care randeaza pagini.
Mediul CI foloseste SQLite in memorie si provideri externi dezactivati/mockuiti.
CD nu este inca activ; productia nu se modifica la push.
Nu exista acces SSH de deploy sau credentiale de productie configurate prin acest document.

Flux de verificare si livrare:

1. Push pe main / pull request porneste GitHub Actions pentru validare Composer,
   stil PHP, teste cu servicii externe mockuite, build CSS si verificare route cache.
   Ruleaza pe mediu izolat, cu SQLite sau baza MySQL dedicata testelor, niciodata pe DB reala.
2. Un esec blocheaza livrarea. Nu sunt necesare chei AI sau secrete de productie in CI.
3. Deploy manual prin Actions -> Run workflow, pentru SHA-ul exact care a trecut CI.
   Verifica main/provenienta commitului si rezultatul CI, nu executa git pull latest
   dupa testarea altui commit. Arhiva CSS trebuie sa provina din acelasi commit.
4. CD foloseste un user de deploy separat, cheie privata in GitHub Secrets, host key
   SSH verificat si permisiuni minime; niciun login root sau parola/secrete in chat.
   Serializeaza deploy-urile, pastreaza .env si storage privat in afara codului livrat,
   gestioneaza migrarile/workeri/cache si verifica HTTPS/health dupa publicare.
5. Pastreaza release-ul anterior pentru rollback de cod. Migrarile bazei nu se anuleaza
   automat; necesita compatibilitate si backup/restaurare verificate.

Pentru beta: CI automat, CD manual. Deploy-ul complet automat dupa push se poate
activa ulterior, cand monitorizarea, backupul si strategia de rollback sunt validate.
Butonul manual este oferit de workflow_dispatch; nu este configurat doar prin existenta
unui repo GitHub. Configurarea practica urmeaza dupa primul deploy si accesul SSH sigur.

Documentatie: https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow
