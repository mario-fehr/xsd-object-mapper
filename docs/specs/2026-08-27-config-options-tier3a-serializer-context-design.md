# Config options — Tier 3a (configurable date format(s), skip-null-values)

## Why

Continuation of `docs/backlog.md`'s "Config options" section, following
[Tier 1](2026-08-27-config-options-tier1-design.md) and
[Tier 2](2026-08-27-config-options-tier2-design.md). Tier 3 splits into two independent subsystems
(see the Tier 3 note in `docs/backlog.md`, itself superseded by the findings below); this spec is
Tier 3a — configurable date format(s) and `skip-null-values`, both extending
`symfony/serializer`'s `#[Context]` attribute mechanism. Tier 3b (custom type mapping per named
`simpleType`) is a separate spec, unrelated code path.

Two corrections to the backlog wording found while scoping this spec:

1. **"Both need Symfony Serializer context-attribute generation, which doesn't exist yet" is false.**
   `SymfonySerializerAttributeStrategy::attributesFor()` already emits
   `#[Context(['datetime_format' => 'Y-m-d'])]` for `dateOnly` properties
   (`src/Attribute/SymfonySerializerAttributeStrategy.php:30-35`). The mechanism exists; it's just
   narrowly hardcoded to one format string, for one property kind (`dateOnly`, not full `dateTime`).
   The real gap is generalizing an existing mechanism, not building one from scratch.
