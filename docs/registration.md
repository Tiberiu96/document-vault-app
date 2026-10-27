# Inregistrare PF/PJ si selectarea spatiului

## Functionalitati livrate

Rute localizate: /ro/inregistrare, /en/register, /hu/regisztracio, /bg/registratsiya. Buton vizibil in antet, CTA pe homepage si legatura din login. Formulare Blade responsive, selector PF/PJ fara dependente JS noi, campuri PJ afisate conditionat, erori localizate si noindex. Inregistrarea nu apare in sitemap sau llms.txt.

RegisterRequest normalizeaza emailul si prefixul RO al CUI, valideaza inputurile si exclude datele de firma pentru PF. Parola: minimum 12 caractere cu litere mari/mici, cifre si simboluri, confirmare, maximum 72 bytes pentru bcrypt. Hash::make este aplicat explicit, inclusiv pentru o parola care seamana cu un hash. Parolele nu sunt pastrate in old input. Numele si adresa nu sunt transliterate; continutul utilizatorilor isi pastreaza diacriticele.

PJ: denumire, CUI numeric de 2–10 cifre (prefix RO acceptat la introducere), numar de inregistrare cu format flexibil pentru identificatori vechi/noi, judet/sector, oras si strada. Cod postal optional de 6 cifre. Tara este RO in aceasta versiune. Aceasta este validare locala de format, nu verificare de checksum, existenta, reprezentare sau status fiscal in ANAF/ONRC. Nu exista apeluri la registre externe.

RegisterUser creeaza intr-o singura tranzactie user + personal workspace + owner membership. PJ adauga business workspace, company si owner membership in aceeasi tranzactie. Orice esec anuleaza toate aceste inregistrari. Emailul are index unic pentru a preveni duplicate la retry/concurenta; testele nu pretind o proba HTTP simultana. Rolurile, owner_id, is_super_admin si email_verified_at din input sunt ignorate.

Dupa commit se emite Registered, se autentifica userul si se regenereaza sesiunea. Spatiul initial este personal pentru PF si business pentru PJ. User implementeaza MustVerifyEmail; Registered trimite acum notificarea de confirmare in Redis/high. email_verified_at ramane null pana la confirmarea linkului semnat. Mailpit local si workerul Horizon sunt configurate si verificate; vezi account-email.md.

Limitare beta configurabila: 3 cereri POST/ora per IP, comuna limbilor, in config/vault.php. Include input invalid pentru protectie la abuz; honeypot suplimentar, fara CAPTCHA extern. Raspuns 429 cu Retry-After si formular localizat.

## Selectarea spatiului

POST /ro/spatii/selecteaza, /en/workspaces/switch, /hu/munkateruletek/valtas, /bg/prostranstva/izbor. Necesita auth, CSRF, membership din relatia utilizatorului si WorkspacePolicy. Persoanele straine nu pot accesa nici macar un workspace personal cu membership inconsistent. Conturile globale nu primesc bypass. Spatiul activ se salveaza server-side in sesiune; panoul il reconfirma la fiecare afisare si elimina selectia daca accesul a fost revocat.

## Teste

75 teste / 1038 asertiuni trecute in suita completa, inclusiv inregistrare PF/PJ, parole, roluri injectate, inputuri array, email duplicat, rollback la esec, retry unic, rate limiting intre limbi, alegere de workspace si revocare de acces. Testele folosesc SQLite/mocks. O proba separata a rulat RegisterUser PJ pe MySQL local intr-o tranzactie exterioara, apoi a anulat toate datele de proba. Nu a fost creat un cont implicit sau administrator.

Build: composer run css:build. Verificarea vizuala in browser ramane blocata de politica salvata a domeniului si nu este raportata ca efectuata. Nu au fost facute incercari prin mecanisme alternative pentru a ocoli politica.

## Urmatorul pas

Verificarea emailului si resetarea parolei sunt livrate prin Mailpit local. Providerul real pentru beta necesita configurare ghidata separata. MFA si interfata admin raman in asteptare; documentele reale si limitele lor urmeaza in etapa 4.

Stocarea documentelor va folosi discul local privat in storage/app/private, cu chei de tip workspaces/{id}/documents/{uuid}/original.ext, originalele pastrate si metadata in DB. Fara storage:link pentru documente. Descarcarile vor trece prin auth, selectarea/izolarea workspaceului si Policy. public/storage ar fi doar pentru continut public explicit. Continutul storage si backupurile sunt excluse din Git; doar structura .gitignore este versionata.

Bulgara si ajustarea tipografiei/antetului fac parte din acest checkpoint. Limba UI nu schimba regulile de validare pentru firma romaneasca si nu activeaza automat OCR pentru bulgara.
