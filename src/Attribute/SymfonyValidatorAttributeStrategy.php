<?php

declare(strict_types=1);

namespace Xsd2Php\Attribute;

use Xsd2Php\Property;

/**
 * Emits symfony/validator constraints derived from the property model:
 *
 * Presence (from nullable/isArray/phpType, always available):
 * - required string (not nullable, not array)            -> #[Assert\NotBlank]
 * - required non-string scalar/class/enum                -> #[Assert\NotNull]
 * - required array (isArray && !nullable, minOccurs >= 1) -> #[Assert\Count(min: 1)]
 * - optional/nullable properties                          -> no presence constraint
 *
 * Facets (from $property->facets - merged across a whole chain of nested named-simpleType
 * restrictions, closer-to-the-property facets winning on a key collision with an ancestor's;
 * skipped entirely for array properties, since these validate a single scalar value, not each
 * item):
 * - xs:pattern                        -> #[Assert\Regex] (XSD pattern is implicitly anchored,
 *   wrapped as ^(?:...)$ for PCRE; XSD's regex dialect isn't 100% PCRE-compatible, works for
 *   the common cases seen in practice)
 * - xs:length                         -> #[Assert\Length(exactly: ...)] (mutually exclusive
 *   with minLength/maxLength on a valid schema, takes precedence if both are somehow present)
 * - xs:minLength / xs:maxLength       -> #[Assert\Length]
 * - xs:minInclusive / xs:maxInclusive -> #[Assert\Range]
 * - xs:minExclusive                   -> #[Assert\GreaterThan]
 * - xs:maxExclusive                   -> #[Assert\LessThan]
 * - xs:totalDigits / xs:fractionDigits -> #[Xsd2Php\Validator\Decimal] (custom constraint,
 *   symfony/validator has no built-in for either)
 *
 * Cascading (from $property->kind, regardless of nullable/isArray):
 * - kind === 'class' (a nested generated DTO, scalar or array-of) -> #[Assert\Valid] - without
 *   this, symfony/validator only checks a property's own constraints and never descends into
 *   its value's constraints, so none of the above would ever fire on nested objects.
 */
final class SymfonyValidatorAttributeStrategy implements PropertyAttributeStrategy
{
    public function attributesFor(Property $property): array
    {
        $attrs = $this->presenceConstraint($property);

        if ('class' === $property->kind) {
            $attrs[] = ['fqcn' => \Symfony\Component\Validator\Constraints\Valid::class, 'args' => ''];
        }

        if (!$property->isArray) {
            return [...$attrs, ...$this->facetConstraints($property->facets)];
        }

        return $attrs;
    }

    private function presenceConstraint(Property $property): array
    {
        if ($property->isArray) {
            return $property->nullable ? [] : [[
                'fqcn' => \Symfony\Component\Validator\Constraints\Count::class,
                'args' => 'min: 1',
            ]];
        }

        if ($property->nullable) {
            return [];
        }

        if ('scalar' === $property->kind && 'string' === $property->phpType) {
            return [[
                'fqcn' => \Symfony\Component\Validator\Constraints\NotBlank::class,
                'args' => '',
            ]];
        }

        return [[
            'fqcn' => \Symfony\Component\Validator\Constraints\NotNull::class,
            'args' => '',
        ]];
    }

    /** @param array{length?: int, minLength?: int, maxLength?: int, pattern?: string, minInclusive?: string, maxInclusive?: string, minExclusive?: string, maxExclusive?: string, totalDigits?: int, fractionDigits?: int} $facets */
    private function facetConstraints(array $facets): array
    {
        $attrs = [];

        if (isset($facets['pattern'])) {
            $attrs[] = [
                'fqcn' => \Symfony\Component\Validator\Constraints\Regex::class,
                'args' => 'pattern: '.var_export($this->toPcrePattern($facets['pattern']), true),
            ];
        }

        if (isset($facets['length'])) {
            // xs:length is mutually exclusive with minLength/maxLength on a valid schema, so it
            // alone decides the Length constraint when present.
            $attrs[] = ['fqcn' => \Symfony\Component\Validator\Constraints\Length::class, 'args' => "exactly: {$facets['length']}"];
        } else {
            $lengthArgs = $this->minMaxArgs($facets, 'minLength', 'maxLength');
            if ('' !== $lengthArgs) {
                $attrs[] = ['fqcn' => \Symfony\Component\Validator\Constraints\Length::class, 'args' => $lengthArgs];
            }
        }

        $rangeArgs = $this->minMaxArgs($facets, 'minInclusive', 'maxInclusive');
        if ('' !== $rangeArgs) {
            $attrs[] = ['fqcn' => \Symfony\Component\Validator\Constraints\Range::class, 'args' => $rangeArgs];
        }

        if (isset($facets['minExclusive'])) {
            $attrs[] = ['fqcn' => \Symfony\Component\Validator\Constraints\GreaterThan::class, 'args' => "value: {$facets['minExclusive']}"];
        }
        if (isset($facets['maxExclusive'])) {
            $attrs[] = ['fqcn' => \Symfony\Component\Validator\Constraints\LessThan::class, 'args' => "value: {$facets['maxExclusive']}"];
        }

        $decimalArgs = [];
        if (isset($facets['fractionDigits'])) {
            $decimalArgs[] = "fractionDigits: {$facets['fractionDigits']}";
        }
        if (isset($facets['totalDigits'])) {
            $decimalArgs[] = "totalDigits: {$facets['totalDigits']}";
        }
        if ([] !== $decimalArgs) {
            $attrs[] = ['fqcn' => \Xsd2Php\Validator\Decimal::class, 'args' => implode(', ', $decimalArgs)];
        }

        return $attrs;
    }

    private function minMaxArgs(array $facets, string $minKey, string $maxKey): string
    {
        $args = [];
        if (isset($facets[$minKey])) {
            $args[] = "min: {$facets[$minKey]}";
        }
        if (isset($facets[$maxKey])) {
            $args[] = "max: {$facets[$maxKey]}";
        }

        return implode(', ', $args);
    }

    /** XSD's xs:pattern matches the whole value implicitly; PCRE needs that made explicit. */
    private function toPcrePattern(string $xsdPattern): string
    {
        $delimiter = array_find(['#', '~', '!', '%'], static fn ($candidate): bool => !str_contains($xsdPattern, $candidate));
        if (null === $delimiter) {
            throw new \RuntimeException("xs:pattern '{$xsdPattern}' contains all candidate PCRE delimiters, cannot build a safe pattern");
        }

        return $delimiter.'^(?:'.$xsdPattern.')$'.$delimiter.'u';
    }
}
