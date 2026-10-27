<?php

return [
    'required' => 'A(z) :attribute mező kitöltése kötelező.',
    'email' => 'A(z) :attribute mezőben érvényes e-mail-címet adj meg.',
    'boolean' => 'A(z) :attribute értéke igaz vagy hamis lehet.',
    'string' => 'A(z) :attribute mezőnek szövegnek kell lennie.',
    'in' => 'A(z) :attribute értéke érvénytelen.',
    'max' => [
        'string' => 'A(z) :attribute legfeljebb :max karakter lehet.',
    ],
    'attributes' => [
        'q' => 'keresés',
        'category' => 'kategória',
    ],
    'confirmed' => 'A(z) :attribute megerősítése nem egyezik.',
    'unique' => 'A(z) :attribute értéke már használatban van.',
    'min' => [
        'string' => 'A(z) :attribute legalább :min karaktert tartalmazzon.',
        'numeric' => 'A(z) :attribute legalább :min legyen.',
    ],
    'integer' => 'A(z) :attribute egész szám legyen.',
    'regex' => 'A(z) :attribute formátuma érvénytelen.',
];
