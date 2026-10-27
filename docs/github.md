# GitHub din terminal

Repository configurat: https://github.com/Tiberiu96/document-vault-app.git. Branchul local main exista deja. Commiturile locale sunt create; primul push a fost refuzat deoarece Git HTTPS din terminal nu era autentificat. Conexiunea pluginului GitHub nu configureaza automat credentialele Git locale. Nu crea alt repository si nu folosi force push.

Instalare CLI si autentificare in terminal/browserul utilizatorului:

```bash
sudo apt-get -o DPkg::Lock::Timeout=120 install gh
gh auth login --hostname github.com --git-protocol https --web
gh auth setup-git --hostname github.com
cd /var/www/document-vault-app
git push -u origin main
```

Ruleaza pe rand si opreste-te la prima eroare. Numai instalarea foloseste sudo. Confirmarile/codul de autentificare se folosesc direct in terminal/browser, fara a fi trimise in conversatie. GitHub CLI foloseste credential store-ul sistemului cand este disponibil; nu utiliza --insecure-storage si nu pune tokenul in URL, .env sau fisiere versionate. Dupa login + setup-git, agentul poate folosi git push din aceeasi identitate locala.

Se versioneaza composer.json/composer.lock, cod, documentatie, referinte de design si licentele asseturilor. Nu se versioneaza vendor, .env, credentiale, DB-uri locale, loguri, cache sau documente private. storage pastreaza numai fisierele .gitignore. Verificarea staged cauta si valorile credentialelor locale fara a le afisa.

Surse: [gh auth login](https://cli.github.com/manual/gh_auth_login), [gh auth setup-git](https://cli.github.com/manual/gh_auth_setup-git).
