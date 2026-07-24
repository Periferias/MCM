<?php

declare(strict_types=1);

namespace App\Repository\Interface;

use App\Entity\User;

/**
 * @phpstan-type UserExportRow array{
 *     user_id: string,
 *     user_name: string,
 *     social_name: ?string,
 *     email: string,
 *     status: string,
 *     roles: string,
 *     user_created_at: string,
 *     user_updated_at: ?string,
 *     agent_id: ?string,
 *     agent_name: ?string,
 *     agent_extra_fields: ?string,
 *     organization_id: ?string,
 *     organization_name: ?string,
 *     organization_type: ?string,
 *     organization_extra_fields: ?string,
 *     owner_id: ?string
 * }
 */
interface UserRepositoryInterface
{
    public function save(User $user): User;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollback(): void;

    public function findOneByRole(string $role): ?User;

    /**
     * @return iterable<UserExportRow>
     */
    public function findAllForExport(): iterable;
}
