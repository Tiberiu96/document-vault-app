<?php

namespace App\Contracts\Ai;

interface AiProviderInterface
{
    public function health(): array;

    /** Transport JSON structurat; apelantul valideaza si regulile de domeniu. */
    public function structured(array $messages, array $schema): array;
}
