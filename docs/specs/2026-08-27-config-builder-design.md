# ConfigBuilder — fluent construction for Config

## Why

`Config` (`src/Config.php`) is a `final readonly class` built via a single named-argument constructor
call. Across [Tier 1](2026-08-27-config-options-tier1-design.md),
[Tier 2](2026-08-27-config-options-tier2-design.md),
[Tier 3a](2026-08-27-config-options-tier3a-serializer-context-design.md),
[Tier 3b](2026-08-27-config-options-tier3b-scalar-type-mapping-design.md), and
[Tier 4a](2026-08-27-config-options-tier4a-property-accessor-style-design.md), `Config` grows from 3
constructor parameters to 15. PHP 8.4 named arguments keep a single large constructor call reasonably
readable regardless of parameter count, so this isn't primarily a "too many params" problem — it's an
**incremental-collection-building** problem: three of `Config`'s fields (`xsdPaths`, `namespaceMap`,
and, after Tier 1/3b, `typeAliases`/`scalarTypeMapping`) are arrays a caller often wants to build up
one entry at a time (e.g. one `namespaceMap` entry per XSD `targetNamespace`, looped or added
conditionally) rather than construct as one array literal up front.

Reference-repo check: `makinacorpus/php-xsd-gen`'s `AbstractGenerator`
(`.references/makinacorpus-php-xsd-gen/src/Helper/AbstractGenerator.php`) is a mutable fluent builder
over the same option set its `GeneratorConfig` ultimately holds — `->file()` appends one XSD path at a
time, `->namespace()` appends one namespace-map entry at a time, every boolean option gets a
`toggle(bool $toggle = true): static` method. Adopting the _shape_ of that pattern (fluent, mutable
builder wrapping an otherwise-immutable config), not its `logger()` method — this generator has no
`LoggerInterface` abstraction anywhere today (`Generator::warn()`/`note()` write directly to `STDERR`);
adding one would be an unrelated observability feature, not a `Config`-construction-ergonomics one, out
of scope here.

This spec does not make any Tier 1-4a field obsolete — `ConfigBuilder` is a strictly additive
convenience layer that ends in `->build(): Config`, calling `Config`'s existing constructor. `Config`
itself stays exactly as each prior spec defined it, immutable, the canonical construction path for a
caller who prefers one constructor call over incremental building.

## Goals

- New `src/ConfigBuilder.php`, `final class` (deliberately _not_ `readonly` — a fluent builder is
  inherently mutable internal state, unlike every other value object this package exposes).
- One fluent method per `Config` field, `return $this` (technically `static` for subclass-safety, no
  subclassing use case exists today but costs nothing to declare correctly).
- Collection fields get an _appending_ method (`file()`, `namespace()`, `typeAlias()`,
  `scalarTypeMapping()` — singular, one entry per call) rather than a method that replaces the whole
  array, since the incremental-building ergonomics are the entire point (a caller who already has a
  complete array can still pass it via `Config`'s constructor directly — the builder isn't the only
  way to construct one).
- Boolean fields get `methodName(bool $toggle = true): static`, matching
  `makinacorpus/php-xsd-gen`'s own convention — `->cleanGenerated()` (defaults to enabling) and
  `->cleanGenerated(false)` (explicit disable) both read naturally.
- `build(): Config` validates the one field `Config`'s own constructor requires with no default
  (`attributeStrategy`) is set, throwing `\LogicException` if not — every other field falls back to
  `Config`'s own constructor defaults when the builder method was never called, no builder-side default
  duplication.

## Non-goals

- No `logger()` method or any `LoggerInterface` integration — see Why.
- No validation duplicated from `Config`'s own constructor (e.g. Tier 4a's `propertyReadonly` +
  `propertySetter` conflict) — `build()` calls `new Config(...)`, which already throws; the builder
  doesn't pre-check anything `Config` itself already guards.
- No fluent method that _replaces_ a whole collection field (e.g. no `xsdPaths(array $paths): static`
  alongside `file(string $path): static`) — one appending method per collection field keeps the API
  surface from doubling for no ergonomic gain a direct `Config` constructor call doesn't already cover.
- `Config`'s constructor stays the canonical/primary construction path, documented as such — this
  spec doesn't deprecate or steer callers away from it, `ConfigBuilder` is an alternative for the
  specific incremental-collection case, not a replacement.

## API

`src/ConfigBuilder.php`:

