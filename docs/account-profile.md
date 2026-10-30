# Contul meu si avatarul

Pagina privata/noindex este disponibila din navigatia workspace-ului si meniul
contului: `/ro/contul-meu`, `/en/my-account`, `/hu/fiokom`, `/bg/moyat-profil`.
Este o setare a identitatii, comuna tuturor workspace-urilor; functioneaza si
pentru un cont fara workspace. Nu modifica datele firmelor sau planurile.

## Date si parola

Numele complet este editabil (2-120 caractere). Emailul este numai pentru citire;
serverul respinge orice email trimis spre modificare. ID-ul contului vine exclusiv
din sesiunea web autentificata, niciodata din formular. Roluri, workspace-uri si
atribute administrative nu sunt actualizabile prin formular.

Schimbarea parolei este separata si cere parola actuala, confirmarea celei noi,
minimum 16 caractere, litere mari/mici, cifre si simboluri, maximum 72 bytes.
Parola actuala se reverifica sub lock. Se roteste remember_token, se revoca
tokenurile Sanctum, se invalideaza sesiunile DB si sesiunea curenta. Middleware-ul
auth.session respinge celelalte sesiuni web cu hashul parolei vechi. Notificarea
AccountMail changed foloseste high, afterCommit, payload criptat si limba cererii.
Parolele sunt excluse din old input. Consultarea administrativa ramane read-only.

## Avatar privat

Un singur avatar activ per user, maximum 5 MiB (5120 KiB), JPEG/PNG/WebP,
maximum 4096 x 4096 pixeli. Se verifica fisierul real, MIME, extensia si dimensiunile;
SVG/GIF si fisierele care nu sunt imagini sunt refuzate. GD decodeaza si reencodeaza
imaginea ca JPEG 512 x 512, cu orientarea EXIF JPEG aplicata cand extensia EXIF este disponibila,
decupare centrala si eliminarea metadatelor.
Afisarea este circulara; in lipsa avatarului se foloseste initiala numelui.

Fisierul se pastreaza pe discul local privat in avatars/, separat de documentele
workspace-ului. Nu consuma cota OCR/AI/documente. Calea este ascunsa la serializarea
User. Endpointul autentificat livreaza numai avatarul propriu, fara parametru ID,
cu no-store/private si nosniff; nu exista URL public de storage.

Actualizarea profilului este sub lock pe user. Inlocuirea/eliminarea sterge
avatarul vechi dupa commit; daca discul esueaza, DeleteReplacedAvatar reincearca
pe low, cu payload criptat, timeout 20s, trei incercari si backoff 10/60/180s.
Jobul valideaza calea, verifica sub lock ca nu este avatarul curent si este
idempotent. Dupa epuizarea incercarilor necesita reconcilierea jobului esuat.

Limite separate configurabile in config/vault.php: profile_updates_per_minute
(10), profile_password_per_minute (5), profile_avatar_per_minute (120), pe user.
CSRF si auth.session raman active. Nu se folosesc servicii externe noi.

## Livrare si verificare

Migrarea 2026_10_30_000018_add_user_avatar adauga users.avatar_path nullable.
Rulare pe fiecare mediu: php artisan migrate; apoi composer run css:build si
php artisan route:cache. PHP GD este necesar (deja prezent in runtime-ul local
si in instructiunile de deploy).

ProfileTest acopera autentificare, localizare/noindex, email blocat, izolare intre
conturi, imagini invalide/prea mari, reencodare, inlocuire/eliminare, retry sigur si
schimbarea parolei cu notificare. Testele folosesc storage/notification fake,
fara servicii externe. Nu au fost rulate local, conform preferintei utilizatorului.
Verificarea vizuala in browser ramane indisponibila deoarece browserul local este
dezactivat in setarile Work.

Resetarea ceruta a consumului local OCR/AI s-a efectuat separat, numai pentru
workspace-ul personal identificat, in luna UTC curenta, sub lock si fara procesari
sau rezervari active. Nu s-au modificat documentele, planul sau istoricul joburilor.
Aceasta operatiune punctuala nu introduce un bypass disponibil utilizatorilor.

Migrarea avatar a fost aplicata local; compilarea Blade si route:cache au reusit.
