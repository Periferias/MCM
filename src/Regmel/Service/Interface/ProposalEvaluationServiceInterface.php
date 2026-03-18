<?php

declare(strict_types=1);

namespace App\Regmel\Service\Interface;

use App\Regmel\Enum\EvaluationResultEnum;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

interface ProposalEvaluationServiceInterface
{
    /**
     * Verifica se uma proposta pode ser avaliada.
     */
    public function canEvaluate(Uuid $proposalId): bool;

    /**
     * Registra a avaliação de uma proposta (uma única avaliação por proposta).
     */
    public function evaluate(
        Uuid $proposalId,
        EvaluationResultEnum $result,
        string $reason,
        ?string $notes = null,
        ?int $ranking = null,
        ?UploadedFile $document = null,
    ): void;

    /**
     * Obtém propostas aguardando avaliação, filtrado por região e estado.
     */
    public function getProposalsAwaitingEvaluation(
        ?string $region = null,
        ?string $state = null,
    ): array;

    /**
     * Obtém o resultado da avaliação de uma proposta.
     */
    public function getEvaluation(Uuid $proposalId): ?array;
}
