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

        // Proposta deve estar com status ANUIDA
        if ($status !== StatusProposalEnum::ANUIDA->value) {
            return false;
        }

        // Proposta ainda não foi avaliada
        $evaluationStatus = $extraFields['evaluation_status'] ?? null;

        return $evaluationStatus !== 'completed';
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

        if ($result === EvaluationResultEnum::CLASSIFICADA && !$ranking) {
            throw new \InvalidArgumentException('O ranking é obrigatório para propostas classificadas.');
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

        if ($result === EvaluationResultEnum::CLASSIFICADA && $ranking) {
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

        $proposal->setExtraFields($extraFields);
        $this->entityManager->flush();
    }

    public function getProposalsAwaitingEvaluation(
        ?string $region = null,
        ?string $state = null,
    ): array {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('i')
            ->from(Initiative::class, 'i')
            ->where('i.deletedAt IS NULL');

        // Filtrar por status ANUIDA
        $qb->andWhere('JSON_EXTRACT(i.extraFields, \'$."status"\') = :status')
            ->setParameter('status', StatusProposalEnum::ANUIDA->value);

        // Filtrar por propostas não avaliadas
        $qb->andWhere('JSON_EXTRACT(i.extraFields, \'$."evaluation_status"\') IS NULL OR JSON_EXTRACT(i.extraFields, \'$."evaluation_status"\') != :evaluationStatus')
            ->setParameter('evaluationStatus', 'completed');

        if ($region) {
            $qb->andWhere('JSON_EXTRACT(i.extraFields, \'$."region"\') = :region')
                ->setParameter('region', $region);
        }

        if ($state) {
            $qb->andWhere('JSON_EXTRACT(i.extraFields, \'$."state"\') = :state')
                ->setParameter('state', $state);
        }

        $results = $qb->getQuery()->getResult();

        return array_map(fn (Initiative $initiative) => [
            'id' => $initiative->getId()->toRfc4122(),
            'name' => $initiative->getName(),
            'status' => $initiative->getExtraFields()['status'] ?? '',
            'region' => $initiative->getExtraFields()['region'] ?? '',
            'state' => $initiative->getExtraFields()['state'] ?? '',
        ], $results);
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
