# xsd-object-mapper

[![CI](https://github.com/mario-fehr/xsd-object-mapper/actions/workflows/ci.yml/badge.svg)](https://github.com/mario-fehr/xsd-object-mapper/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Generates readonly, constructor-promoted PHP value-object classes from XSD schemas: native
types, backed enums for `xs:enumeration`, and pluggable attribute strategies for Symfony
Serializer/Validator annotations on the generated code.

## Requirements

- PHP `^8.4`

## Install

```bash
composer require mario-fehr/xsd-object-mapper
```

## Usage

```php
use XsdObjectMapper\Config;
use XsdObjectMapper\Generator;
use XsdObjectMapper\NamespaceMapping;
use XsdObjectMapper\Attribute\SymfonySerializerAttributeStrategy;

$config = new Config(
    xsdPaths: [__DIR__ . '/schema/purchase-order.xsd'],
    namespaceMap: [
        'http://example.com/po' => new NamespaceMapping(
            phpNamespace: 'App\\Generated\\PurchaseOrder',
            outputDir: __DIR__ . '/src/Generated/PurchaseOrder',
        ),
    ],
    attributeStrategy: new SymfonySerializerAttributeStrategy(),
);

(new Generator())->generate($config);
```

Each XSD `targetNamespace` referenced by a generated type needs an entry in `namespaceMap`, or
generation fails loudly. `xs:include`/`xs:import` are not followed: list every contributing
file in `xsdPaths` explicitly (see [ADR 0002](docs/adr/0002-explicit-xsd-paths-no-include-import-following.md)).

## Attribute strategies

- `SymfonySerializerAttributeStrategy`: Symfony Serializer attributes on generated properties.
- `SymfonyValidatorAttributeStrategy`: Symfony Validator constraints (including custom
  `Decimal` and `ExactlyOneOf` constraints for XSD facets that have no built-in equivalent).
- `SemanticTypeAttributeStrategy`: caller-supplied semantic-type aliasing.
- `CompositeAttributeStrategy`: combines multiple strategies on the same property.

## Supported constructs

See [`docs/construct-coverage.md`](docs/construct-coverage.md) for the full per-construct
support/test matrix, and [`docs/backlog.md`](docs/backlog.md) for the reasoning behind every gap
in that matrix.

## Design decisions

Durable architectural decisions are recorded as ADRs in [`docs/adr/`](docs/adr); see [`docs/adr/README.md`](docs/adr/README.md) for the index and conventions.

## Contributing

Issues and PRs welcome: see [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

MIT: see [LICENSE](LICENSE).
