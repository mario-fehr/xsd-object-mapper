<?php

declare(strict_types=1);

namespace XsdObjectMapper;

final readonly class TypeInfo
{
    /** @param array{length?: int, minLength?: int, maxLength?: int, pattern?: string, minInclusive?: string, maxInclusive?: string, minExclusive?: string, maxExclusive?: string, totalDigits?: int, fractionDigits?: int} $facets */
    public function __construct(
        public TypeKind $kind,
        public string $phpType,
        public bool $dateOnly = false,
        public array $facets = [],
        public ?string $namedType = null,
    ) {
    }
}
