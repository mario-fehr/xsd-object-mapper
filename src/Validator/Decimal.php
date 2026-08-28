<?php

declare(strict_types=1);

namespace XsdObjectMapper\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Validates xs:decimal-style precision constraints that have no symfony/validator built-in:
 * xs:fractionDigits (max decimal places) and xs:totalDigits (max significant digits, XSD's
 * "ignoring leading/trailing zeros, except the value 0 itself which has 1" rule, approximated).
 */
#[\Attribute]
final class Decimal extends Constraint
{
    public string $fractionDigitsMessage = 'This value must have at most {{ max }} decimal place(s).';
    public string $totalDigitsMessage = 'This value must have at most {{ max }} significant digit(s).';

    public function __construct(
        public readonly ?int $fractionDigits = null,
        public readonly ?int $totalDigits = null,
        ?string $fractionDigitsMessage = null,
        ?string $totalDigitsMessage = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);

        $this->fractionDigitsMessage = $fractionDigitsMessage ?? $this->fractionDigitsMessage;
        $this->totalDigitsMessage = $totalDigitsMessage ?? $this->totalDigitsMessage;
    }
}
