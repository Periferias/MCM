<?php

declare(strict_types=1);

namespace App\Repository\Interface;

use App\Entity\Agent;

interface AgentRepositoryInterface
{
    public function save(Agent $agent): Agent;

    public function getMainAgentByEmail(string $email): ?Agent;

    public function findByCpf(string $cpf, ?string $excludeId = null): ?Agent;
}
