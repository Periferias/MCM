<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Agent;
use App\Entity\Initiative;
use App\Repository\Interface\InitiativeRepositoryInterface;
use Doctrine\Persistence\ManagerRegistry;

class InitiativeRepository extends AbstractRepository implements InitiativeRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Initiative::class);
    }

    public function save(Initiative $initiative): Initiative
    {
        $this->getEntityManager()->persist($initiative);
        $this->getEntityManager()->flush();

        return $initiative;
    }

    public function findByFilters(?string $region, ?string $state, ?string $cityName, ?string $status, ?string $anticipation): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $queryBuilder = $connection->createQueryBuilder()
            ->select('i.id')
            ->from('initiative', 'i')
            ->orderBy('i.created_at', 'DESC');

        if ($region) {
            $queryBuilder->andWhere("i.extra_fields->>'region' = :region")
                ->setParameter('region', $region);
        }

        if ($state) {
            $queryBuilder->andWhere("i.extra_fields->>'state' = :state")
                ->setParameter('state', $state);
        }

        if ($cityName) {
            $queryBuilder->andWhere("i.extra_fields->>'city_name' = :cityName")
                ->setParameter('cityName', $cityName);
        }

        if ($status) {
            $queryBuilder->andWhere("i.extra_fields->>'status' = :status")
                ->setParameter('status', $status);
        }

        if ($anticipation) {
            $queryBuilder->andWhere("i.extra_fields->>'anticipation' = :anticipation")
                ->setParameter('anticipation', $anticipation);
        }

        $queryBuilder->andWhere('i.deleted_at IS NULL');

        $ids = $queryBuilder->executeQuery()->fetchFirstColumn();
        if (empty($ids)) {
            return [];
        }

        $initiatives = $this->findBy(
            ['id' => $ids],
            ['createdAt' => 'DESC']
        );

        // Filtrar em memória as propostas deletadas (is_deleted = false)
        return array_filter($initiatives, function(Initiative $initiative) {
            return !$initiative->isDeleted();
        });
    }

    public function countProposals(?Agent $createdBy = null): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $queryBuilder = $connection->createQueryBuilder()
            ->select('i.id')
            ->from('initiative', 'i')
            ->where("(i.extra_fields->>'map_file' IS NOT NULL OR i.extra_fields->>'project_file' IS NOT NULL)")
            ->andWhere('i.deleted_at IS NULL')
            ->orderBy('i.created_at', 'DESC');

        if ($createdBy) {
            $queryBuilder->andWhere('i.created_by_id = :createdById')
                ->setParameter('createdById', $createdBy->getId());
        }

        $ids = $queryBuilder->executeQuery()->fetchFirstColumn();
        
        if (empty($ids)) {
            return 0;
        }
        
        // Filtrar em memória as propostas deletadas
        $proposals = $this->findBy(['id' => $ids]);
        $notDeletedProposals = array_filter($proposals, function(Initiative $initiative) {
            return !$initiative->isDeleted();
        });
        
        return count($notDeletedProposals);
    }

    public function findAllIncludingDeleted(int $limit = 50, array $params = [], string $order = 'DESC'): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $queryBuilder = $connection->createQueryBuilder()
            ->select('i.id')
            ->from('initiative', 'i')
            ->where("(i.extra_fields->>'map_file' IS NOT NULL OR i.extra_fields->>'project_file' IS NOT NULL)")
            ->andWhere('i.deleted_at IS NULL')
            ->orderBy('i.created_at', $order)
            ->setMaxResults($limit);

        // Aplicar filtro de status se fornecido
        if (isset($params['status'])) {
            $queryBuilder->andWhere("i.extra_fields->>'status' = :status")
                ->setParameter('status', $params['status']);
        }

        $ids = $queryBuilder->executeQuery()->fetchFirstColumn();
        if (empty($ids)) {
            return [];
        }

        return $this->findBy(
            ['id' => $ids],
            ['createdAt' => $order]
        );
    }
}