<?php

declare(strict_types=1);

namespace App\Regmel\Service;

use App\Entity\Initiative;
use App\Enum\StatusProposalEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class ProposalOrderingService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Obter propostas ordenadas por status e ordem de prioridade.
     *
     * @return array Lista de propostas formatadas com ordem
     */
    public function getProposalsOrdered(
        ?string $status = null,
        ?string $region = null,
        ?string $state = null,
    ): array {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('i')
            ->from(Initiative::class, 'i')
            ->where('i.deletedAt IS NULL');

        // Buscar todas as propostas e filtrar em PHP (usar JSON_EXTRACT seria mais eficiente)
        $results = $qb->getQuery()->getResult();

        // Filtrar em PHP baseado em extra_fields
        $filtered = array_filter($results, function (Initiative $initiative) use ($status, $region, $state) {
            $extra = $initiative->getExtraFields() ?? [];
            $proposalStatus = $extra['status'] ?? null;

            // Se status especificado, verificar
            if ($status) {
                $allowedStatuses = [
                    StatusProposalEnum::SELECIONADA->value,
                    StatusProposalEnum::CLASSIFICADA->value,
                ];

                if (!in_array($proposalStatus, $allowedStatuses)) {
                    return false;
                }

                if ($proposalStatus !== $status) {
                    return false;
                }
            } else {
                // Se nenhum status especificado, permitir apenas SELECIONADA e CLASSIFICADA
                $allowedStatuses = [
                    StatusProposalEnum::SELECIONADA->value,
                    StatusProposalEnum::CLASSIFICADA->value,
                ];

                if (!in_array($proposalStatus, $allowedStatuses)) {
                    return false;
                }
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

        // Formatar e ordenar por ordem de prioridade
        $formatted = array_map(function (Initiative $initiative) {
            $extra = $initiative->getExtraFields() ?? [];
            $proposalStatus = $extra['status'] ?? null;

            // Obter ordem: evaluation_ranking para CLASSIFICADA, ordem_prioridade para SELECIONADA
            $order = null;
            if ($proposalStatus === StatusProposalEnum::CLASSIFICADA->value) {
                $order = $extra['evaluation_ranking'] ?? null;
            } else {
                $order = $extra['ordem_prioridade'] ?? null;
            }

            return [
                'id' => $initiative->getId()->toRfc4122(),
                'name' => $initiative->getName(),
                'company' => $initiative->getOrganizationFrom()?->getName() ?? 'N/A',
                'municipality' => $extra['city_name'] ?? 'N/A',
                'region' => $extra['region'] ?? 'N/A',
                'state' => $extra['state'] ?? 'N/A',
                'status' => $proposalStatus,
                'order' => $order,
                'quantity_houses' => (int) ($extra['quantity_houses'] ?? 0),
                'evaluation_ranking' => $extra['evaluation_ranking'] ?? null,
                'evaluation_reason' => $extra['evaluation_reason'] ?? null,
            ];
        }, $filtered);

        // Ordenar por ordem
        usort($formatted, function (array $a, array $b) {
            $orderA = $a['order'] ?? PHP_INT_MAX;
            $orderB = $b['order'] ?? PHP_INT_MAX;

            return $orderA <=> $orderB;
        });

        return array_values($formatted);
    }

    /**
     * Reordenar propostas.
     *
     * @param array<array{proposalId: string, newOrder: int}> $reordering
     *
     * @throws \InvalidArgumentException
     */
    public function reorderProposals(array $reordering): void
    {
        if (empty($reordering)) {
            throw new \InvalidArgumentException('Nenhuma proposta para reordenar.');
        }

        // Validar que todas as IDs existem e estão em status válido
        $proposals = [];
        foreach ($reordering as $item) {
            $proposalId = Uuid::fromString($item['proposalId']);
            $newOrder = $item['newOrder'];

            if (!is_int($newOrder) || $newOrder < 1) {
                throw new \InvalidArgumentException('Ordem deve ser um inteiro >= 1.');
            }

            /** @var Initiative $proposal */
            $proposal = $this->entityManager->find(Initiative::class, $proposalId);

            if (!$proposal) {
                throw new \InvalidArgumentException(sprintf('Proposta %s não encontrada.', $proposalId->toRfc4122()));
            }

            $extra = $proposal->getExtraFields() ?? [];
            $status = $extra['status'] ?? null;

            // Validar que está em status SELECIONADA ou CLASSIFICADA
            $allowedStatuses = [
                StatusProposalEnum::SELECIONADA->value,
                StatusProposalEnum::CLASSIFICADA->value,
            ];

            if (!in_array($status, $allowedStatuses)) {
                throw new \InvalidArgumentException(
                    sprintf('Proposta %s não está em status SELECIONADA ou CLASSIFICADA.', $proposalId->toRfc4122())
                );
            }

            $proposals[] = [
                'proposal' => $proposal,
                'newOrder' => $newOrder,
                'status' => $status,
            ];
        }

        // Validar sequência: deve ser contígua (1, 2, 3, ...)
        $orders = array_map(fn ($p) => $p['newOrder'], $proposals);
        sort($orders);

        $expectedSequence = range(1, count($orders));
        if ($orders !== $expectedSequence) {
            throw new \InvalidArgumentException('Sequência de ordem deve ser contígua: 1, 2, 3, ...');
        }

        // Validar unicidade de ordem
        if (count($orders) !== count(array_unique($orders))) {
            throw new \InvalidArgumentException('Não pode haver duplicidade de ordem.');
        }

        // Atualizar todas as propostas em transação
        try {
            foreach ($proposals as $item) {
                $proposal = $item['proposal'];
                $newOrder = $item['newOrder'];
                $status = $item['status'];

                $extra = $proposal->getExtraFields() ?? [];

                // Atualizar o campo de ordem correto baseado no status
                if ($status === StatusProposalEnum::CLASSIFICADA->value) {
                    $extra['evaluation_ranking'] = $newOrder;
                } else {
                    $extra['ordem_prioridade'] = $newOrder;
                }

                $proposal->setExtraFields($extra);
                $this->entityManager->persist($proposal);
            }

            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw new \InvalidArgumentException('Erro ao reordenar propostas: ' . $e->getMessage());
        }
    }

    /**
     * Validar integridade de sequência.
     *
     * @param array $proposals
     *
     * @return bool
     */
    private function validateSequenceIntegrity(array $proposals): bool
    {
        if (empty($proposals)) {
            return true;
        }

        $orders = array_map(fn ($p) => $p['order'] ?? 0, $proposals);
        sort($orders);

        $expectedSequence = range(1, count($orders));

        return $orders === $expectedSequence;
    }
}
