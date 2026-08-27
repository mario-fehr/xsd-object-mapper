<?php

declare(strict_types=1);

namespace Xsd2Php;

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
    }
}
