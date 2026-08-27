<?php

declare(strict_types=1);

namespace Xsd2Php;

use Xsd2Php\Attribute\PropertyAttributeStrategy;

final readonly class Config
{
    /**
     * @param string[] $xsdPaths Explicit, ordered list of .xsd files to pool together. Each
     *   file's own targetNamespace applies to its top-level declarations; xs:include/xs:import
     *   directives inside the files themselves are NOT followed - every file that contributes
     *   types must be listed explicitly.
     * @param array<string, NamespaceMapping> $namespaceMap Maps each XSD targetNamespace URI
     *   (the empty string for no targetNamespace) to the PHP namespace/output directory its
     *   top-level classes are generated into. Every targetNamespace referenced by a type that
     *   ends up generated must have an entry, or generation fails loudly.
     */
    public function __construct(
        public array                     $xsdPaths,
        public array                     $namespaceMap,
        public PropertyAttributeStrategy $attributeStrategy,
    ) {
    }
}