2. **Where the config lives.** Per [ADR 0008](../adr/0008-composable-attribute-strategies.md),
   per-strategy configuration belongs on the strategy itself, not the shared `Config` object — "the
   composition decision... lives entirely with the caller building the `Config`". So the date-format
   options land as `SymfonySerializerAttributeStrategy` constructor parameters, not new `Config`
   fields. `skip-null-values` is different: it's a *class*-level attribute (applies uniformly across
   all of a generated class's nullable properties), and this codebase's only existing class-level
   attribute (`#[ExactlyOneOf]`, from the choice-group feature) is generated directly by
   `Generator::buildComplexClass()`, config-driven, with no strategy indirection at all — `Config`
   gets a `skipNullValues` field, following that precedent instead of ADR 0008's per-property-strategy
   pattern (which doesn't apply to a class-level concern).

`janephp`'s option names/defaults referenced for scoping (not adopted 1:1 — see API section for why):
`full-date-format` (default `'Y-m-d'`, this generator's current hardcoded date-only format),
`date-format` (default RFC3339, the full-datetime format), `date-input-format` (default `null`, a
separate *input*-parsing format) —
`.references/janephp/src/Component/JsonSchema/Console/Loader/ConfigLoader.php:67-70`.

## Goals

- `SymfonySerializerAttributeStrategy`'s constructor gains `dateOnlyFormat: string = 'Y-m-d'`
  (replaces the current hardcoded literal, same default, same output) and
  `dateTimeFormat: ?string = null` (new: when set, a full — non-`dateOnly` — `\DateTimeImmutable`/
  `\DateTimeInterface` property also gets a `#[Context(['datetime_format' => ...])]`; `null` keeps
  today's behavior of no `Context` attribute on those properties at all).
- `Config::$skipNullValues: ?bool = null` — when non-`null`, every generated class gets a class-level
  `#[Context(['skip_null_values' => $value])]`, alongside (not replacing) any `#[ExactlyOneOf]`
  already emitted for that class. `null` (default) emits nothing, preserving current output.

## Non-goals

- No `date-input-format` equivalent. `janephp` needs it because its JSON-Schema source data is
  untyped strings that may arrive in a different format than they're serialized back out in — a
  problem specific to schema-less JSON input. `symfony/serializer`'s `DateTimeNormalizer` already
  uses one `datetime_format` context key for both `normalize()` and `denormalize()`
  (falls back to `\DateTime::ATOM` when unset) — one format string per date-kind is sufficient here,
  confirm this against `symfony/serializer`'s current `DateTimeNormalizer` behavior at implementation
  time rather than assume.
- `skipNullValues` is one global flag for the whole generation run, not configurable per-namespace or
  per-type — matches every other `Config` flag added in Tier 1/Tier 2.
- No merging logic between a property's own `dateOnly`-driven `#[Context]` and the new class-level
  `skipNullValues`-driven `#[Context]` — they're different attribute *targets* (property vs. class),
  `symfony/serializer` attributes support both independently on the same class without conflict, no
  special-casing needed.

## API

`src/Attribute/SymfonySerializerAttributeStrategy.php`:

```php
public function __construct(
    private readonly string $dateOnlyFormat = 'Y-m-d',
    private readonly ?string $dateTimeFormat = null,
) {
}
```

`src/Config.php` — one more constructor parameter (additive to Tier 1's four + Tier 2's one):

```php
public ?bool $skipNullValues = null,
```

## Implementation scope

**`SymfonySerializerAttributeStrategy::attributesFor()`** — currently:

```php
if ($property->dateOnly) {
    $attrs[] = [
        'fqcn' => 'Symfony\Component\Serializer\Attribute\Context',
        'args' => "['datetime_format' => 'Y-m-d']",
    ];
}
```

becomes:

```php
if ($property->dateOnly) {
    $attrs[] = [
        'fqcn' => 'Symfony\Component\Serializer\Attribute\Context',
        'args' => "['datetime_format' => ".var_export($this->dateOnlyFormat, true).']',
    ];
} elseif (null !== $this->dateTimeFormat && \in_array($property->phpType, ['\DateTimeImmutable', '\DateTimeInterface'], true)) {
    $attrs[] = [
        'fqcn' => 'Symfony\Component\Serializer\Attribute\Context',
        'args' => "['datetime_format' => ".var_export($this->dateTimeFormat, true).']',
    ];
}
```

The `'\DateTimeImmutable', '\DateTimeInterface'` check covers both today's hardcoded type and
[Tier 2](2026-08-27-config-options-tier2-design.md)'s `datePreferInterface` swap — confirm both spec's
implementation order at plan time (this spec doesn't depend on Tier 2 landing first, the `in_array`
check is correct whichever type is actually in use).

**`Generator::buildComplexClass()`** — `skipNullValues` follows the exact same shape as the existing
`$choiceGroups`-driven `#[ExactlyOneOf]` class attribute:

- Import registration, alongside the existing `if ([] !== $choiceGroups) { $imports[Validator\ExactlyOneOf::class] ??= 'ExactlyOneOf'; }` block:
  ```php
  if (null !== $this->config->skipNullValues) {
      $imports['Symfony\Component\Serializer\Attribute\Context'] ??= 'Context';
  }
  ```
  (Idempotent either way if a property-level `dateOnly`/`dateTimeFormat` `Context` attribute already
  registered the same fqcn earlier in the same method — `??=` is a no-op on the second registration.)
- Class attribute line, alongside the existing `foreach ($choiceGroups as $group) { $classAttrLines[] = ...; }` loop:
  ```php
  if (null !== $this->config->skipNullValues) {
      $classAttrLines[] = "#[{$ctx->render('Symfony\Component\Serializer\Attribute\Context')}(['skip_null_values' => ".var_export($this->config->skipNullValues, true)."])]\n";
  }
  ```

## Testing

- `SymfonySerializerAttributeStrategyTest.php`: a `dateOnlyFormat: 'd.m.Y'` construction emits
  `#[Context(['datetime_format' => 'd.m.Y'])]` for a `dateOnly` property (was `'Y-m-d'`); a default
  construction still emits `'Y-m-d'` (regression guard); a `dateTimeFormat: \DateTimeInterface::ATOM`
  construction emits the `Context` attribute for a full (non-`dateOnly`) `\DateTimeImmutable`
  property; a default (`dateTimeFormat: null`) construction emits no `Context` attribute for that same
  property (regression guard, matches current behavior).
- `GeneratorTest.php`: a `Config` with `skipNullValues: true` generates a class with
  `#[Context(['skip_null_values' => true])]` above it, alongside `#[ExactlyOneOf]` when the fixture
  also has a choice group (both attributes present, independent); `skipNullValues: null` (default)
  generates no `Context` class attribute at all — byte-identical to current output.
- Byte-identical-output regression check against `tests/fixtures/w3c-purchase-order.xsd` with a
  default-everything `Config`/`SymfonySerializerAttributeStrategy`, same bar Tier 1/Tier 2 used.

## Edge cases

- `dateTimeFormat`'s `\in_array($property->phpType, [...], true)` check is a string comparison against
  the property's rendered PHP type — if a future `typeAliases`/custom-scalar-type-mapping feature
  (Tier 3b or later) ever substitutes a different class for a date-shaped `simpleType`, that
  substituted property would no longer match `\DateTimeImmutable`/`\DateTimeInterface` and correctly
  stops getting a `datetime_format` `Context` (a consumer-supplied value-object type has no defined
  relationship to `symfony/serializer`'s `DateTimeNormalizer` format context) — not a gap, the check is
  already the right guard for that future case.
- A caller composing `SymfonySerializerAttributeStrategy` inside `CompositeAttributeStrategy` alongside
  other strategies is unaffected — the constructor change is purely additive with defaults, no call
  site elsewhere in this package constructs `SymfonySerializerAttributeStrategy` with positional args
  that a new leading/inserted parameter could break (confirm no such call site exists at implementation
  time; if one does, order the two new parameters last, as shown in the API section, to keep any
  existing positional constructor call intact).
