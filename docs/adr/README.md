# Architecture Decision Records

Architecturally significant decisions for this XSD-to-PHP generator: build-vs-buy, the input contract, the extension model, and behavior a consumer depends on. Each record captures the context, the decision, and its consequences at the time it was made.

## Log

| ADR                                                            | Title                                                                              | Status   |
| -------------------------------------------------------------- | ---------------------------------------------------------------------------------- | -------- |
| [0001](0001-custom-generator-not-goetas-xsd2php.md)            | Custom generator instead of adopting goetas-webservices/xsd2php                    | Accepted |
| [0002](0002-explicit-xsd-paths-no-include-import-following.md) | Explicit `Config::$xsdPaths`, no `xs:include`/`xs:import` following                | Accepted |
| [0003](0003-nested-type-ownership-declaring-type.md)           | Nested-type ownership follows the declaring type, not an extending subclass        | Accepted |
| [0004](0004-choice-nullable-plus-exactly-one-of.md)            | `xs:choice` elements become nullable plus a class-level `ExactlyOneOf` constraint  | Accepted |
| [0005](0005-xsd-default-fixed-as-doc-hint-only.md)             | `xs:default`/`xs:fixed` surfaced as a doc-comment hint, not a PHP property default | Accepted |
| [0006](0006-semantic-type-aliasing-is-caller-supplied.md)      | Semantic type aliasing is caller-supplied, not built into the package              | Accepted |
| [0007](0007-custom-decimal-constraint-for-digit-facets.md)     | Custom `Decimal` constraint for `totalDigits`/`fractionDigits`                     | Accepted |
| [0008](0008-composable-attribute-strategies.md)                | Composable attribute strategies, not one monolithic strategy                       | Accepted |

## Conventions

- An accepted ADR is a historical record, not living documentation: it is never edited to track a later rename or refactor. When a decision changes, add a new ADR that supersedes the old one and set the old one's status to `Superseded by NNNN`.
- Number sequentially; filename is `NNNN-kebab-title.md`.
- Status is one of: `Accepted`, `Superseded by NNNN`, `Deprecated`.
