<?php

declare(strict_types=1);

namespace App\Validator;

use App\Validator\Constraints\UniqueProposalOrder;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Valida a integridade da sequência de ordenação de propostas.
 *
 * Verifica:
 * 1. Não há duplicidade de ordem
 * 2. Não há quebras na sequência (1, 2, 3...)
 * 3. Todas as ordens são >= 1
 * 4. Lista não está vazia (se aplicável)
 */
final class UniqueProposalOrderValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueProposalOrder) {
            throw new UnexpectedTypeException($constraint, UniqueProposalOrder::class);
        }

        if (null === $value || !is_array($value)) {
            return;
        }

        // Se a lista está vazia, é válido (pode ser caso de lista sem propostas)
        if (count($value) === 0) {
            return;
        }

        // Extrair ordens do array
        $orders = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $order = $item['order'] ?? $item['newOrder'] ?? null;
            } else {
                $order = null;
            }

            if (null !== $order) {
                $orders[] = (int) $order;
            }
        }

        // Se não há ordens para validar, é válido
        if (count($orders) === 0) {
            return;
        }

        // Validar ordens individuais (deve ser >= 1)
        foreach ($orders as $order) {
            if ($order < 1) {
                $this->context->buildViolation($constraint->messageInvalidOrder)
                    ->setParameter('{{ order }}', (string) $order)
                    ->addViolation();
                return;
            }
        }

        // Validar duplicidade
        $uniqueOrders = array_unique($orders);
        if (count($uniqueOrders) !== count($orders)) {
            // Encontrar qual ordem está duplicada
            $duplicates = array_diff_assoc($orders, array_unique($orders));
            $duplicatedOrder = reset($duplicates);
            
            $this->context->buildViolation($constraint->messageDuplicate)
                ->setParameter('{{ order }}', (string) $duplicatedOrder)
                ->addViolation();
            return;
        }

        // Validar sequência (1, 2, 3...)
        sort($orders);
        $minOrder = 1;
        foreach ($orders as $order) {
            if ($order !== $minOrder) {
                $this->context->buildViolation($constraint->messageSequenceGap)
                    ->setParameter('{{ expected }}', (string) $minOrder)
                    ->setParameter('{{ found }}', (string) $order)
                    ->addViolation();
                return;
            }
            ++$minOrder;
        }
    }
}
