# 5. `xs:default`/`xs:fixed` surfaced as a doc-comment hint, not a PHP property default

## Context

`xs:default`/`xs:fixed` on elements and attributes were completely ignored by the generator: every
optional property was generated as `?type $x = null;` regardless of an XSD-level default, and a
consumer had to read the XSD directly to learn the effective default.

## Decision

Surface `xs:default`/`xs:fixed` as an informational `(XSD-Default: ...)` / `(XSD-Fixed: ...)`
doc-comment hint on the generated property. Do not change the property's PHP default value,
nullability, or type.

## Consequences

Consumers reading generated code see the schema's intended default without any behavior change;
serialization/deserialization is unaffected. The cost of avoiding the rejected alternative's risk is
that the default stays purely documentary: nothing enforces or applies it automatically.

## Considered and rejected

- **Setting the PHP property default to the XSD default value directly** (e.g. `bool $x = false`
  instead of `?bool $x = null`): rejected, it changes real serialization behavior, since a property
  that can never be `null` is always emitted on outgoing serialization even when the caller intended
  to defer to the receiving system's own default, and it destroys the distinction between
  "deliberately omitted" and "deliberately set to the default value".
