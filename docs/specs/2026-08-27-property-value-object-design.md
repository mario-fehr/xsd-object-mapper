# Property value object

## Why

`Generator::makeProperty()` builds an untyped associative array (`array{phpName: string,
xmlName: ?string, isAttribute: bool, isText: bool, isArray: bool, nullable: bool, kind: string,
phpType: string, dateOnly: bool, facets: array, namedType: ?string, doc: ?string}`) that flows
through `Generator.php`'s `fqType()`/`phpPropertyType()`/`phpDocType()`, all three
`PropertyAttributeStrategy` implementations, and is the parameter/return type of the
`PropertyAttributeStrategy::attributesFor()` interface. PHPStan (`level: max`, set up this
session) can only verify this shape via a repeated inline `@param`/`@return` array-shape
docblock at every boundary — the shape erodes wherever a boundary is typed as plain `array`
(e.g. `makeProperty()`'s own return type, or a test building a partial array literal that skips
required keys with no static check catching it). This is the dominant source of
`phpstan-baseline.neon`'s 219 frozen findings.

`docs/backlog.md`'s "Simplify / code hygiene" section already links this to two other items: the
baseline itself, and `isAttribute`/`isText` being 2 independent booleans instead of 1
enum/string (the 4th, impossible combination isn't structurally excluded). A typed value object
fixes both by construction, and gives any future AST-based-codegen work (the third linked
backlog item, out of scope here) a stable typed model to build from instead of ad-hoc arrays.

## Goals

- Replace the property array with a typed, immutable `Property` value object.
- Replace `isAttribute`/`isText` (2 bools) with a `PropertyRole` enum (`Element`, `Attribute`,
  `Text`) — the 4th, impossible combination becomes unrepresentable.
- Shrink `phpstan-baseline.neon` substantially by letting PHPStan track the real type instead of
  a re-declared array shape at every boundary.
- Change `PropertyAttributeStrategy::attributesFor()`'s signature directly (breaking change) —
  not published to Packagist yet, no external consumers, no need for a compatibility shim.

## Non-goals

- `facets` (the optional XSD-facet sub-array: `length`, `minLength`, `pattern`, ...) stays a
  plain array. Smaller, less central to the baseline than `Property` itself; can be typed
  separately later if it turns out to matter.
- The `list<array{fqcn: string, args: string}>` returned by `attributesFor()` stays a plain
  array shape (an `AttributeSpec` value object was considered and explicitly deferred — YAGNI).
- AST-based code generation (`nikic/php-parser`'s `BuilderFactory` instead of the current string
  concatenation) is a separate, bigger backlog item. This work gives it a better foundation but
  doesn't implement it.
- No new runtime behavior. Pure refactor — output of `Generator::generate()` for a given XSD
  input is unchanged.

## API

`src/PropertyRole.php` (new):

```php
enum PropertyRole
{
    case Element;
    case Attribute;
    case Text;
}
```

`src/Property.php` (new), `final readonly class`, one constructor with named-argument defaults
so call sites (tests especially) can supply only the fields they need, matching today's partial
associative-array ergonomics:

```php
final readonly class Property
{
    public function __construct(
        public string $phpName,
        public ?string $xmlName,
        public PropertyRole $role,
        public bool $isArray = false,
        public bool $nullable = false,
        public string $kind = 'scalar',
        public string $phpType = 'string',
        public bool $dateOnly = false,
        public array $facets = [],
        public ?string $namedType = null,
        public ?string $doc = null,
    ) {
    }
}
```

`PropertyAttributeStrategy::attributesFor()`'s signature changes from
`attributesFor(array $property): array` to `attributesFor(Property $property): array` (return
shape unchanged — out of scope, see Non-goals). The interface's `@param array{...}` docblock is
deleted; the type now carries itself.

## Migration scope

- `src/Property.php`, `src/PropertyRole.php` — new.
- `src/Generator.php` — `makeProperty()` constructs `new Property(...)` instead of an array
  literal; `fqType()`, `phpPropertyType()`, `phpDocType()` take `Property $p` instead of
  `array $p`; every `$p['key']` access becomes `$p->key`.
- `src/Attribute/PropertyAttributeStrategy.php` — signature + docblock as above.
- `src/Attribute/SymfonySerializerAttributeStrategy.php` — the `isText`/`isAttribute` ternary
  chain becomes a `match ($property->role)`.
- `src/Attribute/SymfonyValidatorAttributeStrategy.php`,
  `src/Attribute/SemanticTypeAttributeStrategy.php` — array accesses become property accesses.
- `src/TypeRenderContext.php` — re-check for `$property`/`$p` array usage during
  implementation; none was found during design research, but confirm before/while touching it.
- Test files currently constructing raw property arrays (found during design research:
  `tests/Attribute/SemanticTypeAttributeStrategyTest.php`,
  `tests/SymfonyValidatorAttributeStrategyTest.php`, `tests/GeneratorTest.php`,
  `tests/OfficialSchemaFixtureTest.php`, `tests/ConstructReportToolTest.php`,
  `tests/FixtureDriftToolTest.php`, and any others `grep -rlE "\['phpName'|'isAttribute'|'isText'"
  tests/` turns up at implementation time) — switch to `new Property(...)`.

## Testing

Pure refactor, no new behavior — the existing 66-test suite is the regression guard. Standard
failing-test → implement → passing-test → commit loop per file/unit, per this project's usual
TDD workflow. No new `PropertyTest.php` — the object has no logic of its own (YAGNI).

After the migration, regenerate `phpstan-baseline.neon`
(`vendor/bin/phpstan analyse --generate-baseline`) and confirm the count dropped substantially
from 219. Some findings are unrelated to this shape (e.g. DOM-API-typed findings like
`\DOMNameSpaceNode|DOMNode|null`) and will remain in the baseline — that's expected and out of
scope for this cleanup.

## Edge cases

- Partial test-array literals that previously only set 2-3 keys (relying on PHP's lack of array
  shape enforcement) must now supply every required (non-defaulted) constructor argument, or
  rely on the constructor defaults where the test doesn't care about a field's value. This is a
  feature of the fix, not a complication to work around — it's exactly the class of bug (an
  incomplete/malformed property reaching a strategy silently) this change makes impossible.
- `SymfonySerializerAttributeStrategy`'s `$property['isAttribute'] ? '@'.$xmlName : $xmlName`
  branch becomes a 3-armed `match` (`Element`, `Attribute`, `Text`) — confirm the `Text` arm
  still produces `'#'` and doesn't fall through to the `Element` case's `$xmlName` (which is
  `null` for text properties today; `PropertyRole::Text` makes this explicit instead of
  incidental).
