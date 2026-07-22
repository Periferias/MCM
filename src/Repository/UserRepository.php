<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Repository\Interface\UserRepositoryInterface;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends AbstractRepository implements UserRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $user): User
    {
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();

        return $user;
    }

    public function beginTransaction(): void
    {
        $this->getEntityManager()->beginTransaction();
    }

    public function commit(): void
    {
        $this->getEntityManager()->commit();
    }

    public function rollback(): void
    {
        $this->getEntityManager()->rollback();
    }

    public function findOneByRole(string $role): ?User
    {
        $connection = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT id FROM app_user
            WHERE roles @> :role
            AND deleted_at IS NULL
            LIMIT 1
            SQL;

        $result = $connection->executeQuery($sql, [
            'role' => json_encode([$role]),
        ]);

        $id = $result->fetchOne();

        return $id ? $this->find($id) : null;
    }

    public function findAllForExport(): array
    {
        $sql = <<<SQL
            SELECT
                u.id AS user_id,
                CONCAT(u.firstname, ' ', u.lastname) AS user_name,
                u.social_name,
                u.email,
                u.status,
                u.roles,
                u.created_at AS user_created_at,
                u.updated_at AS user_updated_at,
                a.id AS agent_id,
                a.name AS agent_name,
                a.extra_fields AS agent_extra_fields,
                active_organization.organization_id,
                active_organization.organization_name,
                active_organization.organization_type,
                active_organization.organization_extra_fields,
                active_organization.owner_id
            FROM app_user u
            LEFT JOIN agent a
                ON a.user_id = u.id
                AND a.deleted_at IS NULL
            LEFT JOIN (
                SELECT
                    organizations_agents.agent_id,
                    organization.id AS organization_id,
                    organization.name AS organization_name,
                    organization.type AS organization_type,
                    organization.extra_fields AS organization_extra_fields,
                    organization.owner_id
                FROM organizations_agents
                INNER JOIN organization
                    ON organization.id = organizations_agents.organization_id
                    AND organization.deleted_at IS NULL
            ) active_organization
                ON active_organization.agent_id = a.id
            WHERE u.deleted_at IS NULL
            ORDER BY
                user_name,
                user_id,
                agent_name,
                agent_id,
                organization_name,
                organization_id
            SQL;

        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql)
            ->fetchAllAssociative();
    }
}