```php
final class ConfigBuilder
{
    private array $xsdPaths = [];
    private array $namespaceMap = [];
    private ?PropertyAttributeStrategy $attributeStrategy = null;
    private array $typeAliases = [];
    private bool $cleanGenerated = true;
    private bool $typeMissingError = false;
    private bool $typeOverrideError = false;
    private bool $datePreferInterface = false;
    private ?bool $skipNullValues = null;
    private array $scalarTypeMapping = [];
    private bool $propertyPromotion = true;
    private bool $propertyPublic = true;
    private bool $propertyReadonly = true;
    private bool $propertyGetter = false;
    private bool $propertySetter = false;

    public function file(string $xsdPath): static { $this->xsdPaths[] = $xsdPath; return $this; }

    public function namespace(string $xsdNamespaceUri, NamespaceMapping $mapping): static
    {
        $this->namespaceMap[$xsdNamespaceUri] = $mapping;

        return $this;
    }

    public function attributeStrategy(PropertyAttributeStrategy $strategy): static { $this->attributeStrategy = $strategy; return $this; }

    public function typeAlias(string $xsdQName, string $className): static { $this->typeAliases[$xsdQName] = $className; return $this; }

    public function cleanGenerated(bool $toggle = true): static { $this->cleanGenerated = $toggle; return $this; }

    public function typeMissingError(bool $toggle = true): static { $this->typeMissingError = $toggle; return $this; }

    public function typeOverrideError(bool $toggle = true): static { $this->typeOverrideError = $toggle; return $this; }

    public function datePreferInterface(bool $toggle = true): static { $this->datePreferInterface = $toggle; return $this; }

    public function skipNullValues(bool $toggle = true): static { $this->skipNullValues = $toggle; return $this; }

    public function scalarTypeMapping(string $xsdQName, string $className): static { $this->scalarTypeMapping[$xsdQName] = $className; return $this; }

    public function propertyPromotion(bool $toggle = true): static { $this->propertyPromotion = $toggle; return $this; }

    public function propertyPublic(bool $toggle = true): static { $this->propertyPublic = $toggle; return $this; }

    public function propertyReadonly(bool $toggle = true): static { $this->propertyReadonly = $toggle; return $this; }

    public function propertyGetter(bool $toggle = true): static { $this->propertyGetter = $toggle; return $this; }

    public function propertySetter(bool $toggle = true): static { $this->propertySetter = $toggle; return $this; }

    public function build(): Config
    {
        if (null === $this->attributeStrategy) {
            throw new \LogicException('ConfigBuilder::attributeStrategy() must be called before build().');
        }

        return new Config(
            xsdPaths: $this->xsdPaths,
            namespaceMap: $this->namespaceMap,
            attributeStrategy: $this->attributeStrategy,
            typeAliases: $this->typeAliases,
            cleanGenerated: $this->cleanGenerated,
            typeMissingError: $this->typeMissingError,
            typeOverrideError: $this->typeOverrideError,
            datePreferInterface: $this->datePreferInterface,
            skipNullValues: $this->skipNullValues,
            scalarTypeMapping: $this->scalarTypeMapping,
            propertyPromotion: $this->propertyPromotion,
            propertyPublic: $this->propertyPublic,
            propertyReadonly: $this->propertyReadonly,
            propertyGetter: $this->propertyGetter,
            propertySetter: $this->propertySetter,
        );
    }
}
```

`skipNullValues`'s builder-side default is `null` (matching Tier 3a's `Config::$skipNullValues: ?bool
= null` three-state field exactly — `bool $toggle = true` for the _method_ signature still works,
since calling `->skipNullValues()` or `->skipNullValues(true)`/`->skipNullValues(false)` all produce a
concrete `bool`; the field only stays `null` when the method is never called at all).

## Implementation scope

This spec fixes the target API — the full 15-method surface above — but implementation lands
incrementally, not as one batch:

- **This spec's own plan** implements the `ConfigBuilder` class itself plus the three methods for
  `Config`'s existing fields (`file()`, `namespace()`, `attributeStrategy()`, `build()`) — these three
  fields already exist in `Config` today, nothing to wait on.
- **Every other method** (one per Tier 1/2/3a/3b/4a field) is added as a task on _that field's own_
  tier's implementation plan, once `writing-plans` turns that tier's spec into a plan — not batched
  into a separate "catch up `ConfigBuilder`" plan after the fact. Matches the already-agreed convention
  of updating `Config`'s own docblock per-tier rather than in one final pass (same session, same
  reasoning: keep the builder and the field it wraps landing in the same commit).
- Consequence: `ConfigBuilder`'s full 15-method shape isn't achieved until every referenced tier's plan
  has actually run — `docs/note.md` already tracks each tier spec's own "resume with `writing-plans`"
  entry; this spec doesn't introduce new tracking beyond adding itself to that same list once approved.

## Testing

- `ConfigBuilderTest.php` (new): `build()` without calling `attributeStrategy()` throws
  `\LogicException`; a builder with only `attributeStrategy()` called produces a `Config` matching
  `Config`'s own all-defaults constructor call; `file()`/`namespace()`/`typeAlias()`/
  `scalarTypeMapping()` called multiple times each accumulate correctly (order-preserving for
  `xsdPaths`, last-write-wins per key for the three map fields, matching plain PHP array-append/
  array-key-assignment semantics — no special merge logic to test beyond that).
- Each boolean field's builder method gets a one-line test confirming `->method()` (no arg) and
  `->method(false)` both produce the expected `Config::$field` value — mechanical, added alongside that
  field's own tier plan rather than upfront (per Implementation scope), so this is a description of
  what those later plans should each include, not a task this spec's own plan runs 15 times today.

## Edge cases

- `namespace()` takes a pre-built `NamespaceMapping` value object rather than its two constructor
  fields (`phpNamespace`, `outputDir`) as separate builder-method parameters — keeps `ConfigBuilder`
  from needing to know `NamespaceMapping`'s internal shape, single source of truth stays
  `NamespaceMapping` itself, consistent with how `Config`'s own constructor already takes
  `array<string, NamespaceMapping>`, not raw namespace/directory string pairs.
- Calling the same appending method twice with the same key (`namespace()` with the same
  `$xsdNamespaceUri`, `typeAlias()`/`scalarTypeMapping()` with the same QName) silently overwrites,
  same as assigning the same array key twice — no dedicated collision diagnostic, matching plain-PHP-
  array semantics a caller already expects from a `->method(key, value)`-shaped builder API.
- `build()` can be called more than once on the same `ConfigBuilder` instance (returns a fresh `Config`
  each time from the builder's current state) — no single-use enforcement, since nothing about
  `ConfigBuilder`'s mutable internal state requires it and forbidding reuse would be an arbitrary
  restriction with no stated need.
