<?php

declare(strict_types=1);

namespace App\Regmel\Repository\Interface;

interface ProposalRepositoryInterface
{
    // Adicionamos a string $userName aqui para bater com o Repository
    public function bulkUpdateStatus(array $proposals, string $statusTo, string $userName): void;
}
