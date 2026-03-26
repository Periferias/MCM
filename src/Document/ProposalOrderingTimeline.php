<?php

declare(strict_types=1);

namespace App\Document;

use DateTime;
use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Doctrine\ODM\MongoDB\Types\Type;

#[ODM\Document(collection: 'proposal_ordering_timeline')]
class ProposalOrderingTimeline extends AbstractDocument
{
    #[ODM\Id]
    private ?string $id = null;

    #[ODM\Field]
    private string $proposalId;

    #[ODM\Field]
    private string $userId;

    #[ODM\Field]
    private string $userName;

    #[ODM\Field]
    private DateTime $timestamp;

    #[ODM\Field(type: Type::HASH)]
    private array $previousOrdering;

    #[ODM\Field(type: Type::HASH)]
    private array $newOrdering;

    #[ODM\Field(type: Type::HASH)]
    private array $changes;

    #[ODM\Field]
    private ?string $device = null;

    #[ODM\Field]
    private ?string $platform = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getProposalId(): string
    {
        return $this->proposalId;
    }

    public function setProposalId(string $proposalId): void
    {
        $this->proposalId = $proposalId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): void
    {
        $this->userId = $userId;
    }

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function setUserName(string $userName): void
    {
        $this->userName = $userName;
    }

    public function getTimestamp(): DateTime
    {
        return $this->timestamp;
    }

    public function setTimestamp(DateTime $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    public function getPreviousOrdering(): array
    {
        return $this->previousOrdering;
    }

    public function setPreviousOrdering(array $previousOrdering): void
    {
        $this->previousOrdering = $previousOrdering;
    }

    public function getNewOrdering(): array
    {
        return $this->newOrdering;
    }

    public function setNewOrdering(array $newOrdering): void
    {
        $this->newOrdering = $newOrdering;
    }

    public function getChanges(): array
    {
        return $this->changes;
    }

    public function setChanges(array $changes): void
    {
        $this->changes = $changes;
    }

    public function getDevice(): ?string
    {
        return $this->device;
    }

    public function setDevice(?string $device): void
    {
        $this->device = $device;
    }

    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    public function setPlatform(?string $platform): void
    {
        $this->platform = $platform;
    }
}
