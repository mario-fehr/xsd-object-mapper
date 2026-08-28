# 1. Custom generator instead of adopting goetas-webservices/xsd2php

## Context

Needed an XSD-to-PHP generator producing `readonly` value objects annotated with `symfony/serializer`
attributes. An established package doing structurally similar work already exists:
`goetas-webservices/xsd2php`.

## Decision

Build a custom generator rather than depending on `goetas-webservices/xsd2php`.

## Consequences

Full control over the generated output shape (PHP attributes, not external YAML/XML mapping files)
and over the extension model (`PropertyAttributeStrategy`). In exchange, the generator itself
(parsing, resolution, codegen) is maintained in-house instead of reusing a maintained dependency's
test coverage and edge-case handling.

## Considered and rejected

- **`goetas-webservices/xsd2php`**: targets JMS Serializer with external YAML/XML mapping files,
  not PHP attributes with `symfony/serializer`; maintenance status was unclear at evaluation time.
  Its namespace → PHP-namespace/output-dir mapping idea was still adopted (`NamespaceMapping`,
  `Config::$namespaceMap`) even though the dependency itself was not.
