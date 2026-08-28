# PHPStan baseline to zero

## Why

`phpstan-baseline.neon` currently freezes 175 findings (106 distinct entries) at `level: max`.
It exists because two earlier sessions' worth of static-analysis adoption and the
[Property value object refactor](2026-08-27-property-value-object-design.md) deliberately
deferred everything not directly in scope, rather than block on a full clean-up. `docs/backlog.md`'s
"Static analysis" section already names the two biggest remaining categories. This spec covers
eliminating the baseline entirely — `phpstan-baseline.neon` deleted, `phpstan.dist.neon`'s
`includes:` line removed, `vendor/bin/phpstan analyse` clean with no baseline at all.

Categorized by root cause (see `docs/backlog.md`'s "Static analysis" section for the count method):

- **A. `bin/` scripts (23 entries, `check-fixture-drift.php` + `xsd-construct-report.php`)** —
  `json_decode()`'s result used as `mixed` with no boundary check, `main(array $argv)`'s
  parameter with no value type, `RecursiveIteratorIterator`'s yielded value used without an
  `\SplFileInfo` guard, a `\DOMNodeList` accessed without a `false`-guard.
- **B. The untyped `$typeInfo` array (~40-50 entries, mostly `Generator.php`)** — the
  `array{kind: string, phpType: string, dateOnly?: bool, facets?: array, namedType?: string}`
  shape `resolveSimpleTypeRef()`/`resolvePrimitiveOrNamedSimpleType()`/`resolveParticleType()`/
  `mergeFacets()`/`toEnumResult()`/`fallbackScalar()`/`self::STRING_FALLBACK` all produce and
  consume — the same class of problem the `Property` value object fixed for the property-bag
  shape, not yet applied to this one.
- **C. Raw DOM `NodeList` iteration (25 entries, all `Generator.php` + 1 in
  `xsd-construct-report.php`)** — `$xp->query(...)` returns `\DOMNodeList<\DOMNameSpaceNode|
\DOMNode>|false` per PHPStan's `ext-dom` stubs; a `foreach` over it yields
  `\DOMNameSpaceNode|\DOMNode`, not `\DOMElement`. The codebase already uses an
  `instanceof \DOMElement` guard at some call sites (e.g. `$ext instanceof \DOMElement` in
  `collectProperties()`) — these 25 are the remaining sites where that same, already-established
  idiom is missing.
- **D. Everything else (~8 entries)** — `Naming.php`'s `substr()`/`strrchr()` results used
  without a `false`-guard, and a handful of test-file findings (`ReflectionClass`'s generic
  parameter, `assertMatchesRegularExpression()`'s `string|false` argument,
  `CompositeAttributeStrategy`'s `list<...>` variadic-to-property assignment, an anonymous test
  class's untyped `$items` property).

## Goals

- `vendor/bin/phpstan analyse` reports `[OK] No errors` with `phpstan-baseline.neon` deleted and
  `phpstan.dist.neon`'s `includes:` section removed.
- No behavior change to `Generator::generate()`'s output for any given XSD input, or to any
  `bin/` script's output for any given input — verified by the existing test suite plus, where a
  fix touches DOM traversal, running the generator against `tests/fixtures/w3c-purchase-order.xsd`
  and diffing generated output before/after.
- Introduce `TypeInfo` (value object) + `TypeKind` (enum: `Scalar`, `Class`, `Enum`) mirroring
  this session's `Property`/`PropertyRole` pattern, replacing the `$typeInfo` array shape
  end-to-end.
- Apply the codebase's existing `instanceof \DOMElement` guard idiom to every remaining raw
  `\DOMNodeList` iteration site instead of introducing a new pattern (e.g. no PHPStan `ext-dom`
  stub package, no blanket `@phpstan-ignore` suppression).

## Non-goals

- No new PHPStan rules/level increase beyond the already-configured `level: max`.
- No behavior change or new XSD-construct support — this is purely a type-safety pass over
  existing logic paths.
- `facets`' own shape (`array{length?: int, minLength?: int, ...}`) stays a plain array, same as
  the `Property` refactor's decision — it becomes a field _of_ `TypeInfo`, not its own object.
- Cluster D's test-file findings get the minimal fix each needs (an explicit cast, an
  `assert()`, an `array_values()`) — no broader test-file refactor.

## API

`src/TypeKind.php` (new):

```php
enum TypeKind
{
    case Scalar;
    case Class_;
    case Enum;
}
```

(`Class_` with a trailing underscore — `class` is a reserved word and cannot be a case name.)

`src/TypeInfo.php` (new), `final readonly class`, mirroring `Property`'s constructor-with-defaults
shape for the same test/call-site ergonomics:

```php
final readonly class TypeInfo
{
    /** @param array{length?: int, minLength?: int, maxLength?: int, pattern?: string, minInclusive?: string, maxInclusive?: string, minExclusive?: string, maxExclusive?: string, totalDigits?: int, fractionDigits?: int} $facets */
    public function __construct(
        public TypeKind $kind,
        public string $phpType,
        public bool $dateOnly = false,
        public array $facets = [],
        public ?string $namedType = null,
    ) {
    }
}
```

Every `Generator` method currently typed to return the `$typeInfo` array (`resolveSimpleTypeRef()`,
`resolvePrimitiveOrNamedSimpleType()`, `resolveParticleType()`, `toEnumResult()`, `fallbackScalar()`,
`mergeFacets()`) returns `TypeInfo` instead. `self::STRING_FALLBACK` becomes a `TypeInfo` constant
instance. `makeProperty(string $name, PropertyRole $role, bool $isArray, bool $nullable,
TypeInfo $typeInfo, ?string $doc): Property` takes `TypeInfo` directly (was `array $typeInfo`) and
maps its fields onto `Property`'s matching constructor arguments — `'scalar'`/`'class'`/`'enum'`
string comparisons elsewhere (`Generator::fqType()`, `SymfonyValidatorAttributeStrategy`) become
`match`/`===` on `TypeKind` instead. `Property::$kind`/`$phpType` stay plain `string` (per the
existing `Property` design from this session) — `TypeKind` is consumed at the `TypeInfo` ->
`Property` boundary in `makeProperty()`, not threaded further.

No other public interface changes. `PropertyAttributeStrategy::attributesFor(Property $property)`
is unaffected — `Property::$kind` staying a string means no second breaking interface change.

## Migration scope

Exact file/line list is deferred to the implementation plan (this spec fixes the approach, not
every call site) — the plan's own research pass re-verifies line numbers against the code at
implementation time, the same way the Property refactor's plan did.

- **Cluster A** (`bin/check-fixture-drift.php`, `bin/xsd-construct-report.php`): add an
  `is_array()` check immediately after each `json_decode(..., true, flags: JSON_THROW_ON_ERROR)`
  call (throwing a clear error on a valid-but-non-array JSON document, e.g. a bare JSON string or
  number at the top level); type `main()`'s `$argv` parameter as `list<string>` via a docblock;
  guard the `\RecursiveIteratorIterator` loop variable with `instanceof \SplFileInfo`; guard the
  `\DOMNodeList` access in `xsd-construct-report.php` the same way Cluster C does elsewhere.
- **Cluster B**: `src/TypeKind.php`, `src/TypeInfo.php` new. `src/Generator.php`'s
  `resolveSimpleTypeRef()`, `resolvePrimitiveOrNamedSimpleType()`, `resolveParticleType()`,
  `toEnumResult()`, `fallbackScalar()`, `mergeFacets()`, `makeProperty()`, `self::STRING_FALLBACK`,
  and every `$typeInfo['key']`/`$baseInfo['key']`/`$base['key']` array access across those methods.
  `src/Attribute/SymfonyValidatorAttributeStrategy.php`'s `'class' === $property->kind` /
  `'scalar' === $property->kind` string comparisons are unaffected (they compare `Property::$kind`,
  a plain string per the Non-goals above) — confirm this at implementation time rather than assume.
- **Cluster C**: every raw `foreach ($xp->query(...) as $x)` in `Generator.php` gets an
  `instanceof \DOMElement` guard (skip/`continue` + a `warn()` call on the non-element case,
  matching this file's existing warn-and-skip convention elsewhere, e.g. `collectAttributes()`'s
  unknown-ref handling) if it doesn't already have one; `resolveQName()`'s `$contextNode` parameter
  and `xpath()`'s `$doc` parameter call sites get the same treatment where they currently receive
  an unguarded `\DOMNode`/`?\DOMDocument`; `ensureEnumClass()`/`toEnumResult()`'s `$enumerations`
  parameter gets a concrete `\DOMNodeList<\DOMElement>` docblock type once its only caller
  (`resolveSimpleTypeRef()`'s `$xp->query('xs:enumeration', $restriction)`) is itself guarded.
- **Cluster D**: `Naming.php`'s `substr()`/`strrchr()`/`splitQName()` results get an explicit
  `false`-check or a cast justified by a preceding guard; `GeneratorTest.php`'s `ReflectionClass`
  usage gets a `@var class-string<...>` hint; `SymfonyValidatorAttributeStrategyTest.php`'s
  `assertMatchesRegularExpression()` call gets its `string|false` argument narrowed (the value
  comes from a `preg_replace()`/similar call — confirm and guard rather than blindly cast);
  `CompositeAttributeStrategy`'s `list<PropertyAttributeStrategy>` property assignment from a
  variadic constructor gets `array_values(...)` or an equivalent narrowing;
  `ExactlyOneOfValidatorTest.php`'s anonymous class gets an explicit `@var list<mixed>` (or more
  specific, if the test's own logic implies a narrower type) on its untyped `$items` property.
- **Final step**: delete `phpstan-baseline.neon`; remove `phpstan.dist.neon`'s `includes:` block;
  update `docs/backlog.md`'s "Static analysis" section (move to "Resolved" or delete, matching
  how the Property refactor's plan handled its own backlog entry).

## Testing

Existing 72-test suite is the primary regression guard, same as the Property refactor. Because
Cluster C touches DOM traversal logic (adding `instanceof` guards around already-well-formed-XSD
assumptions), additionally diff `Generator::generate()`'s output for
`tests/fixtures/w3c-purchase-order.xsd` before and after each DOM-related change lands, the same
verification method the Property refactor's final review used — byte-identical output is the bar,
not merely "tests still pass" (the existing suite may not exercise every particle/attribute-group/
enum code path Cluster C touches). No new test files expected — TDD within each task follows the
existing failing-test -> implement -> passing-test loop against `Generator.php`'s current test
coverage; add a targeted regression test only if a Cluster C guard's `warn()`-and-skip branch
turns out to be unreachable by any existing fixture (verify with code coverage or a deliberate
malformed-input test, not by assumption).

## Edge cases

- Cluster C's `instanceof \DOMElement` guards are defensive against inputs PHPStan can't rule
  out statically (a `\DOMNameSpaceNode`, or `\DOMText`/`\DOMComment` mixed into a NodeList) but
  that a well-formed XSD schema should never actually produce at these specific query sites
  (`xs:sequence | xs:choice | xs:all` results, `xs:attribute`/`xs:attributeGroup` results, etc.
  are schema-constrained by the queries' own XPath expressions). The guard's failure branch
  should `warn()` and skip (matching the rest of the file's error-handling convention) rather
  than throw — consistent with this generator's overall philosophy of degrading gracefully on
  unexpected schema shapes instead of crashing.
- `self::STRING_FALLBACK` (currently `['kind' => 'scalar', 'phpType' => 'string', 'dateOnly' =>
false]`) becoming a `TypeInfo` constant needs PHP 8.4's ability to use an object as a class
  constant's default value where the object is composed entirely of other constants/literals
  (readonly class instantiation in a constant context) — confirm this compiles as expected during
  Cluster B's implementation; if it doesn't, a `private static function stringFallback(): TypeInfo`
  factory method is the fallback design, not a blocker for the approach.
- `mergeFacets(TypeInfo $typeInfo, \DOMElement $restriction): TypeInfo` needs to return a _new_
  `TypeInfo` with merged facets (immutable value object, no in-place mutation) rather than the
  current array-merge-and-return pattern — straightforward but worth calling out since it's a
  `with`-style reconstruction, not a simple field access change like most of Cluster B.
