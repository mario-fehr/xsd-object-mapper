# Config options — Tier 3b (custom type mapping per named simpleType)

## Why

Continuation of `docs/backlog.md`'s "Config options" section, following
[Tier 1](2026-08-27-config-options-tier1-design.md),
[Tier 2](2026-08-27-config-options-tier2-design.md), and
[Tier 3a](2026-08-27-config-options-tier3a-serializer-context-design.md). This is Tier 3b — custom
type mapping per named `simpleType` (`janephp`'s `custom-string-format-mapping`): substitutes a
consumer-supplied value-object class for a specific named XSD `simpleType`, instead of this
generator's own scalar/enum mapping. Also generically unblocks `docs/backlog.md`'s "Type derivation"
section's `xs:decimal` → PHP `float` precision-loss item — a consumer can map their own `Decimal`
value object onto any named `simpleType` restricting `xs:decimal`, without this package adding any
built-in, XSD-type-specific behavior. Matches
[ADR 0006](../adr/0006-semantic-type-aliasing-is-caller-supplied.md)'s existing precedent: "the
package itself holds no knowledge of any specific type name" — this spec doesn't special-case
`xs:decimal` or any other type, it's one generic mechanism.

Reference-repo check: `janephp`'s `CustomStringFormatGuesser`
(`.references/janephp/src/Component/JsonSchema/Guesser/JsonSchema/CustomStringFormatGuesser.php`)
takes `array<string, string>` (JSON Schema `format` keyword => classname) and, on a match, returns a
`CustomObjectType` that _replaces_ the property's type resolution entirely — it runs first in the
guesser chain (`GuesserFactory.php:39`, before `DateGuesser`/`EnumGuesser`/etc.), so a mapped
`format` short-circuits every other type-guessing concern for that property, not just the base scalar
type.

## Goals

- `Config::$scalarTypeMapping: array<string, string> = []`, keyed by XSD QName
  (`"{namespaceURI}#{localName}"`, matching Tier 1's `typeAliases` key shape, not
  [ADR 0006](../adr/0006-semantic-type-aliasing-is-caller-supplied.md)'s `Property::$namedType`
  bare-local-name shape — collision-safe across namespaces, see Non-goals) mapping a named
  `simpleType` to a consumer-supplied class FQCN.
- A property whose type resolves to a mapped named `simpleType` gets `kind: 'class'`,
  `phpType: <mapped FQCN>` — the exact same type-info shape `resolveParticleType()` already produces
  for a `complexType` reference (`Generator.php:471`), so every downstream consumer of `Property::$kind`
  (`buildComplexClass()`'s `use`-import collection, `SymfonyValidatorAttributeStrategy`'s
  `#[Assert\Valid]` cascade, `fqType()`'s FQCN-rendering branch) handles it automatically with zero
  further code changes.
- Matching `CustomStringFormatGuesser`'s "replaces type resolution entirely" behavior: a mapped type
  short-circuits `resolveSimpleTypeRef()` before any enum/facet resolution runs — XSD facets
  (`pattern`/`length`/`minInclusive`/etc.) and enum-ness are not computed at all for a mapped type, so
  `SymfonyValidatorAttributeStrategy`'s facet-derived constraints (`Assert\Length`/`Regex`/`Range`/
  `Xsd2Php\Validator\Decimal`) never apply to a mapped property. Confirmed direction: the mapping is a
  full substitution, not an addition — the consumer's class owns its own validation, if any.

## Non-goals

- Not the same field as Tier 1's `typeAliases` — `typeAliases` renames a class this generator still
  generates; `scalarTypeMapping` substitutes an external class this generator never generates or
  imports-checks beyond the `use` statement. Both keyed by QName for the same collision-safety reason,
  but they answer different questions and a given key could theoretically appear in both without
  conflict (a `complexType` alias and an unrelated `simpleType` mapping never collide on the same key
  in practice, since `complexType`/`simpleType` are separate registries per
  `Generator::SCHEMA_NAME_BUCKETS`).
- Does not touch `Property::$namedType`/`SemanticTypeAttributeStrategy` (ADR 0006) — that mechanism
  stays bare-local-name-keyed and _adds_ a constraint attribute to an otherwise-normal scalar property;
  this spec's mapping fully replaces the property's type, a structurally different operation. A type
  that's `scalarTypeMapping`-mapped simply never reaches the `namedType`-assignment line
  (`Generator.php:333`) since resolution short-circuits earlier — `Property::$namedType` stays `null`
  for a mapped property, so `SemanticTypeAttributeStrategy`'s alias lookup naturally no-ops for it too
  (consistent, not a new special case to implement).
- No package-provided mappings for any specific XSD type (`xs:decimal` included) — purely a generic,
  consumer-configured mechanism, per ADR 0006's stated philosophy.
- A mapped type used as an `xs:enumeration`-carrying `simpleType` intentionally loses its enum
  generation too (the mapping wins over enum-ness, not just over facets) — see Edge cases.
- No constructor-argument shape assumed for the mapped class — the mapped FQCN is used purely as a PHP
  type hint; how the consumer's class gets _instantiated_ during deserialization is entirely the
  consumer's `symfony/serializer` denormalizer configuration, outside this generator's concern.

## API

`src/Config.php` — one more constructor parameter (additive to Tier 1's four + Tier 2's one +
Tier 3a's one):

```php
/** @var array<string, string> XSD QName ("{namespaceURI}#{localName}") => consumer value-object FQCN, replaces this generator's own scalar/enum mapping for that named simpleType entirely */
public array $scalarTypeMapping = [],
```

## Implementation scope

`Generator::resolveSimpleTypeRef(string $key): array` — single hook point, since every named-`
simpleType` reference (whether from an element/attribute `type="..."` via
`resolvePrimitiveOrNamedSimpleType()`, or `generate()`'s own top-level iteration) funnels through this
one method. Currently:

```php
private function resolveSimpleTypeRef(string $key): array
{
    if (isset($this->resolvedSimple[$key])) {
        return $this->resolvedSimple[$key];
    }
    // break self-reference cycles defensively
    $this->resolvedSimple[$key] = self::STRING_FALLBACK;
    ...
```

becomes:

```php
private function resolveSimpleTypeRef(string $key): array
{
    if (isset($this->resolvedSimple[$key])) {
        return $this->resolvedSimple[$key];
    }
    if (isset($this->config->scalarTypeMapping[$key])) {
        return $this->resolvedSimple[$key] = ['kind' => 'class', 'phpType' => $this->config->scalarTypeMapping[$key]];
    }
    // break self-reference cycles defensively
    $this->resolvedSimple[$key] = self::STRING_FALLBACK;
    ...
```

No `facets`/`dateOnly`/`namedType` keys in the returned array — matches the existing `['kind' =>
'class', 'phpType' => ...]` shape `resolveParticleType()` already returns for a `complexType`
reference (`Generator.php:471`), which also carries none of those keys. `makeProperty()`'s
`$typeInfo['...'] ?? default` fallbacks (`Generator.php:724-729`) already handle a typeInfo array
missing those keys — no change needed there.

No changes needed in `buildComplexClass()` (import collection already branches on
`\in_array($p->kind, ['class', 'enum'], true)`, a mapped property's `kind: 'class'` is already
covered), `SymfonyValidatorAttributeStrategy` (branches on `'class' === $property->kind` for
`#[Assert\Valid]`, on `!$property->isArray` for facet constraints — a mapped property's empty
`$property->facets` array means `facetConstraints([])` naturally returns `[]`, no special-casing
needed), or `fqType()`/`phpPropertyType()`/`phpDocType()` (already FQCN-render any `'class'`-kind
`phpType` via `TypeRenderContext`).

## Testing

- `GeneratorTest.php`: a fixture with a named `simpleType` (with `xs:pattern`/`xs:minLength` facets,
  to prove they're dropped) mapped via `scalarTypeMapping` generates a property typed to the mapped
  FQCN, with a `use` import for it, and with **no** `Assert\Regex`/`Assert\Length` constraint attached
  (only `#[Assert\Valid]` alongside the usual presence constraint, matching any other `class`-kind
  property).
- A fixture with a named `simpleType` carrying `xs:enumeration` values, mapped via
  `scalarTypeMapping`, generates the mapped FQCN instead of a backed enum — proves the mapping wins
  over enum generation too.
- A fixture with `scalarTypeMapping: []` (default) is unaffected — byte-identical regression check
  against `tests/fixtures/w3c-purchase-order.xsd`, same bar every prior tier spec used.
- `resolveSimpleTypeRef()`'s cycle-guard (`$this->resolvedSimple[$key] = self::STRING_FALLBACK;` set
  _before_ recursing) is unreachable for a mapped key (the mapped branch returns before that line) —
  confirm this doesn't change cycle-detection behavior for any _other_, unmapped named `simpleType`
  that happens to reference the mapped one as its own restriction base (it can't: a mapped type's
  resolution never recurses into `resolvePrimitiveOrNamedSimpleType()` at all, so nothing downstream
  of it can be part of a self-reference cycle through this method).

## Edge cases

- **Mapping wins over enum-ness** (stated in Goals, repeated here since it's easy to miss): a named
  `simpleType` with both a mapping entry and `xs:enumeration` children generates the mapped class, not
  a PHP `enum` — the mapping is checked first, unconditionally, before the enum/facet branches run at
  all.
- **No FQCN validation.** `scalarTypeMapping`'s value is used verbatim as a PHP class-name string (both
  as the property type hint and as a `use`-import target) — no check that the class exists, is
  autoloadable, or exposes a compatible constructor. Same caller-trust posture as Tier 1's
  `typeAliases`.
- **`Property::$namedType` staying `null` for a mapped property** (Non-goals) means a consumer cannot
  combine this spec's type-substitution with `SemanticTypeAttributeStrategy`'s constraint-aliasing for
  the _same_ named `simpleType` — not a real limitation in practice, since a consumer substituting
  their own class for a type is already free to put any `symfony/validator` constraints they want
  directly on that class.
- **Array-of-mapped-type properties** need no special handling: `isArray` facet-skipping already exists
  independent of this spec (`SymfonyValidatorAttributeStrategy.php:49-51`, the pre-existing
  `Array-item-wise validation` gap noted in `docs/backlog.md`'s `symfony/validator constraints`
  section) — a mapped, array-typed property was already getting no facet constraints before this spec,
  for an unrelated reason. Confirmed not a new gap this spec introduces.
