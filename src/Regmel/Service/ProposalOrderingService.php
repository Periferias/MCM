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
                if (!StatusProposalEnum::isRanked((string) $proposalStatus)) {
                    return false;
                }

                if ($proposalStatus !== $status) {
                    return false;
                }
            } else {
                // Se nenhum status especificado, permitir apenas SELECIONADA e CLASSIFICADA
                if (!StatusProposalEnum::isRanked((string) $proposalStatus)) {
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
            if (StatusProposalEnum::isRanked((string) $proposalStatus)) {
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
            $orderA = $a['order'] ?? 'ZZZZZZ';
            $orderB = $b['order'] ?? 'ZZZZZZ';

            return strnatcasecmp((string) $orderA, (string) $orderB);
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

            // Validar que está em status selecionado ou classificado.
            if (!StatusProposalEnum::isRanked((string) $status)) {
                throw new \InvalidArgumentException(sprintf('Proposta %s não está em status selecionado ou classificado.', $proposalId->toRfc4122()));
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
            throw new \InvalidArgumentException('Validação de sequência falhou: '.implode(', ', $messages));
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
                if (StatusProposalEnum::isRanked((string) $status)) {
                    $previousOrder = $extra['evaluation_ranking'] ?? null;
                }

                $previousOrdering[] = [
                    'proposalId' => $proposal->getId()->toRfc4122(),
                    'order' => $previousOrder,
                    'name' => $proposal->getName(),
                ];
            }

            // Atualizar propostas recalculando a posição por UF na ordem recebida.
            $stateCounters = [];
            foreach ($proposals as $item) {
                $proposal = $item['proposal'];
                $newOrder = $item['newOrder'];
                $status = $item['status'];

                $extra = $proposal->getExtraFields() ?? [];

                if (StatusProposalEnum::isRanked((string) $status)) {
                    $state = strtoupper((string) ($extra['state'] ?? ''));
                    if (!preg_match('/^[A-Z]{2}$/', $state)) {
                        throw new \InvalidArgumentException(sprintf('Proposta %s não possui UF válida.', $proposal->getId()->toRfc4122()));
                    }

                    $stateCounters[$state] = ($stateCounters[$state] ?? 0) + 1;
                    $extra['evaluation_ranking'] = sprintf('%s%04d', $state, $stateCounters[$state]);
                }

                $proposal->setExtraFields($extra);
                $this->entityManager->persist($proposal);

                $newOrdering[] = [
                    'proposalId' => $proposal->getId()->toRfc4122(),
                    'order' => $extra['evaluation_ranking'] ?? $newOrder,
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
            throw new \InvalidArgumentException('Erro ao reordenar propostas: '.$e->getMessage());
        }
    }

    /**
     * Atualizar diretamente o código de posição de uma proposta ranqueada.
     */
    public function updateProposalRanking(Uuid $proposalId, string $ranking): void
    {
        /** @var Initiative|null $proposal */
        $proposal = $this->entityManager->find(Initiative::class, $proposalId);

        if (!$proposal) {
            throw new \InvalidArgumentException('Proposta não encontrada.');
        }

        $extra = $proposal->getExtraFields() ?? [];
        $status = (string) ($extra['status'] ?? '');

        if (!StatusProposalEnum::isRanked($status)) {
            throw new \InvalidArgumentException('Proposta não está em status selecionado ou classificado.');
        }

        $state = strtoupper((string) ($extra['state'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $state)) {
            throw new \InvalidArgumentException('UF da proposta é obrigatória para atualizar a posição.');
        }

        $ranking = strtoupper(trim($ranking));
        if (!preg_match('/^([A-Z]{2})(\d{1,4})$/', $ranking, $matches)) {
            throw new \InvalidArgumentException('A posição deve seguir o formato UF0001.');
        }

        if ($matches[1] !== $state) {
            throw new \InvalidArgumentException('A UF da posição deve ser igual à UF da proposta.');
        }

        $position = (int) $matches[2];
        if ($position < 1) {
            throw new \InvalidArgumentException('A parte numérica da posição deve ser maior que zero.');
        }

        $normalizedRanking = sprintf('%s%04d', $state, $position);
        $this->assertRankingIsAvailable($proposal, $state, $normalizedRanking);

        $previousOrder = (string) ($extra['evaluation_ranking'] ?? '');
        if ($previousOrder === $normalizedRanking) {
            return;
        }

        $extra['evaluation_ranking'] = $normalizedRanking;
        $proposal->setExtraFields($extra);
        $this->entityManager->persist($proposal);
        $this->entityManager->flush();

        $event = new ProposalOrderingEvent(
            $proposal->getId()->toRfc4122(),
            [[
                'proposalId' => $proposal->getId()->toRfc4122(),
                'order' => $previousOrder,
                'name' => $proposal->getName(),
            ]],
            [[
                'proposalId' => $proposal->getId()->toRfc4122(),
                'order' => $normalizedRanking,
                'name' => $proposal->getName(),
            ]],
        );
        $this->eventDispatcher->dispatch($event);
    }

    private function assertRankingIsAvailable(Initiative $proposal, string $state, string $ranking): void
    {
        $currentProposalId = $proposal->getId()?->toRfc4122();
        $proposals = $this->entityManager->getRepository(Initiative::class)->findAll();

        foreach ($proposals as $candidate) {
            if (!$candidate instanceof Initiative || null !== $candidate->getDeletedAt()) {
                continue;
            }

            if ($currentProposalId && $candidate->getId()?->toRfc4122() === $currentProposalId) {
                continue;
            }

            $candidateExtra = $candidate->getExtraFields() ?? [];
            $candidateState = strtoupper((string) ($candidateExtra['state'] ?? ''));
            $candidateRanking = strtoupper((string) ($candidateExtra['evaluation_ranking'] ?? ''));

            if ($candidateState !== $state || $candidateRanking !== $ranking) {
                continue;
            }

            throw new \InvalidArgumentException('Já existe uma proposta com essa posição para a mesma UF.');
        }
    }

    /**
     * Validar integridade de sequência.
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
