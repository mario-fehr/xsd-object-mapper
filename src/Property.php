<?php

declare(strict_types=1);

namespace XsdObjectMapper;

final readonly class Property
{
    /** @param array{length?: int, minLength?: int, maxLength?: int, pattern?: string, minInclusive?: string, maxInclusive?: string, minExclusive?: string, maxExclusive?: string, totalDigits?: int, fractionDigits?: int} $facets */
    public function __construct(
        public string $phpName,
        public ?string $xmlName,
        public PropertyRole $role,
        public bool $isArray = false,
        public bool $nullable = false,
        public string $kind = 'scalar',
        public string $phpType = 'string',
        public bool $dateOnly = false,
        public array $facets = [],
        public ?string $namedType = null,
        public ?string $doc = null,
    ) {
        if (PropertyRole::Text === $role && null !== $xmlName) {
            throw new \InvalidArgumentException('Property with role Text must have a null xmlName.');
        }
        if (PropertyRole::Text !== $role && null === $xmlName) {
            throw new \InvalidArgumentException("Property with role {$role->name} must have a non-null xmlName.");
        }
    }
}
