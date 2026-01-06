<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Agent;
use App\Repository\Interface\AgentRepositoryInterface;
use Doctrine\Persistence\ManagerRegistry;

class AgentRepository extends AbstractRepository implements AgentRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agent::class);
    }

    public function save(Agent $agent): Agent
    {
        $this->getEntityManager()->persist($agent);
        $this->getEntityManager()->flush();

        return $agent;
    }

    public function getMainAgentByEmail(string $email): ?Agent
    {
        $qb = $this->getEntityManager()->createQueryBuilder();

        return $qb->select('a')
            ->from(Agent::class, 'a')
            ->innerJoin('a.user', 'u')
            ->where('u.email = :email')
            ->andWhere('a.main = true')
            ->setParameter('email', $email)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCpf(string $cpf, ?string $excludeId = null): ?Agent
    {
        $connection = $this->getEntityManager()->getConnection();

        $sql = "SELECT * FROM agent WHERE extra_fields->>'cpf' = :cpf";

        if (null !== $excludeId) {
            $sql .= " AND id::text != :excludeId";
        }

        $sql .= " LIMIT 1";

        $statement = $connection->prepare($sql);
        $params = ['cpf' => $cpf];

        if (null !== $excludeId) {
            $params['excludeId'] = $excludeId;
        }

        $result = $statement->executeQuery($params);
        $data = $result->fetchAssociative();

        if (!$data) {
            return null;
        }

        return $this->find($data['id']);
    }
}
