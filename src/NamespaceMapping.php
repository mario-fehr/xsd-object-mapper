<?php

declare(strict_types=1);

namespace XsdObjectMapper;

/** Where one XSD targetNamespace's generated classes land: PHP namespace + output directory. */
final readonly class NamespaceMapping
{
    public function __construct(
        public string $phpNamespace,
        public string $outputDir,
    ) {
    }
}
