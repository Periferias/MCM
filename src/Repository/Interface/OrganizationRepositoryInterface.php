<?php

declare(strict_types=1);

namespace App\Repository\Interface;

use App\Entity\Organization;
use Symfony\Component\Uid\Uuid;

interface OrganizationRepositoryInterface
{
    public function save(Organization $organization): Organization;

    public function findMunicipalitiesByAgents(iterable $agents): array;

    public function findOrganizationByRegionAndState(string $region, string $state): array;

    public function findOrganizationByCompanyFilters(string $tipo): array;

    public function findByCnpj(string $cnpj, ?string $excludeId = null): ?Organization;

    public function hardDelete(Uuid $id): void;
}
