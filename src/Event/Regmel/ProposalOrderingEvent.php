<?php

declare(strict_types=1);

namespace App\Event\Regmel;

use Symfony\Contracts\EventDispatcher\Event;

class ProposalOrderingEvent extends Event
{
    public const string TITLE = 'Proposal ordering changed';

    public function __construct(
        public readonly string $proposalId,
        public readonly array $previousOrdering,
        public readonly array $newOrdering,
    ) {
    }
}
