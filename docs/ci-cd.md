# CI/CD pentru beta

CI este activ: `.github/workflows/ci.yml` verifica Composer, stil PHP, CSS, teste
izolate si boot/cache fara dependinte de dezvoltare. Nu necesita secrete reale.

CD este pregatit, dar ramane oprit pana la configurarea serverului si a variabilei
repository `AUTO_DEPLOY_ENABLED=true`. Jobul deploy cere succesul CI si push pe main.
Pull request-urile si pornirea manuala a CI nu fac deploy.

## Ce livreaza

- SHA-ul exact testat si CSS-ul din artifactul aceleiasi rulari (retentie 7 zile).
- SSH cu host key verificat, cheie dedicata cu comanda fortata, fara shell/forwarding.
- Git fetch si fast-forward numai la SHA-ul testat, daca este inca varful main.
  Commiturile depasite sunt omise. Refuza un checkout murdar sau alta ramura.
- Backup Restic verificat inainte de schimbari; un esec blocheaza deploy-ul.
- Down, opreste FPM/Horizon/scheduler, Composer fara dev, CSS preconstruit,
  migrate --force, config/route/view cache si verificare DB/Redis.
- Porneste FPM, up, verifica HTTPS /up, apoi porneste si verifica workerii.
- Reia numai timerele de backup care erau active. Nu modifica .env sau documentele.

CI poate anula teste vechi. CD foloseste alta grupa de concurenta, cu
cancel-in-progress=false: un push nou nu intrerupe deploy-ul deja pornit.
Lockurile locale exclud deploy/restaurare simultana; backupurile active blocheaza
pornirea, iar timerele active sunt suspendate pe durata operatiei.

## Instalarea helperului pe beta

Helperul `deploy/bootstrap/deploy-vaulty.sh` corespunde checkout-ului existent:
/var/www/document-vault-app, operator szabotiberiuandrei2020, runtime vaulty,
health https://vaulty-beta.duckdns.org/up. La migrare adapteaza aceste valori
inainte de instalare. Nu schimba arhitectura in release-uri/symlink-uri.

Dupa pull, instaleaza root-owned 0700 in /usr/local/libexec/vaulty-deploy.
Actualizarea din Git nu reinstaleaza helperul privilegiat automat; necesita
revizuire si sudo. Git/Composer ruleaza ca operator cu umask 022; PHP ca vaulty.
Se pastreaza ACL-urile existente pentru .env si storage/cache.
Cache-urile vechi se curata ca vaulty inainte de schimbarea codului. Composer
foloseste --no-scripts; package:discover este executat apoi separat ca vaulty.
Astfel, deploy-urile repetate nu cer operatorului sa citeasca cache-uri private
create de runtime. Nu largi permisiunile cache-ului pentru a ocoli aceasta regula.

Cheia SSH dedicata trebuie adaugata la authorized_keys pe o singura linie:

```text
restrict,command="sudo -n /usr/local/libexec/vaulty-deploy" ssh-ed25519 CHEIA_PUBLICA vaulty-github-actions
```

Regula sudoers root-owned 0440, validata inainte cu visudo -cf:

```text
szabotiberiuandrei2020 ALL=(root) NOPASSWD: /usr/local/libexec/vaulty-deploy ""
```

Ghilimelele goale restrictioneaza helperul la zero argumente. Comanda fortata
ignora SSH_ORIGINAL_COMMAND; cheia nu poate primi shell sau invoca alt sudo.
Operatorul trebuie sa poata face git fetch noninteractiv. Cheia de intrare pentru
GitHub Actions este distincta de credentialele de citire a repository-ului.

## Configurarea GitHub

Creeaza environment `production`. In Settings -> Secrets and variables -> Actions:

| Secret | Valoare |
| --- | --- |
| DEPLOY_HOST | IP public stabil sau hostname SSH, fara schema/port; portul este 22 |
| DEPLOY_USER | szabotiberiuandrei2020 |
| DEPLOY_SSH_KEY | Cheia privata dedicata, copiata direct in GitHub, niciodata in chat |
| DEPLOY_KNOWN_HOSTS | HOST ssh-ed25519 CHEIA_PUBLICA_A_SERVERULUI |

Ia host key direct din consola serverului: /etc/ssh/ssh_host_ed25519_key.pub.
Nu utiliza acceptarea automata a cheilor. Firewall-ul trebuie sa permita SSH din
runnerul GitHub; rezerva preferabil IP static. Actualizeaza host/known_hosts daca
IP-ul se schimba. Nu pune credentiale SMTP/AI/DB in workflow.

Pastreaza AUTO_DEPLOY_ENABLED absent/false pana la instalare si verificare SSH.
Dupa true, urmatorul push main livreaza automat dupa CI verde. False opreste
pornirile viitoare, fara sa anuleze un deploy deja pornit.

## Prima verificare a livrarii automate

Foloseste un commit numai de documentatie pe main pentru prima rulare completa.
In Actions, verifica succesul joburilor CI si Deploy productie, inclusiv mesajul
`Deploy reusit: SHA`. Un job verde cu `deploy omis` nu confirma livrarea.
Pe VM, `git rev-parse HEAD` trebuie sa corespunda commitului testat; verifica apoi
HTTPS /up si starea FPM, Horizon si scheduler. Nu executa git pull manual in timpul
acestui test: actualizarea trebuie facuta de CD.
Repeta verificarea dupa reinstalarea helperului: deploy-ul trebuie sa reuseasca
si cand config/route/view cache exista deja, create de utilizatorul vaulty.

## Esecuri

Inainte de down, refuzul nu modifica aplicatia. Dupa down, un esec pastreaza
mentenanta, FPM si workerii opriti, cu timerele suspendate. Nu exista rollback
automat al DB sau restart orb. Nu anula manual un deploy in curs; la pierderea
conexiunii verifica starea pe server inainte sa repornesti servicii.

/var/lib/vaulty-deploy pastreaza SHA-ul anterior si CSS-ul (root-only), nu o copie
completa a vendor/DB. Backupul Restic contine DB/documente; restaurarea este un
flux separat. Nu reporni cod vechi peste o schema incompatibila. Retentia Restic
ramane ultimele doua copii verificate, inclusiv cele facute inainte de deploy.
Verificarea /up si DB/Redis nu inlocuieste testarea uploadului/emailului dupa
primul deploy real. Prima livrare pe VM a fost confirmata pentru 39d86a0;
corectia separarii Composer/Artisan necesita verificarea unui nou deploy complet.

Referinte: [concurrency](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency),
[artifacts](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/download-workflow-artifacts).
