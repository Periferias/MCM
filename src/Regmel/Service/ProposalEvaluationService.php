<?php

declare(strict_types=1);

namespace App\Regmel\Service;

use App\Entity\Initiative;
use App\Enum\StatusProposalEnum;
use App\Regmel\Enum\EvaluationResultEnum;
use App\Regmel\Service\Interface\ProposalEvaluationServiceInterface;
use App\Service\Interface\FileServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

class ProposalEvaluationService implements ProposalEvaluationServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileServiceInterface $fileService,
    ) {
    }

    public function canEvaluate(Uuid $proposalId): bool
    {
        /** @var Initiative $proposal */
        $proposal = $this->entityManager->find(Initiative::class, $proposalId);

        if (!$proposal) {
            return false;
        }

        $extraFields = $proposal->getExtraFields() ?? [];
        $status = $extraFields['status'] ?? '';

        // Proposta deve estar com status ANUIDA OU já avaliada (permitir reavaliação)
        $allowedStatuses = [
            StatusProposalEnum::ANUIDA->value,
            ...StatusProposalEnum::rankedValues(),
            StatusProposalEnum::NAO_SELECIONADA->value,
            StatusProposalEnum::DESCLASSIFICADA->value,
        ];

        return in_array($status, $allowedStatuses);
    }

    public function evaluate(
        Uuid $proposalId,
        EvaluationResultEnum $result,
        string $reason,
        ?string $notes = null,
        ?string $ranking = null,
        ?UploadedFile $document = null,
    ): void {
        /** @var Initiative $proposal */
        $proposal = $this->entityManager->find(Initiative::class, $proposalId);

        if (!$proposal) {
            throw new \InvalidArgumentException('Proposta não encontrada.');
        }

        // Validar se pode ser avaliada
        if (!$this->canEvaluate($proposalId)) {
            throw new \InvalidArgumentException('Proposta não pode ser avaliada. Verifique o status e se já foi avaliada.');
        }

        // Validar campos obrigatórios
        if (empty($reason)) {
            throw new \InvalidArgumentException('O motivo da avaliação é obrigatório.');
        }

        $ranking = null !== $ranking ? trim($ranking) : null;

        if ($result->requiresRanking() && '' === (string) $ranking) {
            $ranking = $this->generateNextRankingCode($proposal, $result);
        }

        if ($result->requiresRanking() && !preg_match('/^[A-Za-z0-9]+$/', (string) $ranking)) {
            throw new \InvalidArgumentException('O código de posição deve conter apenas letras e números.');
        }

        if ($result->requiresRanking() && $this->rankingExistsForGroup($proposal, $result, (string) $ranking)) {
            $ranking = $this->generateNextRankingCode($proposal, $result);
        }

        $extraFields = $proposal->getExtraFields() ?? [];
        $user = null; // Será injetado pelo listener

        // Preparar dados de avaliação
        $evaluationData = [
            'evaluation_status' => 'completed',
            'evaluation_result' => $result->value,
            'evaluation_reason' => $reason,
            'evaluation_notes' => $notes,
            'evaluation_completed_at' => new \DateTime('now', new \DateTimeZone('America/Sao_Paulo')),
        ];

        if ($result->requiresRanking()) {
            $evaluationData['evaluation_ranking'] = $ranking;
        }

        if ($document) {
            $filename = $this->fileService->save($document, 'evaluations');
            $evaluationData['evaluation_document'] = $filename;
        }

        // Atualizar dados de avaliação
        $extraFields = array_merge($extraFields, $evaluationData);

        // Atualizar status da proposta
        $newStatus = match ($result) {
            EvaluationResultEnum::SELECIONADA => StatusProposalEnum::SELECIONADA->value,
            EvaluationResultEnum::SELECIONADA_DESEMPATE => StatusProposalEnum::SELECIONADA_DESEMPATE->value,
            EvaluationResultEnum::CLASSIFICADA_CADASTRO_RESERVA => StatusProposalEnum::CLASSIFICADA_CADASTRO_RESERVA->value,
            EvaluationResultEnum::CLASSIFICADA_NAO_SELECIONADA_EMPATE => StatusProposalEnum::CLASSIFICADA_NAO_SELECIONADA_EMPATE->value,
            EvaluationResultEnum::DESCLASSIFICADA => StatusProposalEnum::DESCLASSIFICADA->value,
        };

        $extraFields['status'] = $newStatus;
        $extraFields['status_reason'] = $reason;

        if (StatusProposalEnum::isRanked($newStatus)) {
            $extraFields['evaluation_ranking'] = $ranking;
        } else {
            unset($extraFields['evaluation_ranking']);
        }

        $proposal->setExtraFields($extraFields);
        $this->entityManager->flush();
    }

    private function generateNextRankingCode(Initiative $proposal, EvaluationResultEnum $result): string
    {
        $extraFields = $proposal->getExtraFields() ?? [];
        $state = strtoupper((string) ($extraFields['state'] ?? ''));

        if (!preg_match('/^[A-Z]{2}$/', $state)) {
            throw new \InvalidArgumentException('UF da proposta é obrigatória para gerar o código de posição.');
        }

        $statusGroup = $this->getResultStatusGroup($result);
        $currentProposalId = $proposal->getId()?->toRfc4122();
        $highestPosition = 0;

        $proposals = $this->entityManager->getRepository(Initiative::class)->findAll();

        foreach ($proposals as $candidate) {
            if (!$candidate instanceof Initiative) {
                continue;
            }

            if ($currentProposalId && $candidate->getId()?->toRfc4122() === $currentProposalId) {
                continue;
            }

            $candidateExtraFields = $candidate->getExtraFields() ?? [];
            $candidateStatus = (string) ($candidateExtraFields['status'] ?? '');
            $candidateState = strtoupper((string) ($candidateExtraFields['state'] ?? ''));

            if ($candidateState !== $state || !$this->statusBelongsToGroup($candidateStatus, $statusGroup)) {
                continue;
            }

            $ranking = (string) ($candidateExtraFields['evaluation_ranking'] ?? '');
            if (preg_match('/^'.preg_quote($state, '/').'(\d+)$/', $ranking, $matches)) {
                $highestPosition = max($highestPosition, (int) $matches[1]);
            }
        }

        return sprintf('%s%04d', $state, $highestPosition + 1);
    }

    private function rankingExistsForGroup(Initiative $proposal, EvaluationResultEnum $result, string $ranking): bool
    {
        $extraFields = $proposal->getExtraFields() ?? [];
        $state = strtoupper((string) ($extraFields['state'] ?? ''));
        $statusGroup = $this->getResultStatusGroup($result);
        $currentProposalId = $proposal->getId()?->toRfc4122();

        $proposals = $this->entityManager->getRepository(Initiative::class)->findAll();

        foreach ($proposals as $candidate) {
            if (!$candidate instanceof Initiative) {
                continue;
            }

            if ($currentProposalId && $candidate->getId()?->toRfc4122() === $currentProposalId) {
                continue;
            }

            $candidateExtraFields = $candidate->getExtraFields() ?? [];
            $candidateStatus = (string) ($candidateExtraFields['status'] ?? '');
            $candidateState = strtoupper((string) ($candidateExtraFields['state'] ?? ''));
            $candidateRanking = (string) ($candidateExtraFields['evaluation_ranking'] ?? '');

            if ($candidateState === $state && $candidateRanking === $ranking && $this->statusBelongsToGroup($candidateStatus, $statusGroup)) {
                return true;
            }
        }

        return false;
    }

    private function getResultStatusGroup(EvaluationResultEnum $result): string
    {
        if (str_starts_with($result->value, 'Selecionada')) {
            return 'selected';
        }

        if (str_starts_with($result->value, 'Classificada')) {
            return 'classified';
        }

        return 'none';
    }

    private function statusBelongsToGroup(string $status, string $statusGroup): bool
    {
        return match ($statusGroup) {
            'selected' => StatusProposalEnum::isSelected($status),
            'classified' => StatusProposalEnum::isClassified($status),
            default => false,
        };
    }

    public function getProposalsAwaitingEvaluation(
        ?string $region = null,
        ?string $state = null,
    ): array {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('i')
            ->from(Initiative::class, 'i')
            ->where('i.deletedAt IS NULL');

        // Usar SQL nativo para JSON_EXTRACT
        $dql = $qb->getDQL();
        $results = $this->entityManager->createQuery(
            'SELECT i FROM App\Entity\Initiative i 
             WHERE i.deletedAt IS NULL'
        )->getResult();

        // Filtrar em PHP
        $filtered = array_filter($results, function (Initiative $initiative) use ($region, $state) {
            $extra = $initiative->getExtraFields() ?? [];

            // Verificar status ANUIDA
            if (($extra['status'] ?? null) !== StatusProposalEnum::ANUIDA->value) {
                return false;
            }

            // Verificar se não foi avaliada
            if (($extra['evaluation_status'] ?? null) === 'completed') {
                return false;
            }

            // Filtrar por região
            if ($region && ($extra['region'] ?? null) !== $region) {
                return false;
            }

            // Filtrar por estado
            if ($state && ($extra['state'] ?? null) !== $state) {
                return false;
            }

            return true;
        });

        return array_map(fn (Initiative $initiative) => [
            'id' => $initiative->getId()->toRfc4122(),
            'name' => $initiative->getName(),
            'status' => $initiative->getExtraFields()['status'] ?? '',
            'region' => $initiative->getExtraFields()['region'] ?? '',
            'state' => $initiative->getExtraFields()['state'] ?? '',
        ], $filtered);
    }

    public function getEvaluation(Uuid $proposalId): ?array
    {
        /** @var Initiative $proposal */
        $proposal = $this->entityManager->find(Initiative::class, $proposalId);

        if (!$proposal) {
            return null;
        }

        $extraFields = $proposal->getExtraFields() ?? [];

        if (($extraFields['evaluation_status'] ?? null) !== 'completed') {
            return null;
        }

        return [
            'result' => $extraFields['evaluation_result'] ?? null,
            'reason' => $extraFields['evaluation_reason'] ?? null,
            'notes' => $extraFields['evaluation_notes'] ?? null,
            'ranking' => $extraFields['evaluation_ranking'] ?? null,
            'document' => $extraFields['evaluation_document'] ?? null,
            'completed_at' => $extraFields['evaluation_completed_at'] ?? null,
        ];
    }
}
