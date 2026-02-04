<?php

declare(strict_types=1);

namespace App\Regmel\Message;

final readonly class GenerateMapFilesZipMessage
{
    public function __construct(
        public string $userId,
        public ?string $municipalityId = null,
    ) {}
}
