<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Validator\UniqueProposalOrderValidator;
use Symfony\Component\Validator\Constraint;

/**
 * Valida a integridade da sequência de ordenação de propostas.
 *
 * Garante:
 * - Não há duplicidade de ordem
 * - Não há quebras na sequência (1, 2, 3...)
 * - Todas as ordens são >= 1
 */
final class UniqueProposalOrder extends Constraint
{
    public string $messageDuplicate = 'A ordem {{ order }} está duplicada.';
    public string $messageSequenceGap = 'Há uma quebra na sequência de ordens. Esperado: {{ expected }}, Encontrado: {{ found }}.';
    public string $messageInvalidOrder = 'A ordem {{ order }} é inválida. Deve ser >= 1.';
    public string $messageEmpty = 'A lista de propostas não pode estar vazia.';

    public function validatedBy(): string
    {
        return UniqueProposalOrderValidator::class;
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
