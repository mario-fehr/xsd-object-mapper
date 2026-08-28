# Config options — Tier 1 (clean-generated toggle, per-type alias, strict-mode toggles)

## Why

`Config`'s constructor only takes `xsdPaths`/`namespaceMap`/`attributeStrategy`. `docs/backlog.md`'s
"Config options" section lists 11 config surfaces found by comparing against
`goetas-webservices/xsd2php`, `WsdlToPhp/PackageGenerator`, `janephp/janephp`, and
`makinacorpus/php-xsd-gen` (see `docs/reference-repos.md`), ranked into 4 value-vs-effort tiers. This
spec covers Tier 1 — "cheap, real gap, do first": `clean-generated` purge, per-type alias mapping,
strict-mode missing/colliding-type toggles. All three are additive config fields with no change to
generated-class shape or existing default behavior.

Correction to the backlog wording found while scoping this spec: `clean-generated`'s backlog entry
says "nothing in this generator currently purges the output directory first" — false as of the code
today. `Generator::generate()` already unconditionally wipes every `NamespaceMapping::$outputDir`
before regenerating (`$this->filesystem->remove($mapping->outputDir)`, present since the initial
commit). The real gap is that this is _unconditional_ — no way to opt out for an output dir that also
holds hand-written companion files. Scope here is making it toggleable, not adding the behavior.

## Goals

- `cleanGenerated: bool = true` — output-dir purge in `generate()` becomes conditional on this flag.
  Default preserves current (always-purge) behavior; `false` opts out.
