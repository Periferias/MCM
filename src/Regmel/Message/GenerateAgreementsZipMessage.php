<?php

declare(strict_types=1);

namespace App\Regmel\Message;

final readonly class GenerateAgreementsZipMessage
{
    public function __construct(
        public string $userId,
    ) {}
}
