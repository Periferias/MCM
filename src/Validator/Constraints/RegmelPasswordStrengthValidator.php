<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class RegmelPasswordStrengthValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof RegmelPasswordStrength) {
            throw new UnexpectedTypeException($constraint, RegmelPasswordStrength::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedTypeException($value, 'string');
        }

        // Padrão do frontend: 8+ caracteres, minúscula, maiúscula, dígito, caractere especial
        $passwordPattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/';

        if (!preg_match($passwordPattern, $value)) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}
