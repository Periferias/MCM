<?php

declare(strict_types=1);

namespace App\Tests\Unit\Regmel\Service;

use App\Entity\Initiative;
use App\Enum\StatusProposalEnum;
use App\Regmel\Enum\EvaluationResultEnum;
use App\Regmel\Service\ProposalEvaluationService;
use App\Service\Interface\FileServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ProposalEvaluationServiceTest extends TestCase
{
    private ProposalEvaluationService $service;
    private EntityManagerInterface $entityManager;
    private FileServiceInterface $fileService;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->fileService = $this->createMock(FileServiceInterface::class);
        $this->service = new ProposalEvaluationService($this->entityManager, $this->fileService);
    }

    public function testCanEvaluateReturnsTrueWhenProposalIsAnuida(): void
    {
        $proposalId = Uuid::v4();
        $proposal = new Initiative();
        $proposal->setExtraFields([
            'status' => StatusProposalEnum::ANUIDA->value,
            'evaluation_status' => null,
        ]);

        $this->entityManager->expects($this->once())
            ->method('find')
            ->with(Initiative::class, $proposalId)
            ->willReturn($proposal);

        $result = $this->service->canEvaluate($proposalId);

        $this->assertTrue($result);
    }

    public function testCanEvaluateReturnsFalseWhenProposalIsNotAnuida(): void
    {
        $proposalId = Uuid::v4();
        $proposal = new Initiative();
        $proposal->setExtraFields([
            'status' => StatusProposalEnum::RECEBIDA->value,
        ]);

        $this->entityManager->expects($this->once())
            ->method('find')
            ->with(Initiative::class, $proposalId)
            ->willReturn($proposal);

        $result = $this->service->canEvaluate($proposalId);

        $this->assertFalse($result);
    }

    public function testCanEvaluateReturnsTrueWhenProposalAlreadyEvaluated(): void
    {
        $proposalId = Uuid::v4();
        $proposal = new Initiative();
        $proposal->setExtraFields([
            'status' => StatusProposalEnum::ANUIDA->value,
            'evaluation_status' => 'completed',
        ]);

        $this->entityManager->expects($this->once())
            ->method('find')
            ->with(Initiative::class, $proposalId)
            ->willReturn($proposal);

        $result = $this->service->canEvaluate($proposalId);

        $this->assertTrue($result);
    }

    public function testEvaluateThrowsExceptionWhenReasonIsEmpty(): void
    {
        $proposalId = Uuid::v4();
        $proposal = new Initiative();
        $proposal->setExtraFields([
            'status' => StatusProposalEnum::ANUIDA->value,
            'evaluation_status' => null,
        ]);

        $this->entityManager->expects($this->once())
            ->method('find')
            ->with(Initiative::class, $proposalId)
            ->willReturn($proposal);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('O motivo da avaliação é obrigatório.');

        $this->service->evaluate($proposalId, EvaluationResultEnum::SELECIONADA, '');
    }

    public function testEvaluateThrowsExceptionWhenRankingHasInvalidCharacters(): void
    {
        $proposalId = Uuid::v4();
        $proposal = new Initiative();
        $proposal->setExtraFields([
            'status' => StatusProposalEnum::ANUIDA->value,
            'evaluation_status' => null,
        ]);

        $this->entityManager->expects($this->once())
            ->method('find')
            ->with(Initiative::class, $proposalId)
            ->willReturn($proposal);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('O código de posição deve conter apenas letras e números.');

        $this->service->evaluate($proposalId, EvaluationResultEnum::CLASSIFICADA, 'Test reason', ranking: 'SP-001');
    }

    public function testGetEvaluationReturnsNullWhenNotEvaluated(): void
    {
        $proposalId = Uuid::v4();
        $proposal = new Initiative();
        $proposal->setExtraFields([
            'status' => StatusProposalEnum::ANUIDA->value,
        ]);

        $this->entityManager->expects($this->once())
            ->method('find')
            ->with(Initiative::class, $proposalId)
            ->willReturn($proposal);

        $result = $this->service->getEvaluation($proposalId);

        $this->assertNull($result);
    }
}
