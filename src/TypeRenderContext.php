<?php

declare(strict_types=1);

namespace XsdObjectMapper;

/**
 * Resolves how a class/enum property type is rendered inside one generated class file:
 * bare (own namespace), short imported name (unambiguous), or fully-qualified (basename collision).
 */
final readonly class TypeRenderContext
{
    /**
     * @param array<string, true>   $sameNamespaceTypes fqcn => true, types living in the class being generated's own namespace
     * @param array<string, string> $imports            fqcn => shortName
     * @param array<string, int>    $shortNameUsedBy    shortName => count of distinct fqcns using it
     */
    public function __construct(
        private array $sameNamespaceTypes,
        private array $imports,
        private array $shortNameUsedBy,
    ) {
    }

    public function render(string $fqcn): string
    {
        if (isset($this->sameNamespaceTypes[$fqcn])) {
            return Naming::basename($fqcn);
        }
        $shortName = $this->imports[$fqcn];

        return 1 === $this->shortNameUsedBy[$shortName] ? $shortName : '\\'.$fqcn;
    }
}
