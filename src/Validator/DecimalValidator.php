<?php

declare(strict_types=1);

namespace Xsd2Php\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class DecimalValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Decimal) {
            throw new UnexpectedTypeException($constraint, Decimal::class);
        }

        // custom constraints should ignore null/empty and let NotNull/NotBlank handle presence
        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_int($value) && !\is_float($value) && !\is_string($value)) {
            throw new UnexpectedValueException($value, 'int|float|string');
        }

        $normalized = ltrim($this->toPlainDecimalString($value), '+-');
        [$intPart, $fracPart] = array_pad(explode('.', $normalized, 2), 2, '');

        if (null !== $constraint->fractionDigits && \strlen($fracPart) > $constraint->fractionDigits) {
            $this->context->buildViolation($constraint->fractionDigitsMessage)
                ->setParameter('{{ max }}', (string) $constraint->fractionDigits)
                ->addViolation();
        }

        if (null !== $constraint->totalDigits) {
            $significantIntDigits = ltrim($intPart, '0');
            // no significant integer digits (value is 0.xxx) - leading zeros in the fraction
            // part aren't significant either, e.g. '0.05' has 1 significant digit, not 2.
            $fracForCounting = '' === $significantIntDigits ? ltrim($fracPart, '0') : $fracPart;
            $significantFracDigits = rtrim($fracForCounting, '0');
            $digitCount = max(1, \strlen($significantIntDigits) + \strlen($significantFracDigits));

            if ($digitCount > $constraint->totalDigits) {
                $this->context->buildViolation($constraint->totalDigitsMessage)
                    ->setParameter('{{ max }}', (string) $constraint->totalDigits)
                    ->addViolation();
            }
        }
    }

    /**
     * PHP's (string) cast switches a float to scientific notation (e.g. "1.234E-5") outside
     * roughly 1e-4..1e15 (depends on the `precision` ini setting) - the '.'-split above would
     * then misparse the exponent as fraction digits. Reformat as a plain decimal in that case;
     * for every value that doesn't trigger scientific notation, this is identical to (string) $value.
     */
    private function toPlainDecimalString(int|float|string $value): string
    {
        $raw = (string) $value;
        if (\is_float($value) && (str_contains($raw, 'E') || str_contains($raw, 'e'))) {
            return rtrim(rtrim(\sprintf('%.17F', $value), '0'), '.');
        }

        return $raw;
    }
}
