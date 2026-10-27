# Vaulty local — Nginx si PHP-FPM

Adresa aleasa: http://vaulty.test. APP_URL este configurat in .env si .env.example; credentialele nu au fost afisate. Rularea locala foloseste HTTP pe loopback; HTTPS local necesita certificat separat, neconfigurat in aceasta etapa.

## Configuratii pregatite

- deploy/nginx/vaulty.local.conf: listen 127.0.0.1:80, server_name vaulty.test, root numai public/, front controller index.php, PHP prin socket dedicat, fisiere ascunse refuzate, fara executia altor fisiere PHP, headers de securitate si noindex local.
- deploy/php-fpm/vaulty.local.conf: pool local vaulty, user/group tiberiu, socket php8.3-fpm-vaulty.sock accesibil Nginx www-data; .env ramane 0600 si directoarele runtime raman inscriptibile utilizatorului local, fara chmod 777. Este configuratie pentru acest calculator, nu pentru productie.
- Upload local PHP: 5M/fisier, post 6M; Nginx body 6m pentru multipart. Limitele comerciale vor fi validate server-side in PlanLimitsService; aceste limite de transport se revizuiesc pentru planurile viitoare.

Configuratia PHP-FPM a trecut verificarea sintactica. Configuratia Nginx a fost verificata cu binarul oficial Ubuntu 1.24.0-2ubuntu7.19 extras temporar, cu loguri/temp in /tmp si port neprivilegiat 18080. Testul nu activeaza site-ul si nu verifica integrarea HTTP/FPM reala. Artefactele de test din /tmp au fost eliminate.

## Activare pe host

Nginx nu era instalat, hosts nu continea vaulty.test si sudo solicita parola. Agentul a pregatit fisierele; activarea necesita urmatoarele comenzi in terminalul utilizatorului. Ruleaza-le pe rand si opreste-te la prima eroare:

```bash
cd /var/www/document-vault-app
sudo apt-get install nginx
sudo install -m 0644 deploy/nginx/vaulty.local.conf /etc/nginx/sites-available/vaulty.test
sudo install -m 0644 deploy/php-fpm/vaulty.local.conf /etc/php/8.3/fpm/pool.d/vaulty.conf
sudo ln -s /etc/nginx/sites-available/vaulty.test /etc/nginx/sites-enabled/vaulty.test
printf '\n127.0.0.1 vaulty.test\n' | sudo tee -a /etc/hosts >/dev/null
sudo php-fpm8.3 -t
sudo nginx -t
sudo systemctl reload php8.3-fpm
sudo systemctl restart nginx
```

Linia hosts se adauga o singura data; daca exista deja, verifica intrarea inainte de repetare. Nu sterge alte intrari hosts sau alte site-uri. La reluare, daca symlinkul exista deja, verifica destinatia in loc de a-l suprascrie. Configuratiile cu aceste nume nu existau la inspectia initiala.

## Verificare dupa activare

```bash
getent hosts vaulty.test
curl -I http://vaulty.test/up
curl -I http://vaulty.test/.env
```

Asteptat: domeniu pe 127.0.0.1, /up HTTP 200 si noindex, /.env HTTP 403. / redirectioneaza acum la limba implicita (initial /ro); pagina publica si autentificarea /ro/autentificare sunt implementate. Nu publica domeniul sau porturile locale catre internet. Verificarea HTTP reala se va raporta numai dupa activarea serviciilor.

Surse pentru configuratie: [Laravel Nginx/public root](https://laravel.com/docs/13.x/deployment), [PHP-FPM pools](https://www.php.net/manual/en/install.fpm.configuration.php).


## Reluare dupa blocarea APT

La activare, un upgrade de sistem deja in curs a tinut dpkg lock; Nginx nu s-a instalat. Nu opri procesul de upgrade si nu sterge fisierele de lock. Poolul PHP-FPM a fost instalat, socketul dedicat exista, iar hosts contine deja 127.0.0.1 vaulty.test. Nu repeta adaugarea hosts.

Comanda de reluare asteapta pana la 600 secunde dupa lock si executa pasii urmatori numai daca pasul precedent reuseste:

```bash
cd /var/www/document-vault-app &&
sudo apt-get -o DPkg::Lock::Timeout=600 install nginx &&
sudo install -m 0644 deploy/nginx/vaulty.local.conf /etc/nginx/sites-available/vaulty.test &&
sudo ln -s /etc/nginx/sites-available/vaulty.test /etc/nginx/sites-enabled/vaulty.test &&
sudo nginx -t &&
sudo systemctl restart nginx
```

Daca timeoutul expira, asteapta terminarea upgradeului din managerul de actualizari si reia. Daca symlinkul exista deja dupa o rulare ulterioara, inspecteaza destinatia inainte de a relua; nu suprascrie configuratii necunoscute. Verificarea HTTP se face dupa restartul reusit.
