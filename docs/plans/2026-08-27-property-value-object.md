# Property Value Object Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `Generator::makeProperty()`'s untyped `array{phpName: string, ...}` property
bag with a typed, immutable `Property` value object + `PropertyRole` enum, to shrink
`phpstan-baseline.neon` (219 findings) and resolve the `isAttribute`/`isText`-as-2-booleans
backlog item.

**Architecture:** Two new files (`src/Property.php`, `src/PropertyRole.php`) plus a single
atomic conversion of every array-shaped `$property`/`$p` access across `Generator.php`, the
`PropertyAttributeStrategy` interface, its 4 implementors (3 in `src/Attribute/`, 1 anonymous
test double in `tests/GeneratorTest.php`), and the 1 test file that constructs raw property
arrays directly. PHP's runtime parameter-type enforcement means these can't be converted file by
file with green tests in between - the interface signature and every implementor must change
together.

**Tech Stack:** PHP 8.4, no new dependencies.

**Spec:** `docs/specs/2026-08-27-property-value-object-design.md`

## Global Constraints

- No behavior change. `Generator::generate()`'s output for a given XSD input must be byte-identical
  before and after (verified by the existing 66-test suite, unchanged).
- `facets` (the optional XSD-facet sub-array) and the `list<array{fqcn: string, args: string}>`
  attribute-spec return shape both stay plain arrays - out of scope (see spec's Non-goals).
- `PropertyAttributeStrategy::attributesFor()`'s signature changes directly (breaking change) -
  not published to Packagist, no compatibility shim needed.
- `__srcEl` bookkeeping (Generator.php's choice-branch dedup-survivor tracking) must not become a
  field on `Property` - see Task 2, Step 3 for the object-identity-based replacement.

---

### Task 1: Add `Property` value object and `PropertyRole` enum

**Files:**
- Create: `src/PropertyRole.php`
- Create: `src/Property.php`

**Interfaces:**
- Produces: `Xsd2Php\PropertyRole` (enum, cases `Element`, `Attribute`, `Text`) and
  `Xsd2Php\Property` (final readonly class) - both consumed by every later task in this plan.

- [ ] **Step 1: Create the `PropertyRole` enum**

`src/PropertyRole.php`:

```php
<?php

declare(strict_types=1);

namespace Xsd2Php;

enum PropertyRole
{
    case Element;
    case Attribute;
    case Text;
}
```

- [ ] **Step 2: Create the `Property` value object**

`src/Property.php`:

```php
<?php

declare(strict_types=1);

namespace Xsd2Php;

final readonly class Property
{
    /** @param array{length?: int, minLength?: int, maxLength?: int, pattern?: string, minInclusive?: string, maxInclusive?: string, minExclusive?: string, maxExclusive?: string, totalDigits?: int, fractionDigits?: int} $facets */
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

- [ ] **Step 3: Run the full suite to confirm nothing broke (purely additive so far)**

Run: `vendor/bin/phpunit`
Expected: `OK (66 tests, 306 assertions)` - unchanged, these two files have no consumers yet.

- [ ] **Step 4: Commit**

```bash
git add src/Property.php src/PropertyRole.php
git commit -m "Add Property value object and PropertyRole enum

Not wired in yet - see next commit for the atomic conversion of
Generator.php, PropertyAttributeStrategy, and its implementors."
```

---

### Task 2: Convert `Generator.php`, `PropertyAttributeStrategy`, and all implementors to `Property`

This is one atomic task: PHP enforces parameter types at runtime, so partially converting (e.g.
just the interface, or just one implementor) throws a `TypeError` on the very next test run.
Every sub-step below must land together before the suite is runnable again - do all steps, then
run the suite once at the end.

**Files:**
- Modify: `src/Generator.php` (`makeProperty()`, `collectProperties()`, `fqType()`,
  `phpPropertyType()`, `phpDocType()`, `hasDefault()`, `buildComplexClass()`)
- Modify: `src/Attribute/PropertyAttributeStrategy.php`
- Modify: `src/Attribute/CompositeAttributeStrategy.php`
- Modify: `src/Attribute/SymfonySerializerAttributeStrategy.php`
- Modify: `src/Attribute/SymfonyValidatorAttributeStrategy.php`
- Modify: `src/Attribute/SemanticTypeAttributeStrategy.php`
- Modify: `tests/GeneratorTest.php` (the anonymous `PropertyAttributeStrategy` test double)
- Modify: `tests/Attribute/SemanticTypeAttributeStrategyTest.php` (raw array literals)

**Interfaces:**
- Consumes: `Xsd2Php\Property`, `Xsd2Php\PropertyRole` from Task 1.
- Produces: `PropertyAttributeStrategy::attributesFor(Property $property): array` - the new
  interface signature every future strategy implementation must match.

- [ ] **Step 1: Update `PropertyAttributeStrategy`'s interface signature**

`src/Attribute/PropertyAttributeStrategy.php` - replace the whole file:

```php
<?php

declare(strict_types=1);

namespace Xsd2Php\Attribute;

use Xsd2Php\Property;

/**
 * Extension point: decides which PHP attributes get emitted above a generated
 * constructor-promoted property (e.g. serializer attributes for XML mapping).
 */
interface PropertyAttributeStrategy
{
    /**
     * @return list<array{fqcn: string, args: string}> one entry per attribute to emit; fqcn
     *                                                 is the attribute class without a leading backslash, args the already-rendered PHP
     *                                                 argument list (e.g. "'Foo'" or "['datetime_format' => 'Y-m-d']"). The generator
     *                                                 collects a `use` import per distinct fqcn across the class (falling back to an
     *                                                 inline fully-qualified name only if two different fqcns share a class basename).
     */
    public function attributesFor(Property $property): array;
}
```

- [ ] **Step 2: Update `CompositeAttributeStrategy`**

`src/Attribute/CompositeAttributeStrategy.php` - replace the whole file:

```php
<?php

declare(strict_types=1);

namespace Xsd2Php\Attribute;

use Xsd2Php\Property;

/** Merges the attributesFor() results of multiple strategies, in the order given. */
final readonly class CompositeAttributeStrategy implements PropertyAttributeStrategy
{
    /** @var list<PropertyAttributeStrategy> */
    private array $strategies;

    public function __construct(PropertyAttributeStrategy ...$strategies)
    {
        $this->strategies = $strategies;
    }

    public function attributesFor(Property $property): array
    {
        $attrs = [];
        foreach ($this->strategies as $strategy) {
            $attrs = [...$attrs, ...$strategy->attributesFor($property)];
        }

        return $attrs;
    }
}
```

- [ ] **Step 3: Update `SymfonySerializerAttributeStrategy`**

`src/Attribute/SymfonySerializerAttributeStrategy.php` - replace the whole file:

```php
<?php

declare(strict_types=1);

namespace Xsd2Php\Attribute;

use Xsd2Php\Property;
use Xsd2Php\PropertyRole;

/**
 * Emits symfony/serializer's #[SerializedName]/#[Context] attributes: '@Name' for
 * an xs:attribute, '#' for simpleContent text, bare 'Name' for an xs:element.
 * #[Context(['datetime_format' => 'Y-m-d'])] is added for date-only (not dateTime) properties.
 */
final class SymfonySerializerAttributeStrategy implements PropertyAttributeStrategy
{
    public function attributesFor(Property $property): array
    {
        $serializedName = match ($property->role) {
            PropertyRole::Text => '#',
            PropertyRole::Attribute => '@'.$property->xmlName,
            PropertyRole::Element => $property->xmlName,
        };

        $attrs = [[
            'fqcn' => 'Symfony\Component\Serializer\Attribute\SerializedName',
            'args' => var_export($serializedName, true),
        ]];

        if ($property->dateOnly) {
            $attrs[] = [
                'fqcn' => 'Symfony\Component\Serializer\Attribute\Context',
                'args' => "['datetime_format' => 'Y-m-d']",
            ];
        }

        return $attrs;
    }
}
```

- [ ] **Step 4: Update `SemanticTypeAttributeStrategy`**

`src/Attribute/SemanticTypeAttributeStrategy.php` - replace the whole file:

```php
<?php

declare(strict_types=1);

namespace Xsd2Php\Attribute;

use Xsd2Php\Property;

/**
 * Adds a caller-supplied constraint when a property's directly-referenced named simpleType
 * matches an entry in the alias map - a heuristic keyed by XSD type name (e.g. "EmailType" ->
 * Assert\Email), not something derivable from facets alone. This class knows nothing about any
 * specific schema; the alias map is the caller's decision. Skipped for array properties, same
 * as facet constraints - these validate a single scalar value, not each item.
 */
final readonly class SemanticTypeAttributeStrategy implements PropertyAttributeStrategy
{
    /** @param array<string, array{fqcn: string, args: string}> $aliasMap XSD simpleType local name => constraint to add */
    public function __construct(private array $aliasMap)
    {
    }

    public function attributesFor(Property $property): array
    {
        if ($property->isArray) {
            return [];
        }

        $namedType = $property->namedType;
        if (null === $namedType || !isset($this->aliasMap[$namedType])) {
            return [];
        }

        return [$this->aliasMap[$namedType]];
    }
}
```

- [ ] **Step 5: Update `SymfonyValidatorAttributeStrategy`**

`src/Attribute/SymfonyValidatorAttributeStrategy.php` - the class doc comment at the top
references `$property['facets']`/`$property['kind']`; update those two mentions to
`$property->facets`/`$property->kind` for consistency. Then replace `attributesFor()` and
`presenceConstraint()` (leave `facetConstraints()`/`minMaxArgs()` untouched - they only take the
already-array-typed `$facets`, out of scope):

```php
    public function attributesFor(Property $property): array
    {
        $attrs = $this->presenceConstraint($property);

        if ('class' === $property->kind) {
            $attrs[] = ['fqcn' => \Symfony\Component\Validator\Constraints\Valid::class, 'args' => ''];
        }

        if (!$property->isArray) {
            return [...$attrs, ...$this->facetConstraints($property->facets)];
        }

        return $attrs;
    }

    private function presenceConstraint(Property $property): array
    {
        if ($property->isArray) {
            return $property->nullable ? [] : [[
                'fqcn' => \Symfony\Component\Validator\Constraints\Count::class,
                'args' => 'min: 1',
            ]];
        }

        if ($property->nullable) {
            return [];
        }

        if ('scalar' === $property->kind && 'string' === $property->phpType) {
            return [[
                'fqcn' => \Symfony\Component\Validator\Constraints\NotBlank::class,
                'args' => '',
            ]];
        }

        return [[
            'fqcn' => \Symfony\Component\Validator\Constraints\NotNull::class,
            'args' => '',
        ]];
    }
```

Add `use Xsd2Php\Property;` to the file's `use` block (after `namespace Xsd2Php\Attribute;`).

- [ ] **Step 6: Update `Generator::makeProperty()`**

In `src/Generator.php`, replace the `makeProperty()` method (currently around line 729):

```php
    /** Builds one property-model entry; shared by the simpleContent-value, element, and attribute sites in collectProperties(). */
    private function makeProperty(string $name, PropertyRole $role, bool $isArray, bool $nullable, array $typeInfo, ?string $doc): Property
    {
        return new Property(
            phpName: PropertyRole::Text === $role ? $name : Naming::toPropName($name),
            xmlName: PropertyRole::Text === $role ? null : $name,
            role: $role,
            isArray: $isArray,
            nullable: $nullable,
            kind: $typeInfo['kind'],
            phpType: $typeInfo['phpType'],
            dateOnly: $typeInfo['dateOnly'] ?? false,
            facets: $typeInfo['facets'] ?? [],
            namedType: $typeInfo['namedType'] ?? null,
            doc: $doc,
        );
    }
```

Add `use Xsd2Php\PropertyRole;` is unnecessary (same namespace `Xsd2Php`) - `Property` and
`PropertyRole` are both already in `Generator.php`'s own namespace, no import needed.

- [ ] **Step 7: Update `makeProperty()`'s 3 call sites in `collectProperties()`**

In `src/Generator.php`'s `collectProperties()` method:

The simpleContent-value site (currently `$properties[] = $this->makeProperty('value', false, true, false, false, $baseInfo, null);`):

```php
            $properties[] = $this->makeProperty('value', PropertyRole::Text, false, false, $baseInfo, null);
```

The element site (currently `$prop = $this->makeProperty($name, false, false, $isArray, $nullable, $typeInfo, $doc);`):

```php
                $prop = $this->makeProperty($name, PropertyRole::Element, $isArray, $nullable, $typeInfo, $doc);
```

The attribute site (currently `$properties[] = $this->makeProperty($name, true, false, false, 'required' !== $use, $typeInfo, $doc);`):

```php
            $properties[] = $this->makeProperty($name, PropertyRole::Attribute, false, 'required' !== $use, $typeInfo, $doc);
```

- [ ] **Step 8: Replace the `__srcEl` array mutation with `Property`-object-identity tracking**

Still in `collectProperties()`, immediately after the element-site call from Step 7, the current
code is:

```php
                $prop = $this->makeProperty($name, false, false, $isArray, $nullable, $typeInfo, $doc);
                if ($choiceParticle instanceof \DOMElement) {
                    // bookkeeping only, stripped before the final return - lets the later
                    // dedup-survivor check below tell "this exact choice element survived
                    // de-dup under its phpName" apart from "a same-named non-choice property
                    // (e.g. an xs:attribute) won the de-dup instead".
                    $prop['__srcEl'] = $el;
                }
                $properties[] = $prop;

                if ($choiceParticle instanceof \DOMElement) {
                    $groupKey = spl_object_id($choiceParticle);
                    $choiceGroups[$groupKey] ??= [
                        'particle' => $choiceParticle,
                        'members' => [],
                        'directChildCount' => $this->xpath($choiceParticle->ownerDocument)
                            ->query('xs:element | xs:sequence | xs:choice | xs:all | xs:group', $choiceParticle)->length,
                    ];
                    $choiceGroups[$groupKey]['members'][] = ['phpName' => $prop['phpName'], 'srcEl' => $el];
                }
```

Replace with (drops the `__srcEl` mutation entirely - `$byName[$member['phpName']] === $member['prop']`
in Step 10 below does the same "did this exact property survive dedup" check via object identity,
since `Property` instances are never copied or merged, only replaced wholesale in `$byName`):

```php
                $prop = $this->makeProperty($name, PropertyRole::Element, $isArray, $nullable, $typeInfo, $doc);
                $properties[] = $prop;

                if ($choiceParticle instanceof \DOMElement) {
                    $groupKey = spl_object_id($choiceParticle);
                    $choiceGroups[$groupKey] ??= [
                        'particle' => $choiceParticle,
                        'members' => [],
                        'directChildCount' => $this->xpath($choiceParticle->ownerDocument)
                            ->query('xs:element | xs:sequence | xs:choice | xs:all | xs:group', $choiceParticle)->length,
                    ];
                    // $prop's own identity (not a separately-tracked DOM node) is what the
                    // dedup-survivor check below compares against - "did this exact Property
                    // instance survive dedup under its phpName" tells apart "a same-named
                    // non-choice property (e.g. an xs:attribute) won the de-dup instead",
                    // without Property itself needing to carry DOM bookkeeping.
                    $choiceGroups[$groupKey]['members'][] = ['phpName' => $prop->phpName, 'prop' => $prop];
                }
```

Also update the `@var` docblock immediately above the `$choiceGroups = [];` declaration (a few
lines earlier in the same method) from:

```php
        /** @var array<int, array{particle: \DOMElement, members: array{phpName: string, srcEl: \DOMElement}[], directChildCount: int}> keyed by spl_object_id() of the enclosing xs:choice particle */
```

to:

```php
        /** @var array<int, array{particle: \DOMElement, members: array{phpName: string, prop: Property}[], directChildCount: int}> keyed by spl_object_id() of the enclosing xs:choice particle */
```

- [ ] **Step 9: Update `makeProperty()`'s attribute-site call and the `$byName` dedup loop**

The attribute-collection `foreach` loop's `makeProperty()` call becomes the Step 7 attribute-site
snippet above (already covered). Just after it, the dedup loop:

```php
        // de-dup by phpName, last one wins (own properties override inherited base ones with same name)
        $byName = [];
        foreach ($properties as $p) {
            $byName[$p['phpName']] = $p;
        }
```

becomes:

```php
        // de-dup by phpName, last one wins (own properties override inherited base ones with same name)
        $byName = [];
        foreach ($properties as $p) {
            $byName[$p->phpName] = $p;
        }
```

- [ ] **Step 10: Update the choice-group survivor check and drop the final `__srcEl` strip**

The survivor-check loop (inside the `foreach ($choiceGroups as $group)` block, building
`$exactlyOneOfGroups`):

```php
            $names = [];
            foreach ($group['members'] as $member) {
                if (($byName[$member['phpName']]['__srcEl'] ?? null) === $member['srcEl']) {
                    $names[] = $member['phpName'];
                }
            }
```

becomes:

```php
            $names = [];
            foreach ($group['members'] as $member) {
                if (($byName[$member['phpName']] ?? null) === $member['prop']) {
                    $names[] = $member['phpName'];
                }
            }
```

And the method's final lines:

```php
        $properties = array_values($byName);
        foreach ($properties as &$p) {
            unset($p['__srcEl']);
        }
        unset($p);

        return ['properties' => $properties, 'choiceGroups' => $exactlyOneOfGroups];
```

become (no stripping needed - `Property` never carried the bookkeeping key to begin with):

```php
        return ['properties' => array_values($byName), 'choiceGroups' => $exactlyOneOfGroups];
```

- [ ] **Step 11: Update `fqType()`, `phpPropertyType()`, `phpDocType()`, `hasDefault()`**

In `src/Generator.php`, replace all four methods:

```php
    private function fqType(Property $p, TypeRenderContext $ctx): string
    {
        if (!\in_array($p->kind, ['class', 'enum'], true)) {
            return $p->phpType;
        }

        return $ctx->render($p->phpType);
    }

    private function phpPropertyType(Property $p, TypeRenderContext $ctx): string
    {
        if ($p->isArray) {
            return 'array';
        }

        return ($p->nullable ? '?' : '').$this->fqType($p, $ctx);
    }

    private function phpDocType(Property $p, TypeRenderContext $ctx): string
    {
        $type = $this->fqType($p, $ctx);

        return $p->isArray ? $type.'[]' : $type;
    }

    private function hasDefault(Property $p): bool
    {
        return $p->isArray || $p->nullable;
    }
```

- [ ] **Step 12: Update `buildComplexClass()`'s property-array accesses**

In `src/Generator.php`'s `buildComplexClass()` method:

The sort callback (currently `usort($properties, fn (array $a, array $b): int => $this->hasDefault($a) <=> $this->hasDefault($b));`):

```php
        usort($properties, fn (Property $a, Property $b): int => $this->hasDefault($a) <=> $this->hasDefault($b));
```

The type-import-collection loop:

```php
        foreach ($properties as $p) {
            if (!\in_array($p->kind, ['class', 'enum'], true)) {
                continue;
            }
            $fqcn = $p->phpType;
            if (substr($fqcn, 0, strrpos($fqcn, '\\')) === $namespace) {
                $sameNamespaceTypes[$fqcn] = true;
                continue;
            }
            $imports[$fqcn] ??= Naming::basename($fqcn);
        }
```

The constructor-line-building loop:

```php
        $ctorLines = [];
        foreach ($properties as $i => $p) {
            $type = $this->phpPropertyType($p, $ctx);
            $default = $p->isArray ? ' = []' : ($p->nullable ? ' = null' : '');

            $doc = null !== $p->doc ? str_replace('*/', '* /', $p->doc) : null;
            if ($p->isArray) {
                // symfony/property-info's PhpDocExtractor needs an explicit @var tag (PHP has no
                // generics) to resolve the array item type for denormalization.
                $ctorLines[] = '        /** @var '.$this->phpDocType($p, $ctx).(null !== $doc ? " {$doc}" : '').' */';
            } elseif (null !== $doc) {
                $ctorLines[] = "        /** {$doc} */";
            }
            foreach ($propertyAttrs[$i] as $attr) {
                $rendered = $ctx->render($attr['fqcn']);
                $ctorLines[] = "        #[{$rendered}({$attr['args']})]";
            }
            $ctorLines[] = "        public {$type} \${$p->phpName}{$default},";
            $ctorLines[] = '';
        }
```

- [ ] **Step 13: Update `tests/GeneratorTest.php`'s anonymous `PropertyAttributeStrategy` test double**

Add `use Xsd2Php\Property;` to the `use` block at the top of the file (alongside the existing
`use Xsd2Php\Attribute\PropertyAttributeStrategy;` etc.).

Change (around line 296):

```php
        $this->generate(new class implements PropertyAttributeStrategy {
            public function attributesFor(array $property): array
            {
                return [
                    ['fqcn' => 'Vendor\\One\\Marker', 'args' => ''],
                    ['fqcn' => 'Vendor\\Two\\Marker', 'args' => ''],
                ];
            }
        });
```

to:

```php
        $this->generate(new class implements PropertyAttributeStrategy {
            public function attributesFor(Property $property): array
            {
                return [
                    ['fqcn' => 'Vendor\\One\\Marker', 'args' => ''],
                    ['fqcn' => 'Vendor\\Two\\Marker', 'args' => ''],
                ];
            }
        });
```

- [ ] **Step 14: Update `tests/Attribute/SemanticTypeAttributeStrategyTest.php`'s raw array literals**

Add `use Xsd2Php\Property;` and `use Xsd2Php\PropertyRole;` to the `use` block at the top of the
file.

Change the 3 tests currently building raw property arrays:

```php
    public function testEmitsTheAliasedConstraintWhenNamedTypeMatches(): void
    {
        $strategy = new SemanticTypeAttributeStrategy(self::ALIAS_MAP);

        $property = new Property(phpName: 'Email', xmlName: 'Email', role: PropertyRole::Element, namedType: 'EmailType');

        $this->assertSame([['fqcn' => Email::class, 'args' => '']], $strategy->attributesFor($property));
    }

    public function testEmitsNothingWhenNamedTypeIsUnmapped(): void
    {
        $strategy = new SemanticTypeAttributeStrategy(self::ALIAS_MAP);

        $this->assertSame([], $strategy->attributesFor(new Property(phpName: 'Email', xmlName: 'Email', role: PropertyRole::Element, namedType: 'SomeOtherType')));
        $this->assertSame([], $strategy->attributesFor(new Property(phpName: 'Email', xmlName: 'Email', role: PropertyRole::Element, namedType: null)));
    }

    public function testSkipsArrayPropertiesEvenWhenNamedTypeMatches(): void
    {
        $strategy = new SemanticTypeAttributeStrategy(self::ALIAS_MAP);

        $this->assertSame([], $strategy->attributesFor(new Property(phpName: 'Email', xmlName: 'Email', role: PropertyRole::Element, isArray: true, namedType: 'EmailType')));
    }
```

(`testAliasedConstraintsAreActuallyEnforcedAtRuntime()`, the 4th test in this file, goes through
the real `Generator`/`Config` and never constructs a raw property array or `Property` directly -
leave it untouched.)

- [ ] **Step 15: Run the full suite - this is the first point since Step 1 where it can pass**

Run: `vendor/bin/phpunit`
Expected: `OK (66 tests, 306 assertions)` - identical count to before this task, no behavior
change. If anything fails, the failure is in this task's own conversion (a missed `$p['...']`
access or mismatched constructor argument), not pre-existing - fix before proceeding.

- [ ] **Step 16: Commit**

```bash
git add src/Generator.php src/Attribute/PropertyAttributeStrategy.php \
  src/Attribute/CompositeAttributeStrategy.php src/Attribute/SymfonySerializerAttributeStrategy.php \
  src/Attribute/SymfonyValidatorAttributeStrategy.php src/Attribute/SemanticTypeAttributeStrategy.php \
  tests/GeneratorTest.php tests/Attribute/SemanticTypeAttributeStrategyTest.php
git commit -m "Convert Generator + PropertyAttributeStrategy to Property value object

isAttribute/isText (2 bools, 4th impossible combination not
structurally excluded) become PropertyRole (Element/Attribute/Text).
__srcEl's array-mutation bookkeeping in collectProperties() (added
then stripped, tracking which choice-branch DOM element a property
came from) is replaced by comparing Property object identity directly
- Property never carries DOM state."
```

---

### Task 3: Regenerate the PHPStan baseline and verify

**Files:**
- Modify: `phpstan-baseline.neon`

**Interfaces:**
- Consumes: nothing new - this task only re-runs existing tooling from the phpstan-baseline
  commit set up earlier this session.

- [ ] **Step 1: Run PHPStan without a baseline update first, to see the new count**

Run: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
Expected: some number of errors (should be noticeably lower than 219, since most findings traced
back to the untyped property-bag shape this plan just eliminated). Note the count.

- [ ] **Step 2: Regenerate the baseline**

Run: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G --generate-baseline=phpstan-baseline.neon`
Expected: `[OK] Baseline generated with N errors.` where N is well under 219. If N is not
meaningfully lower than 219 (e.g. still 150+), stop and investigate before proceeding - the
conversion likely missed a boundary and a residual array-shape docblock somewhere is still
masking the real gain; re-check the "Consumes"/"Produces" signatures against Task 2's file list
rather than just accepting the number.

- [ ] **Step 3: Confirm PHPStan is clean against the new baseline**

Run: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
Expected: `[OK] No errors`

- [ ] **Step 4: Run the rest of the quality suite to confirm nothing else regressed**

Run: `vendor/bin/phpunit && vendor/bin/php-cs-fixer fix --dry-run --diff && vendor/bin/rector process --dry-run && composer deps-check`
Expected: PHPUnit `OK (66 tests, 306 assertions)`; CS-Fixer, Rector, and the dependency analyser
all report no changes/issues (Task 2's new code should already match existing style/rule
conventions, since it follows the surrounding code's patterns).

- [ ] **Step 5: Update `docs/backlog.md`**

In `docs/backlog.md`'s "Simplify / code hygiene" section, remove the now-resolved
`makeProperty()` isAttribute/isText bullet and the `phpstan-baseline.neon` bullet entirely (both
describe a problem this plan just fixed), and add one line to the "Resolved" section:

```markdown
- **`makeProperty()`'s untyped array property bag** - replaced with a `Property` value object +
  `PropertyRole` enum (`Element`/`Attribute`/`Text` instead of 2 independent `isAttribute`/
  `isText` booleans, so the 4th impossible combination is now structurally unrepresentable).
  Shrunk `phpstan-baseline.neon` from 219 to N findings (see
  `docs/specs/2026-08-27-property-value-object-design.md`).
```

Replace `N` with the actual number from Step 2.

- [ ] **Step 6: Commit**

```bash
git add phpstan-baseline.neon docs/backlog.md
git commit -m "Regenerate PHPStan baseline after Property value object cleanup

Baseline dropped from 219 to N findings - see
docs/specs/2026-08-27-property-value-object-design.md."
```
