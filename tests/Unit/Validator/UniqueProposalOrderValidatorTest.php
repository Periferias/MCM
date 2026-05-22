<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Validator\Constraints\UniqueProposalOrder;
use App\Validator\UniqueProposalOrderValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

final class UniqueProposalOrderValidatorTest extends TestCase
{
    private UniqueProposalOrderValidator $validator;
    private ExecutionContextInterface $context;
    private ConstraintViolationBuilderInterface $builder;

    protected function setUp(): void
    {
        $this->validator = new UniqueProposalOrderValidator();
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $this->validator->initialize($this->context);
    }

    public function testValidateEmptyListIsValid(): void
    {
        $constraint = new UniqueProposalOrder();
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate([], $constraint);
    }

    public function testValidateSingleOrderIsValid(): void
    {
        $constraint = new UniqueProposalOrder();
        $data = [['order' => 1]];

        $this->context->expects($this->never())->method('buildViolation');
        $this->validator->validate($data, $constraint);
    }

    public function testValidateMultipleSequencialOrderIsValid(): void
    {
        $constraint = new UniqueProposalOrder();
        $data = [
            ['order' => 1],
            ['order' => 2],
            ['order' => 3],
        ];

        $this->context->expects($this->never())->method('buildViolation');
        $this->validator->validate($data, $constraint);
    }

    public function testValidateDuplicateOrderFailsValidation(): void
    {
        $constraint = new UniqueProposalOrder();
        $data = [
            ['newOrder' => 1],
            ['newOrder' => 2],
            ['newOrder' => 2], // Duplicado
        ];

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($constraint->messageDuplicate)
            ->willReturn($this->builder);

        $this->builder->expects($this->once())
            ->method('setParameter')
            ->with('{{ order }}', '2')
            ->willReturn($this->builder);

        $this->builder->expects($this->once())
            ->method('addViolation');

        $this->validator->validate($data, $constraint);
    }

    public function testValidateSequenceGapFailsValidation(): void
    {
        $constraint = new UniqueProposalOrder();
        $data = [
            ['order' => 1],
            ['order' => 3], // Falta 2
        ];

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($constraint->messageSequenceGap)
            ->willReturn($this->builder);

        $this->builder->expects($this->atLeast(2))
            ->method('setParameter')
            ->willReturn($this->builder);

        $this->builder->expects($this->once())
            ->method('addViolation');

        $this->validator->validate($data, $constraint);
    }

    public function testValidateNegativeOrderFailsValidation(): void
    {
        $constraint = new UniqueProposalOrder();
        $data = [
            ['order' => -1],
        ];

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($constraint->messageInvalidOrder)
            ->willReturn($this->builder);

        $this->builder->expects($this->once())
            ->method('setParameter')
            ->with('{{ order }}', '-1')
            ->willReturn($this->builder);

        $this->builder->expects($this->once())
            ->method('addViolation');

        $this->validator->validate($data, $constraint);
    }

    public function testValidateZeroOrderFailsValidation(): void
    {
        $constraint = new UniqueProposalOrder();
        $data = [
            ['order' => 0],
        ];

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($constraint->messageInvalidOrder)
            ->willReturn($this->builder);

        $this->builder->expects($this->once())
            ->method('setParameter')
            ->with('{{ order }}', '0')
            ->willReturn($this->builder);

        $this->builder->expects($this->once())
            ->method('addViolation');

        $this->validator->validate($data, $constraint);
    }

    public function testValidateNullValueIsValid(): void
    {
        $constraint = new UniqueProposalOrder();
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate(null, $constraint);
    }

    public function testValidateOutOfOrderIsValid(): void
    {
        // Valores fora de ordem mas sequência contígua é válido
        // pois o validator não exige que estejam em ordem
        $constraint = new UniqueProposalOrder();
        $data = [
            ['order' => 3],
            ['order' => 1],
            ['order' => 2],
        ];

        $this->context->expects($this->never())->method('buildViolation');
        $this->validator->validate($data, $constraint);
    }

    public function testValidateMultipleDuplicatesDetectsFirst(): void
    {
        $constraint = new UniqueProposalOrder();
        $data = [
            ['order' => 1],
            ['order' => 2],
            ['order' => 2], // Primeiro duplicado
            ['order' => 3],
            ['order' => 3], // Segundo duplicado
        ];

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->willReturn($this->builder);

        $this->builder->expects($this->once())
            ->method('setParameter')
            ->with('{{ order }}', '2')
            ->willReturn($this->builder);

        $this->builder->expects($this->once())
            ->method('addViolation');

        $this->validator->validate($data, $constraint);
    }

    public function testValidateLargeSequenceIsValid(): void
    {
        $constraint = new UniqueProposalOrder();
        $data = [];
        for ($i = 1; $i <= 100; ++$i) {
            $data[] = ['order' => $i];
        }

        $this->context->expects($this->never())->method('buildViolation');
        $this->validator->validate($data, $constraint);
    }

    public function testValidateWithNewOrderKeyWorks(): void
    {
        $constraint = new UniqueProposalOrder();
        $data = [
            ['newOrder' => 1],
            ['newOrder' => 2],
            ['newOrder' => 3],
        ];

        $this->context->expects($this->never())->method('buildViolation');
        $this->validator->validate($data, $constraint);
    }
}
