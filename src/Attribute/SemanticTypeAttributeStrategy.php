<?php

declare(strict_types=1);

namespace Xsd2Php\Attribute;

/**
 * Adds a caller-supplied constraint when a property's directly-referenced named simpleType
 * matches an entry in the alias map - a heuristic keyed by XSD type name (e.g. "EmailType" ->
 * Assert\Email), not something derivable from facets alone. This class knows nothing about any
 * specific schema; the alias map is the caller's decision. Skipped for array properties, same
 * as facet constraints - these validate a single scalar value, not each item.
 */
final readonly class SemanticTypeAttributeStrategy implements PropertyAttributeStrategy
{
    /** @param array<string, array{fqcn: string, args: string}> $aliasMap XSD simpleType local name => constraint to add */
    public function __construct(private array $aliasMap)
    {
    }

    public function attributesFor(array $property): array
    {
        if ($property['isArray']) {
            return [];
        }

        $namedType = $property['namedType'] ?? null;
        if ($namedType === null || !isset($this->aliasMap[$namedType])) {
            return [];
        }

        return [$this->aliasMap[$namedType]];
    }
}