- `typeAliases: array<string, string> = []` — keyed by XSD QName (`"{namespaceURI}#{localName}"`,
  same key shape `Generator` already uses internally), overrides the generated PHP class _name_ (not
  namespace — that stays `NamespaceMapping`'s job) for a specific named `complexType` or named
  `simpleType`-enum. Value is used verbatim as the class's short name, not run through
  `Naming::toClassName()` — consistent with [ADR 0006](../adr/0006-semantic-type-aliasing-is-caller-supplied.md)'s
  precedent of trusting caller-supplied identifiers rather than sanitizing them.
- `typeMissingError: bool = false` — an unresolved named-type reference (a `type="..."` attribute
  naming neither a known `complexType` nor a known `simpleType`, or a `complexContent`/`extension`
  `base="..."` naming an unknown `complexType`) throws `\RuntimeException` instead of silently
  falling back to `string`. Default preserves current permissive behavior.
- `typeOverrideError: bool = false` — two XSD declarations (across the pooled `$xsdPaths`) sharing the
  same `{targetNamespace}#{name}` key for the same construct kind (`complexType`/`simpleType`/
  `attributeGroup`/`group`/`element`) throws `\RuntimeException` instead of the second silently
  overwriting the first in `Generator`'s internal registry. Default preserves current permissive
  behavior, but now always calls `warn()` on the collision (currently: fully silent, no diagnostic at
  all) — same "no silent fallback without a diagnostic" convention `fallbackScalar()`'s docblock
  already states for the type-resolution side.

## Non-goals

- No change to `NamespaceMapping` or how PHP namespaces/output directories are derived — `typeAliases`
  only renames the class's short name within its existing namespace.
- `typeAliases` does not validate its value is a legal PHP identifier, and does not detect two
  different XSD types aliased to the same name within the same namespace (would silently overwrite
  the generated file, same as any other class-name collision today) — caller's responsibility, not a
  new check.
- `typeMissingError`/`typeOverrideError` don't touch the _unrelated_ `$seenGroups` cycle-detection gap
  (`resolveNamedRef()`'s tree-wide-not-per-path scoping, see backlog) or the existing `xs:attribute
ref="..."`/element-ref warn-and-skip paths — those already warn today and are out of scope here.
- No change to any XS-builtin-primitive fallback (e.g. an XSD 1.1-only or otherwise unrecognized
  `xs:*` primitive name still silently maps to `string` via `Naming::xsPrimitiveToPhp()`'s `default`
  arm) — `typeMissingError` is scoped to _named_-type reference misses only, not unrecognized
  XS-namespace primitives.

## API

`src/Config.php` — four new constructor parameters, all with defaults (backward compatible, existing
call sites unaffected):

```php
public function __construct(
    public array $xsdPaths,
    public array $namespaceMap,
    public PropertyAttributeStrategy $attributeStrategy,
    /** @var array<string, string> XSD QName ("{namespaceURI}#{localName}") => PHP class short name */
    public array $typeAliases = [],
    public bool $cleanGenerated = true,
    public bool $typeMissingError = false,
    public bool $typeOverrideError = false,
) {
}
```

## Implementation scope

- **`cleanGenerated`** — `Generator::generate()`, wrap the existing purge loop:
  `if ($this->config->cleanGenerated) { foreach (...) { $this->filesystem->remove(...); } }`.
- **`typeAliases`**:
  - `ensureComplexClass()`: `$className = $this->config->typeAliases[$key] ?? Naming::toClassName($local);`
  - `resolveSimpleTypeRef()`'s enum branch (the `toEnumResult($local, ...)` call): same
    `$this->config->typeAliases[$key] ?? $local` lookup before the call — `ensureEnumClass()` still
    runs `Naming::toClassName()` internally today, so an aliased name must bypass that; simplest fix
    is `ensureEnumClass()` taking the already-resolved class name directly rather than a "simpleType
    name to be sanitized" — confirm exact call shape against the code at implementation time.
- **`typeMissingError`** — new private helper, e.g.:

  ```php
  private function reportMissingType(string $kindLabel, string $local): void
  {
      $message = "unknown {$kindLabel} '{$local}'";
      if ($this->config->typeMissingError) {
          throw new \RuntimeException($message);
      }
      $this->warn($message);
  }
  ```

  Called from:
  - `resolvePrimitiveOrNamedSimpleType()`'s fallback branch, only when `$ns !== self::XS_NS` (a named-
    type reference that resolved to neither a known `complexType` — checked by
    `resolveParticleType()`'s caller — nor a known `simpleType`). An XS-namespace primitive that
    `Naming::xsPrimitiveToPhp()` doesn't recognize stays silent (non-goal above).
  - `collectProperties()`'s `complexContent`/`extension` base lookup (currently: `isset($baseKey,
...)` false means `$baseProperties` just stays `[]`, no diagnostic at all today) — add an `else`
    branch calling `reportMissingType('complexType base', $baseLocal)` when `$baseLocal` is non-empty
    and not `anyType`. This closes a pre-existing silent gap independent of this feature (no warning
    exists today even outside strict mode) — folded in here since `typeMissingError` needs to cover
    this site to be complete, not deferred as a separate backlog item.

- **`typeOverrideError`** — `indexSchemas()`'s combined-query loop, currently:

  ```php
  foreach ($xp->query($query) as $node) {
      $property = self::SCHEMA_NAME_BUCKETS[$node->localName];
      $this->{$property}[$targetNs.'#'.$node->getAttribute('name')] = $node;
  }
  ```

  becomes a collision check before the assignment:

  ```php
  foreach ($xp->query($query) as $node) {
      $property = self::SCHEMA_NAME_BUCKETS[$node->localName];
      $key = $targetNs.'#'.$node->getAttribute('name');
      if (isset($this->{$property}[$key])) {
          $message = "duplicate xs:{$node->localName} '{$key}'";
          if ($this->config->typeOverrideError) {
              throw new \RuntimeException($message);
          }
          $this->warn($message);
      }
      $this->{$property}[$key] = $node;
  }
  ```

  Applies uniformly to all 5 buckets (`complexType`/`simpleType`/`attributeGroup`/`group`/`element`) —
  one shared code path, no reason to special-case by construct kind.

## Testing

Each of the 4 flags gets its own unit test(s) against `GeneratorTest.php`'s existing fixture-based
style:

- `cleanGenerated: false` — a stray file in a configured `outputDir` before `generate()` survives
  after.
- `typeAliases` — a fixture `complexType` and a fixture named-`simpleType` enum each with an alias
  entry generate under the aliased class name (both the emitted file path and the class name inside
  its content).
- `typeMissingError: true` — a fixture referencing an unresolvable named type (`type="..."` naming
  neither an XS primitive nor any known type) throws; a fixture with an unresolvable
  `complexContent`/`extension` `base="..."` throws. Both assert the exception message names the
  missing type.
- `typeOverrideError: true` — two fixture `.xsd` files (pooled via `$xsdPaths`) declaring the same
  `complexType` name in the same namespace throws on `generate()`.
- Each flag's `false`/default path gets a matching test asserting the existing permissive behavior
  (fallback to `string`, silent-but-now-`warn()`-logged overwrite) still holds, so the default-off
  strict flags don't regress the current test suite's fixtures that may already rely on permissive
  behavior — confirm against `tests/fixtures/w3c-purchase-order.xsd` and any other existing fixture
  before assuming none currently trip `typeOverrideError`'s new default `warn()` call.

## Edge cases

- `typeAliases`' verbatim (non-sanitized) value means a caller-supplied alias containing an invalid
  PHP identifier character, a PHP reserved word, or an empty string produces invalid generated code —
  no defensive check added, matching the ADR 0006 precedent of trusting caller-supplied identifiers.
- `typeOverrideError`'s collision check only fires within one `indexSchemas()` pass across the pooled
  `$xsdPaths` — it cannot detect a `complexType` colliding with a `simpleType` of the same QName,
  since they're separate buckets (`self::SCHEMA_NAME_BUCKETS`) by design; XSD itself permits a
  `complexType` and `simpleType`/`element`/`group`/`attributeGroup` to share a local name (different
  symbol spaces), so that's correct, not a gap.
- `reportMissingType()`'s two call sites report different `$kindLabel` strings ("type" vs. "complexType
  base") so the thrown message/warning stays specific about which construct failed to resolve —
  matches this file's existing per-callsite `warn()`/`note()` message style (e.g. `resolveNamedRef()`'s
  `$kindLabel` parameter for group vs. attributeGroup).
