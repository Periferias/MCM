<?php

declare(strict_types=1);

namespace App\Regmel\Service;

use App\Entity\Initiative;
use App\Enum\StatusProposalEnum;
use App\Event\Regmel\ProposalOrderingEvent;
use App\Validator\Constraints\UniqueProposalOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ProposalOrderingService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly EventDispatcherInterface $eventDispatcher,
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

            // Obter ordem: evaluation_ranking para CLASSIFICADA e SELECIONADA
            $order = null;
            if ($proposalStatus === StatusProposalEnum::CLASSIFICADA->value || $proposalStatus === StatusProposalEnum::SELECIONADA->value) {
                $order = $extra['evaluation_ranking'] ?? null;
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

        // Validar sequência com Constraint: deve ser contígua (1, 2, 3, ...) sem duplicidade
        $ordersData = array_map(fn ($p) => ['newOrder' => $p['newOrder']], $proposals);
        $violations = $this->validator->validate($ordersData, new UniqueProposalOrder());

        if (count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = $violation->getMessage();
            }
            throw new \InvalidArgumentException('Validação de sequência falhou: ' . implode(', ', $messages));
        }

        // Atualizar todas as propostas em transação
        try {
            // Capturar estado anterior para auditoria
            $previousOrdering = [];
            $newOrdering = [];

            foreach ($proposals as $item) {
                $proposal = $item['proposal'];
                $extra = $proposal->getExtraFields() ?? [];
                $status = $item['status'];

                // Estado anterior
                $previousOrder = null;
                if ($status === StatusProposalEnum::CLASSIFICADA->value || $status === StatusProposalEnum::SELECIONADA->value) {
                    $previousOrder = $extra['evaluation_ranking'] ?? null;
                }

                $previousOrdering[] = [
                    'proposalId' => $proposal->getId()->toRfc4122(),
                    'order' => $previousOrder,
                    'name' => $proposal->getName(),
                ];
            }

            // Atualizar propostas
            foreach ($proposals as $item) {
                $proposal = $item['proposal'];
                $newOrder = $item['newOrder'];
                $status = $item['status'];

                $extra = $proposal->getExtraFields() ?? [];

                // Atualizar o campo de ordem correto baseado no status
                if ($status === StatusProposalEnum::CLASSIFICADA->value || $status === StatusProposalEnum::SELECIONADA->value) {
                    $extra['evaluation_ranking'] = $newOrder;
                }

                $proposal->setExtraFields($extra);
                $this->entityManager->persist($proposal);

                $newOrdering[] = [
                    'proposalId' => $proposal->getId()->toRfc4122(),
                    'order' => $newOrder,
                    'name' => $proposal->getName(),
                ];
            }

            $this->entityManager->flush();

            // Disparar evento de auditoria para cada proposta (usar a primeira como ID base)
            if (!empty($proposals)) {
                $firstProposal = $proposals[0]['proposal'];
                $event = new ProposalOrderingEvent(
                    $firstProposal->getId()->toRfc4122(),
                    $previousOrdering,
                    $newOrdering,
                );
                $this->eventDispatcher->dispatch($event);
            }
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
