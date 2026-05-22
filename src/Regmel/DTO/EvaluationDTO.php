<?php

declare(strict_types=1);

namespace App\Regmel\DTO;

use App\Regmel\Enum\EvaluationResultEnum;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

class EvaluationDTO
{
    public function __construct(
        public Uuid $proposalId,
        public EvaluationResultEnum $result,
        public string $reason,
        public ?string $notes = null,
        public ?string $ranking = null,
        public ?UploadedFile $document = null,
    ) {
    }
}
