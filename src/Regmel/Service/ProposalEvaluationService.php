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
            StatusProposalEnum::SELECIONADA->value,
            StatusProposalEnum::CLASSIFICADA->value,
            StatusProposalEnum::NAO_SELECIONADA->value,
        ];

        return in_array($status, $allowedStatuses);
    }

    public function evaluate(
        Uuid $proposalId,
        EvaluationResultEnum $result,
        string $reason,
        ?string $notes = null,
        ?int $ranking = null,
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

        if (($result === EvaluationResultEnum::CLASSIFICADA || $result === EvaluationResultEnum::SELECIONADA) && !$ranking) {
            throw new \InvalidArgumentException('O ranking é obrigatório para propostas selecionadas e classificadas.');
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

        if (($result === EvaluationResultEnum::CLASSIFICADA || $result === EvaluationResultEnum::SELECIONADA) && $ranking) {
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
            EvaluationResultEnum::NAO_SELECIONADA => StatusProposalEnum::NAO_SELECIONADA->value,
            EvaluationResultEnum::CLASSIFICADA => StatusProposalEnum::CLASSIFICADA->value,
        };

        $extraFields['status'] = $newStatus;
        $extraFields['status_reason'] = $reason;

        $normalizedRanking = $this->applyRankingForStatusChange($proposal, $newStatus, $ranking);
        if (null !== $normalizedRanking) {
            $extraFields['evaluation_ranking'] = $normalizedRanking;
        } else {
            unset($extraFields['evaluation_ranking']);
        }

        $proposal->setExtraFields($extraFields);
        $this->entityManager->flush();
    }

    private function applyRankingForStatusChange(Initiative $proposal, string $newStatus, ?int $ranking): ?int
    {
        $currentExtraFields = $proposal->getExtraFields() ?? [];
        $currentStatus = $currentExtraFields['status'] ?? null;
        $currentProposalId = $proposal->getId()->toRfc4122();

        $rankedStatuses = [
            StatusProposalEnum::SELECIONADA->value,
            StatusProposalEnum::CLASSIFICADA->value,
        ];

        if (in_array($currentStatus, $rankedStatuses, true)) {
            $this->normalizeStatusRankings($currentStatus, $currentProposalId);
        }

        if (!in_array($newStatus, $rankedStatuses, true)) {
            return null;
        }

        $rankedProposals = $this->getRankedProposalsByStatus($newStatus, $currentProposalId);
        $targetRanking = max(1, min($ranking ?? 1, count($rankedProposals) + 1));

        $position = 1;
        foreach ($rankedProposals as $rankedProposal) {
            if ($position === $targetRanking) {
                ++$position;
            }

            $rankedExtraFields = $rankedProposal->getExtraFields() ?? [];
            $rankedExtraFields['evaluation_ranking'] = $position;
            $rankedProposal->setExtraFields($rankedExtraFields);
            $this->entityManager->persist($rankedProposal);
            ++$position;
        }

        return $targetRanking;
    }

    /**
     * @return Initiative[]
     */
    private function getRankedProposalsByStatus(string $status, ?string $excludeProposalId = null): array
    {
        $proposals = $this->entityManager->getRepository(Initiative::class)->findAll();

        $filtered = array_filter($proposals, function (Initiative $candidate) use ($status, $excludeProposalId) {
            if (method_exists($candidate, 'isDeleted') && $candidate->isDeleted()) {
                return false;
            }

            if ($excludeProposalId && $candidate->getId()->toRfc4122() === $excludeProposalId) {
                return false;
            }

            $extra = $candidate->getExtraFields() ?? [];

            return ($extra['status'] ?? null) === $status;
        });

        usort($filtered, function (Initiative $a, Initiative $b) {
            $orderA = (int) (($a->getExtraFields()['evaluation_ranking'] ?? PHP_INT_MAX));
            $orderB = (int) (($b->getExtraFields()['evaluation_ranking'] ?? PHP_INT_MAX));

            if ($orderA === $orderB) {
                return strcmp($a->getId()->toRfc4122(), $b->getId()->toRfc4122());
            }

            return $orderA <=> $orderB;
        });

        return array_values($filtered);
    }

    private function normalizeStatusRankings(string $status, ?string $excludeProposalId = null): void
    {
        $rankedProposals = $this->getRankedProposalsByStatus($status, $excludeProposalId);

        foreach ($rankedProposals as $index => $rankedProposal) {
            $extraFields = $rankedProposal->getExtraFields() ?? [];
            $extraFields['evaluation_ranking'] = $index + 1;
            $rankedProposal->setExtraFields($extraFields);
            $this->entityManager->persist($rankedProposal);
        }
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
