# 6. Semantic type aliasing is caller-supplied, not built into the package

## Context

Some named simpleTypes carry a semantic meaning beyond their structural facets — e.g. an email-shaped
string, a country-code string — that `symfony/validator` has purpose-built constraints for
(`Assert\Email`, `Assert\Country`). That meaning can only be inferred from the XSD type's _name_, a
heuristic, not something derivable from facets alone, and not something the package can know for any
given consumer's schema in general.

## Decision

Add `namedType` (the referenced named simpleType's local name, or `null` for primitives/
inline-anonymous types) to the property model, and a generic `Xsd2Php\Attribute\SemanticTypeAttributeStrategy`
that takes a caller-supplied alias table (`XSD type name => constraint`). The package itself holds no
knowledge of any specific type name.

## Consequences

Any consumer can opt into semantic constraints for their own schema's type names without a package
change; the package stays schema-agnostic. The alias table itself is the consumer's responsibility to
define and maintain — the risk of an incorrect alias (e.g. a loosely-patterned identifier type that
doesn't actually satisfy a stricter built-in constraint) is a per-alias judgment call made by whoever
configures the table, not something the package can validate.

## Considered and rejected

- **Auto-aliasing an identifier-shaped type to `Assert\Uuid`** — rejected in the evaluated case:
  `Assert\Uuid(strict: false)` still enforces RFC 4122 version/variant bits that an XSD hex-pattern
  type may not guarantee. A wrong alias produces false-negative validation, worse than no alias at all.
