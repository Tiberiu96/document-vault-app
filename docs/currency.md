# Moneda — plan

Preturile abonamentelor sunt intregi in unitatea minima a monedei, de exemplu 2990 = 29.90 RON. Fara FLOAT. ro preselecteaza RON, en EUR, hu HUF, bg EUR. Checkout foloseste preturi explicite si versionate RON/EUR/HUF, fara conversie sau rate inventate; suma si moneda din sumar trebuie sa coincida cu Stripe.

Frankfurter prin ExchangeRateProviderInterface, FrankfurterExchangeRateProvider, ExchangeRateService si UpdateExchangeRatesJob zilnic. Providerul va fi verificat la integrare, inclusiv endpoint, monede si eventuale credentiale. Rate istorice DECIMAL cu data publicarii/sursa in MySQL; Redis pentru ultima rata valida. Weekend/sarbatori: ultima publicata; esec: retry limitat si data ratei vizibila. Daca nu exista rata valida, afiseaza RON si indisponibilitatea conversiei; nu inventa rata.

Factura pastreaza suma si moneda originale, independent de moneda UI. Corectiile OCR/AI sunt explicite. Teste pentru rotunjire, lipsa ratei, rate vechi, API indisponibil si pastrarea sumei originale.

Regula bg=EUR reflecta adoptarea euro in Bulgaria de la 1 ianuarie 2026 ([BCE](https://www.ecb.europa.eu/press/pr/date/2026/html/ecb.pr260101~c830245e42.en.html)). Documentele istorice trebuie sa pastreze in continuare moneda originala, inclusiv BGN daca este cazul.
