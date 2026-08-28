# PHPStan Baseline to Zero Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate `phpstan-baseline.neon` entirely (175 findings / 106 entries at `level: max`) — file deleted, `phpstan.dist.neon`'s `includes:` block removed, `vendor/bin/phpstan analyse` reports `[OK] No errors` with no baseline at all.

**Architecture:** Fix every finding at its root cause, grouped into small, independently-testable, dependency-ordered tasks. Two new value objects (`TypeKind` enum, `TypeInfo` readonly class) replace the untyped `$typeInfo` array shape threaded through `../../src/Generator.php`'s type-resolution methods, mirroring the existing `Property`/`PropertyRole` pattern. Every remaining raw `\DOMNodeList` iteration site gets the codebase's existing `instanceof \DOMElement` guard idiom (warn-and-skip). Two new small private helpers on `Generator` (`query()`, `ownerDocOf()`) remove repetitive `\DOMNodeList|false`/`?\DOMDocument` guard boilerplate at ~20 call sites.

**Tech Stack:** PHP 8.4, PHPStan 2.2 (`level: max`), PHPUnit.

**Spec:** `../specs/2026-08-27-phpstan-baseline-zero-design.md`

## Global Constraints

- No behavior change to `Generator::generate()`'s output for any given XSD input, or to any `../../bin` script's output for any given input.
- `vendor/bin/phpstan analyse` must report `[OK] No errors` once the baseline is deleted (final task) — every task before that must keep `composer phpstan` passing against a **shrinking** baseline (see Standard Verification Loop below).
- `TypeInfo`/`TypeKind` mirror `Property`/`PropertyRole`: `final readonly class` with constructor-with-defaults, enum with plain cases (`Class_` — trailing underscore, `class` is reserved).
- `facets` stays a plain array (not its own object) — it becomes a field _of_ `TypeInfo`.
- `Property::$kind`/`$phpType` stay plain `string` — `TypeKind` is consumed at the `TypeInfo` -> `Property` boundary in `makeProperty()` only, never threaded further (confirmed: `Generator::fqType()` L771, `Generator::buildComplexClass()` L823, and `SymfonyValidatorAttributeStrategy.php` L45/L69 all compare `Property::$kind`, a plain string — untouched by this migration).
- No new PHPStan rules or level increase.
- No new test files, unless a Cluster-C `warn()`-and-skip guard branch turns out to be reachable by an existing fixture (verify, don't assume) — none of the guards added by this plan are expected to be reachable by any well-formed XSD, since every guarded site is schema-constrained by its own XPath expression or by the XSD spec's own structural rules.

### Standard Verification Loop

Every task below ends with this exact sequence (task-specific additions are called out where they apply):

```bash
composer test                                  # full suite green
vendor/bin/phpstan analyse --generate-baseline # shrinks phpstan-baseline.neon in place
git diff phpstan-baseline.neon                 # eyeball: only the intended entries disappeared, nothing new appeared
composer test                                  # green again (baseline regen doesn't touch code, but re-run for safety)
```

`vendor/bin/phpstan analyse --generate-baseline` was verified at plan-writing time to regenerate `phpstan-baseline.neon` byte-identical to the current committed file when no code has changed (175 errors) — safe to run after every task's fix to shrink it by exactly the entries that task resolved. If `git diff phpstan-baseline.neon` shows anything **added** (a new message/path pair, or a higher count for an existing one), the fix introduced a new PHPStan issue — stop and fix it before committing.

Live, ground-truth PHPStan error counts per file (verified at plan-writing time by running `vendor/bin/phpstan analyse` with the `includes:` line stripped — i.e. ignoring the baseline entirely): `../../src/Generator.php` 104, `../../tests/SymfonyValidatorAttributeStrategyTest.php` 20, `../../bin/xsd-construct-report.php` 15, `../../bin/check-fixture-drift.php` 10, `../../src/Attribute/SymfonyValidatorAttributeStrategy.php` 7, `../../tests/FixtureDriftToolTest.php` 6, `../../tests/GeneratorTest.php` 4, `../../src/Naming.php` 3, `../../tests/Validator/ExactlyOneOfValidatorTest.php` 2, `../../tests/Attribute/SemanticTypeAttributeStrategyTest.php` 2, `../../tests/ConstructReportToolTest.php` 1, `../../src/Attribute/CompositeAttributeStrategy.php` 1. Total 175, matching the committed baseline exactly (confirms the baseline is currently in sync with the code, not stale).

### DOM-touching-task fixture diff (tasks 7, 8, 9, 10 only)

Before making any edit in one of these tasks, capture a snapshot; after the edit (before committing), capture another and diff them — must be empty:

```bash
rm -rf /tmp/xsd2php-diff-before /tmp/xsd2php-diff-after
mkdir -p /tmp/xsd2php-diff-before /tmp/xsd2php-diff-after
php -r '
require __DIR__."/vendor/autoload.php";
use Xsd2Php\Config;
use Xsd2Php\Generator;
use Xsd2Php\NamespaceMapping;
use Xsd2Php\Attribute\SymfonySerializerAttributeStrategy;
$config = new Config(
    xsdPaths: [__DIR__."/tests/fixtures/w3c-purchase-order.xsd"],
    namespaceMap: ["" => new NamespaceMapping("PurchaseOrder", $argv[1])],
    attributeStrategy: new SymfonySerializerAttributeStrategy(),
);
new Generator($config)->generate();
' /tmp/xsd2php-diff-before
# ... make the edit ...
php -r '/* identical script as above */' /tmp/xsd2php-diff-after
diff -r /tmp/xsd2php-diff-before /tmp/xsd2php-diff-after   # expect no output
```

---

### Task 1: `../../bin/check-fixture-drift.php` type-safety fixes (Cluster A)

**Files:**

- Modify: `../../bin/check-fixture-drift.php`

**Interfaces:** None — standalone CLI script, no other file depends on its internals.

Ground-truth errors (10): `currentCounts()` untyped return (L21), `main()` untyped `$argv` param (L37), `json_decode()` on `file_get_contents()`'s `string|false` (L49), 3 cascading `mixed` errors from the untyped `$baseline` this produces (L53, L59, L60), 2 `sprintf` `mixed` args (L75, L75 — cascades from the same `$baseline` typing), bare `$argv` possibly undefined at the trailer (L90).

- [ ] **Step 1: Confirm current behavior via the existing subprocess test**

Run: `vendor/bin/phpunit tests/FixtureDriftToolTest.php`
Expected: PASS (this test exercises the script as a real subprocess — it's the regression guard for this task, not a new test).

- [ ] **Step 2: Apply the fix**

Edit `../../bin/check-fixture-drift.php` — full new contents:

```php
<?php

declare(strict_types=1);

/**
 * Re-counts every XSD construct (via xsd-construct-report.php) in this package's own bundled test
 * fixtures (tests/fixtures/) and diffs it against the checked-in baseline
 * (tests/fixtures/coverage-baseline.json). A drift usually means a fixture file was added, removed,
 * or edited - this makes that change visible instead of silent, and flags it especially clearly
 * when a construct that used to be absent from the fixtures (0) is now present, since that changes
 * what construct-coverage.md's "synthetic test" claims are actually backed by.
 *
 * Usage:
 *   php check-fixture-drift.php                 # report drift, exit 1 if any found
 *   php check-fixture-drift.php --update-baseline # after reviewing drift, record new counts
 */
const FIXTURES_DIR = __DIR__.'/../tests/fixtures';
const REPORT_TOOL = __DIR__.'/xsd-construct-report.php';
const BASELINE_FILE = __DIR__.'/../tests/fixtures/coverage-baseline.json';

/** @return array<string, int> */
function currentCounts(): array
{
    $json = shell_exec('php '.escapeshellarg(REPORT_TOOL).' '.escapeshellarg(FIXTURES_DIR).' --json');
    if (null === $json || false === $json) {
        fwrite(\STDERR, "Failed to run xsd-construct-report.php.\n");
        exit(1);
    }
    $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        fwrite(\STDERR, "Unexpected report tool output.\n");
        exit(1);
    }
    /** @var array<string, int> $decoded */

    return $decoded;
}

/** @param list<string> $argv */
function main(array $argv): int
{
    $updateBaseline = in_array('--update-baseline', array_slice($argv, 1), true);
    $current = currentCounts();

    if (!file_exists(BASELINE_FILE)) {
        fwrite(\STDOUT, 'No baseline yet at '.BASELINE_FILE." - writing the current counts as the initial baseline.\n");
        file_put_contents(BASELINE_FILE, json_encode($current, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

        return 0;
    }

    $baselineJson = file_get_contents(BASELINE_FILE);
    if (false === $baselineJson) {
        fwrite(\STDERR, 'Failed to read '.BASELINE_FILE.".\n");
        exit(1);
    }
    $baseline = json_decode($baselineJson, true, flags: \JSON_THROW_ON_ERROR);
    if (!is_array($baseline)) {
        fwrite(\STDERR, "Unexpected baseline file contents.\n");
        exit(1);
    }
    /** @var array<string, int> $baseline */

    $changed = [];
    foreach ($current as $label => $count) {
        $before = $baseline[$label] ?? 0;
        if ($before !== $count) {
            $changed[$label] = ['before' => $before, 'after' => $count];
        }
    }
    foreach ($baseline as $label => $before) {
        if (!array_key_exists($label, $current)) {
            $changed[$label] = ['before' => $before, 'after' => null];
        }
    }

    if ([] === $changed) {
        fwrite(\STDOUT, "No drift - tests/fixtures/*.xsd construct usage matches the recorded baseline.\n");

        return 0;
    }

    fwrite(\STDOUT, 'Construct usage drift detected against '.BASELINE_FILE.":\n\n");
    foreach ($changed as $label => $diff) {
        $before = $diff['before'];
        $after = $diff['after'] ?? 'removed from report tool';
        $flag = 0 === $before && is_int($diff['after']) && $diff['after'] > 0 ? '  <-- newly exercised by a fixture' : '';
        fwrite(\STDOUT, sprintf("  %-40s %s -> %s%s\n", $label, $before, $after, $flag));
    }

    if ($updateBaseline) {
        file_put_contents(BASELINE_FILE, json_encode($current, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
        fwrite(\STDOUT, "\nBaseline updated.\n");

        return 0;
    }

    fwrite(\STDOUT, "\nRun with --update-baseline once you've reviewed the drift, to record the new counts.\n");

    return 1;
}

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
exit(main($argv));
```

- [ ] **Step 3: Re-run the subprocess test**

Run: `vendor/bin/phpunit tests/FixtureDriftToolTest.php`
Expected: PASS (this test itself still has its own separate PHPStan findings — fixed in Task 3 — this step only checks runtime behavior didn't change).

- [ ] **Step 4: Standard Verification Loop**

Run the loop from Global Constraints. Expect the baseline to shrink by exactly 10 entries (all `../../bin/check-fixture-drift.php` entries gone from `phpstan-baseline.neon`).

- [ ] **Step 5: Commit**

```bash
git add bin/check-fixture-drift.php phpstan-baseline.neon
git commit -m "fix: type-safety pass over bin/check-fixture-drift.php"
```

---

### Task 2: `../../bin/xsd-construct-report.php` type-safety fixes (Cluster A)

**Files:**

- Modify: `../../bin/xsd-construct-report.php`

**Interfaces:** None — standalone CLI script; `../../bin/check-fixture-drift.php` invokes it only as a subprocess (`shell_exec`), no PHP-level coupling.

Ground-truth errors (15): `collectXsdFiles()`'s docblock says `string[]` but the body (via `sort()`) produces a `list<string>` (L100); `RecursiveIteratorIterator`'s loop variable used as `mixed` (L94, L95); `countConstructs()`'s combined single-line `@param`/`@return` docblock isn't parsed — PHPStan sees no value type at all (L104); `$xp->query()`'s `\DOMNodeList|false` `->length` access (L119); `main()`'s untyped `$argv` (L126) cascading into `array_filter()`'s callback mismatch (L130), `file_exists()`/`collectXsdFiles()`/two encapsed-string sites on the now-`mixed` `$target` (L139, L140, L145, L147); `max()` on a possibly-empty array (L160); two `sprintf` `mixed` args (L161, L163) cascading from the same `$argv` typing; bare `$argv` at the trailer (L169).

- [ ] **Step 1: Confirm current behavior via the existing subprocess test**

Run: `vendor/bin/phpunit tests/ConstructReportToolTest.php`
Expected: PASS.

- [ ] **Step 2: Apply the fix**

Edit `../../bin/xsd-construct-report.php` — full new contents:

```php
<?php

declare(strict_types=1);

/**
 * Schema-agnostic XSD construct occurrence counter - independent of any specific schema. Parses
 * every *.xsd file under the given directory (or a single file) with DOMDocument/DOMXPath (not
 * regex/grep - avoids false positives from comments, string literals, or substring collisions
 * between e.g. "xs:any" and "xs:anyAttribute") and counts occurrences of each construct listed in
 * docs/construct-coverage.md.
 *
 * Usage:
 *   php xsd-construct-report.php <xsd-dir-or-file> [--json]
 *
 * Output: a table (or, with --json, a machine-readable map) of construct label => occurrence count,
 * scanning recursively into subdirectories.
 */
const XS_NS = 'http://www.w3.org/2001/XMLSchema';

/** @return array<string, string> label => XPath expression (relative to the schema root, "//" absolute) */
function constructDefinitions(): array
{
    return [
        // Partikel-Struktur
        'xs:sequence' => '//xs:sequence',
        'xs:choice' => '//xs:choice',
        'xs:all' => '//xs:all',
        'xs:group (Definition)' => '//xs:group[@name]',
        'xs:group (ref=)' => '//xs:group[@ref]',
        'xs:attributeGroup (Definition)' => '//xs:attributeGroup[@name]',
        'xs:attributeGroup (ref=)' => '//xs:attributeGroup[@ref]',
        'maxOccurs="unbounded"' => '//*[@maxOccurs="unbounded"]',
        'xs:any' => '//xs:any',
        'xs:anyAttribute' => '//xs:anyAttribute',

        // Type-Ableitung
        'xs:simpleContent' => '//xs:simpleContent',
        'xs:complexContent (Extension)' => '//xs:complexContent/xs:extension',
        'xs:complexContent (Restriction)' => '//xs:complexContent/xs:restriction',
        'xs:enumeration' => '//xs:enumeration',
        'xs:pattern' => '//xs:pattern',
        'xs:minLength' => '//xs:minLength',
        'xs:maxLength' => '//xs:maxLength',
        'xs:length' => '//xs:length',
        'xs:minInclusive' => '//xs:minInclusive',
        'xs:maxInclusive' => '//xs:maxInclusive',
        'xs:minExclusive' => '//xs:minExclusive',
        'xs:maxExclusive' => '//xs:maxExclusive',
        'xs:totalDigits' => '//xs:totalDigits',
        'xs:fractionDigits' => '//xs:fractionDigits',
        'xs:whiteSpace' => '//xs:whiteSpace',
        'xs:union' => '//xs:union',
        'xs:list' => '//xs:list',
        'mixed="true"' => '//xs:complexType[@mixed="true"]',
        'abstract="true"' => '//xs:complexType[@abstract="true"]',
        'substitutionGroup=' => '//xs:element[@substitutionGroup]',
        'xs:redefine' => '//xs:redefine',
        'xs:override' => '//xs:override',

        // Element-/Attribut-Deklaration
        'xs:element default=' => '//xs:element[@default]',
        'xs:element fixed=' => '//xs:element[@fixed]',
        'xs:attribute default=' => '//xs:attribute[@default]',
        'xs:attribute fixed=' => '//xs:attribute[@fixed]',
        'nillable="true"' => '//xs:element[@nillable="true"]',
        'use="required"' => '//xs:attribute[@use="required"]',
        'use="optional"' => '//xs:attribute[@use="optional"]',
        'use="prohibited"' => '//xs:attribute[@use="prohibited"]',
        'xs:element ref=' => '//xs:element[@ref]',
        'xs:attribute ref=' => '//xs:attribute[@ref]',
        'form= (element/attribute)' => '//xs:element[@form] | //xs:attribute[@form]',

        // Identity Constraints
        'xs:key' => '//xs:key',
        'xs:keyref' => '//xs:keyref',
        'xs:unique' => '//xs:unique',

        // Namespace-Handling
        'xs:import' => '//xs:import',
        'xs:include' => '//xs:include',
        'elementFormDefault="unqualified"' => '//xs:schema[@elementFormDefault="unqualified"]',
    ];
}

/** @return list<string> absolute paths to every *.xsd file under $path (or [$path] if it's a file) */
function collectXsdFiles(string $path): array
{
    if (is_file($path)) {
        return [$path];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }
        if ('xsd' === $file->getExtension()) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * @param string[] $files
 *
 * @return array<string, int> label => total occurrence count across all files
 */
function countConstructs(array $files): array
{
    $definitions = constructDefinitions();
    $counts = array_fill_keys(array_keys($definitions), 0);

    foreach ($files as $file) {
        $dom = new DOMDocument();
        if (!$dom->load($file)) {
            fwrite(\STDERR, "WARN: failed to parse '{$file}', skipping\n");
            continue;
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('xs', XS_NS);

        foreach ($definitions as $label => $expression) {
            $result = $xp->query($expression);
            if (false === $result) {
                fwrite(\STDERR, "WARN: invalid XPath expression for '{$label}', skipping\n");
                continue;
            }
            $counts[$label] += $result->length;
        }
    }

    return $counts;
}

/** @param list<string> $argv */
function main(array $argv): int
{
    $args = array_slice($argv, 1);
    $asJson = in_array('--json', $args, true);
    $args = array_values(array_filter($args, static fn (string $a): bool => '--json' !== $a));

    if (1 !== count($args)) {
        fwrite(\STDERR, "Usage: php xsd-construct-report.php <xsd-dir-or-file> [--json]\n");

        return 1;
    }

    $target = $args[0];
    if (!file_exists($target)) {
        fwrite(\STDERR, "'{$target}' does not exist.\n");

        return 1;
    }

    $files = collectXsdFiles($target);
    if ([] === $files) {
        fwrite(\STDERR, "No *.xsd files found under '{$target}'.\n");

        return 1;
    }

    $counts = countConstructs($files);

    if ($asJson) {
        echo json_encode($counts, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";

        return 0;
    }

    $labelWidth = max([0, ...array_map(strlen(...), array_keys($counts))]);
    fwrite(\STDOUT, sprintf("Scanned %d *.xsd file(s) under '%s':\n\n", count($files), $target));
    foreach ($counts as $label => $count) {
        fwrite(\STDOUT, sprintf("  %-{$labelWidth}s  %d\n", $label, $count));
    }

    return 0;
}

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
exit(main($argv));
```

- [ ] **Step 3: Re-run the subprocess test**

Run: `vendor/bin/phpunit tests/ConstructReportToolTest.php`
Expected: PASS.

- [ ] **Step 4: Standard Verification Loop**

Expect the baseline to shrink by exactly 15 entries.

- [ ] **Step 5: Commit**

```bash
git add bin/xsd-construct-report.php phpstan-baseline.neon
git commit -m "fix: type-safety pass over bin/xsd-construct-report.php"
```

---

### Task 3: Tool test harness type fixes — `../../tests/ConstructReportToolTest.php` + `../../tests/FixtureDriftToolTest.php` (Cluster D)

**Files:**

- Modify: `../../tests/ConstructReportToolTest.php`
- Modify: `../../tests/FixtureDriftToolTest.php`

**Interfaces:** None — self-contained test helper methods.

Ground-truth errors: `ConstructReportToolTest::runTool()` (1: L110, return type doesn't narrow past `assertIsArray()`'s `array<mixed>`). `FixtureDriftToolTest` (6): two `json_decode(file_get_contents(...), true)` sites without a `string|false` guard (L48, L78) each cascading into an `offsetAccess.nonOffsetAccessible` on the next line (L49, L79), `runTool()`'s untyped `$extraArgs` param (L102) cascading into `array_map()`'s callback mismatch (L104).

- [ ] **Step 1: Confirm current behavior**

Run: `vendor/bin/phpunit tests/ConstructReportToolTest.php tests/FixtureDriftToolTest.php`
Expected: PASS.

- [ ] **Step 2: Fix `../../tests/ConstructReportToolTest.php`**

Find (around L101-113):

```php
    /** @return array<string, int> */
    private function runTool(string $target): array
    {
        $json = shell_exec('php '.escapeshellarg($this->toolPath).' '.escapeshellarg($target).' --json');
        $this->assertIsString($json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
```

Replace with:

```php
    /** @return array<string, int> */
    private function runTool(string $target): array
    {
        $json = shell_exec('php '.escapeshellarg($this->toolPath).' '.escapeshellarg($target).' --json');
        $this->assertIsString($json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        /** @var array<string, int> $decoded */

        return $decoded;
    }
```

- [ ] **Step 3: Fix `../../tests/FixtureDriftToolTest.php`**

Find (around L47-49):

```php
        [, $exitCode] = $this->runTool();

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->baselineFile);
        $baseline = json_decode(file_get_contents($this->baselineFile), true);
        $this->assertSame(1, $baseline['xs:sequence']);
```

Replace with:

```php
        [, $exitCode] = $this->runTool();

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->baselineFile);
        $baselineJson = file_get_contents($this->baselineFile);
        $this->assertIsString($baselineJson);
        $baseline = json_decode($baselineJson, true);
        $this->assertIsArray($baseline);
        $this->assertSame(1, $baseline['xs:sequence']);
```

Find (around L76-79):

```php
        [, $exitCode] = $this->runTool(['--update-baseline']);
        $this->assertSame(0, $exitCode);
        $baseline = json_decode(file_get_contents($this->baselineFile), true);
        $this->assertSame(2, $baseline['xs:sequence']);
```

Replace with:

```php
        [, $exitCode] = $this->runTool(['--update-baseline']);
        $this->assertSame(0, $exitCode);
        $baselineJson = file_get_contents($this->baselineFile);
        $this->assertIsString($baselineJson);
        $baseline = json_decode($baselineJson, true);
        $this->assertIsArray($baseline);
        $this->assertSame(2, $baseline['xs:sequence']);
```

Find (around L101-104):

```php
    /** @return array{0: string, 1: int} [combined stdout+stderr, exit code] */
    private function runTool(array $extraArgs = []): array
    {
        $cmd = 'php '.escapeshellarg($this->toolPath).' '.implode(' ', array_map(escapeshellarg(...), $extraArgs)).' 2>&1';
```

Replace with:

```php
    /**
     * @param list<string> $extraArgs
     *
     * @return array{0: string, 1: int} [combined stdout+stderr, exit code]
     */
    private function runTool(array $extraArgs = []): array
    {
        $cmd = 'php '.escapeshellarg($this->toolPath).' '.implode(' ', array_map(escapeshellarg(...), $extraArgs)).' 2>&1';
```

- [ ] **Step 4: Re-run the tests**

Run: `vendor/bin/phpunit tests/ConstructReportToolTest.php tests/FixtureDriftToolTest.php`
Expected: PASS.

- [ ] **Step 5: Standard Verification Loop**

Expect the baseline to shrink by exactly 7 entries (1 + 6).

- [ ] **Step 6: Commit**

```bash
git add tests/ConstructReportToolTest.php tests/FixtureDriftToolTest.php phpstan-baseline.neon
git commit -m "fix: type-safety pass over bin/ tool test harnesses"
```

---

### Task 4: `../../src/Naming.php` type-safety fixes (Cluster D)

**Files:**

- Modify: `../../src/Naming.php`
- Test: `tests/NamingTest.php` (if it exists — check first; if not, `Naming` is exercised indirectly through `GeneratorTest.php`, which is this task's regression guard)

**Interfaces:** `Naming::basename()`, `Naming::sanitizeIdentifier()` — both `public static`, called from `Generator.php` (`basename()` at `buildComplexClass()`, `sanitizeIdentifier()` transitively via `toPropName()`/`toClassName()`) and possibly from tests. Signatures (param/return types) are unchanged by this task — only internal null/false-safety.

Ground-truth errors (3): `basename()`'s `strrchr()` result used in `substr()` without a `false`-guard (L48); `sanitizeIdentifier()`'s `preg_replace()` result used as an array offset without a `null`-guard (L67) and returned without matching its `string` return type (L71).

- [ ] **Step 1: Confirm current behavior**

Run: `composer test` (Naming has no dedicated test file at plan-writing time — `GeneratorTest.php`'s generated-class/property names are the regression guard)
Expected: PASS.

- [ ] **Step 2: Apply the fix**

Find (around L46-49):

```php
    public static function basename(string $fqcn): string
    {
        return substr(strrchr('\\'.$fqcn, '\\'), 1);
    }
```

Replace with:

```php
    public static function basename(string $fqcn): string
    {
        $pos = strrchr('\\'.$fqcn, '\\');

        return false === $pos ? $fqcn : substr($pos, 1);
    }
```

Find (around L64-72):

```php
    public static function sanitizeIdentifier(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        if ('' === $name || ctype_digit($name[0])) {
            return 'V'.$name;
        }

        return $name;
    }
```

Replace with:

```php
    public static function sanitizeIdentifier(string $name): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        if (null === $sanitized) {
            throw new \RuntimeException("preg_replace failed sanitizing identifier '{$name}'");
        }
        if ('' === $sanitized || ctype_digit($sanitized[0])) {
            return 'V'.$sanitized;
        }

        return $sanitized;
    }
```

- [ ] **Step 3: Re-run tests**

Run: `composer test`
Expected: PASS.

- [ ] **Step 4: Standard Verification Loop**

Expect the baseline to shrink by exactly 3 entries.

- [ ] **Step 5: Commit**

```bash
git add src/Naming.php phpstan-baseline.neon
git commit -m "fix: type-safety pass over Naming"
```

---

### Task 5: `../../src/Attribute/CompositeAttributeStrategy.php` fix (Cluster D)

**Files:**

- Modify: `../../src/Attribute/CompositeAttributeStrategy.php`

**Interfaces:** Unchanged — `attributesFor(Property $property): array` (implements `PropertyAttributeStrategy`).

Ground-truth error (1): the variadic constructor's `$strategies` param assigns to a `list<PropertyAttributeStrategy>`-typed property, but PHPStan infers the variadic capture as `array<int<0,max>|string, PropertyAttributeStrategy>` (not guaranteed a tight `list`).

- [ ] **Step 1: Confirm current behavior**

Run: `composer test` (no dedicated test file for this class at plan-writing time — exercised via `GeneratorTest.php`'s attribute-strategy composition tests, if any, otherwise via any test constructing `new CompositeAttributeStrategy(...)`)
Expected: PASS.

- [ ] **Step 2: Apply the fix**

Find:

```php
    public function __construct(PropertyAttributeStrategy ...$strategies)
    {
        $this->strategies = $strategies;
    }
```

Replace with:

```php
    public function __construct(PropertyAttributeStrategy ...$strategies)
    {
        $this->strategies = array_values($strategies);
    }
```

- [ ] **Step 3: Re-run tests**

Run: `composer test`
Expected: PASS.

- [ ] **Step 4: Standard Verification Loop**

Expect the baseline to shrink by exactly 1 entry.

- [ ] **Step 5: Commit**

```bash
git add src/Attribute/CompositeAttributeStrategy.php phpstan-baseline.neon
git commit -m "fix: CompositeAttributeStrategy variadic-to-list narrowing"
```

---

### Task 6: `TypeKind` enum + `TypeInfo` value object (new files, Cluster B foundation)

**Files:**

- Create: `src/TypeKind.php`
- Create: `src/TypeInfo.php`

**Interfaces:**

- Produces: `TypeKind` (enum, cases `Scalar`, `Class_`, `Enum`) and `TypeInfo` (`final readonly class`, constructor `TypeKind $kind, string $phpType, bool $dateOnly = false, array $facets = [], ?string $namedType = null`) — consumed by every task from Task 9 onward.

Not yet wired into `Generator.php` — pure additions, no existing behavior touched, no new PHPStan findings possible (both classes are unused until Task 9, and PHPStan's `level: max` does not flag unused _classes_, only unused private members within a class already in use).

- [ ] **Step 1: Create `src/TypeKind.php`**

```php
<?php

declare(strict_types=1);

namespace Xsd2Php;

enum TypeKind
{
    case Scalar;
    case Class_;
    case Enum;
}
```

- [ ] **Step 2: Create `src/TypeInfo.php`**

```php
<?php

declare(strict_types=1);

namespace Xsd2Php;

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

- [ ] **Step 3: Verify both files are clean and the whole suite still passes**

Run: `vendor/bin/phpstan analyse src/TypeKind.php src/TypeInfo.php`
Expected: `[OK] No errors`

Run: `composer test`
Expected: PASS (nothing depends on these files yet, so this only confirms nothing else broke).

- [ ] **Step 4: Standard Verification Loop**

Expect the baseline unchanged (0 entries removed — these are new files with no prior baseline entries, and nothing consuming them yet to introduce new ones).

- [ ] **Step 5: Commit**

```bash
git add src/TypeKind.php src/TypeInfo.php
git commit -m "feat: add TypeKind enum and TypeInfo value object"
```

---

### Task 7: `Generator::generate()` + `Generator::indexSchemas()` — `query()`/`ownerDocOf()` helpers (Cluster A/C foundation)

**Files:**

- Modify: `../../src/Generator.php`

**Interfaces:**

- Produces: two new `private` helpers other tasks rely on —
  `private function ownerDocOf(\DOMElement $node): \DOMDocument` (throws `\RuntimeException` if `$node->ownerDocument` is null — unreachable for a node parsed from a loaded `\DOMDocument`)
  `private function query(\DOMXPath $xp, string $expression, ?\DOMNode $context = null): \DOMNodeList` (throws `\RuntimeException` if `$xp->query()` returns `false` — unreachable for this generator's own static, hardcoded XPath expressions).

Ground-truth errors fixed here (12): `generate()`'s `xpath($el->ownerDocument)` (L89, `?\DOMDocument` given) and the following `->item(0)` call on a `\DOMNodeList|false` (L90); `indexSchemas()`'s `foreach` over a `\DOMNodeList|false` (L141), the resulting `mixed` `$node->localName` used as an array offset (L142, two errors) and the cascading `mixed` in the dynamic property assignment (L143, six errors: one `binaryOp`, one `method.notFound`, five `assign.propertyType` — one per collection property).

- [ ] **Step 1: Confirm current behavior**

Run: `composer test`
Expected: PASS.

- [ ] **Step 2: Fixture diff — capture "before" snapshot**

Run the "before" half of the DOM-touching-task fixture diff script from Global Constraints, into `/tmp/xsd2php-diff-before`.

- [ ] **Step 3: Add the two helpers**

Find (the `xpath()` method, around L108-117):

```php
    private function xpath(\DOMDocument $doc): \DOMXPath
    {
        if (!isset($this->xpathCache[$doc])) {
            $xp = new \DOMXPath($doc);
            $xp->registerNamespace('xs', self::XS_NS);
            $this->xpathCache[$doc] = $xp;
        }

        return $this->xpathCache[$doc];
    }
```

Replace with (adds two helpers immediately after):

```php
    private function xpath(\DOMDocument $doc): \DOMXPath
    {
        if (!isset($this->xpathCache[$doc])) {
            $xp = new \DOMXPath($doc);
            $xp->registerNamespace('xs', self::XS_NS);
            $this->xpathCache[$doc] = $xp;
        }

        return $this->xpathCache[$doc];
    }

    /** Every \DOMElement parsed from a loaded document has a non-null ownerDocument - a truly unreachable defensive check, not a malformed-schema case. */
    private function ownerDocOf(\DOMElement $node): \DOMDocument
    {
        return $node->ownerDocument ?? throw new \RuntimeException('DOMElement without ownerDocument (detached node)');
    }

    /** Wraps DOMXPath::query(), which is declared |false for a malformed XPath expression - unreachable for this generator's own static, hardcoded expressions. */
    private function query(\DOMXPath $xp, string $expression, ?\DOMNode $context = null): \DOMNodeList
    {
        $result = $xp->query($expression, $context);
        if (false === $result) {
            throw new \RuntimeException("Invalid XPath expression '{$expression}'");
        }

        return $result;
    }
```

- [ ] **Step 4: Fix `generate()`'s xpath/item call**

Find (around L86-97):

```php
        foreach ($this->elements as $key => $el) {
            [$xsdNs, $local] = explode('#', $key, 2);
            $namespace = $this->namespaceFor($xsdNs)->phpNamespace;
            $xp = $this->xpath($el->ownerDocument);
            $inlineComplex = $xp->query('xs:complexType', $el)->item(0);
            if ($inlineComplex instanceof \DOMElement) {
```

Replace with:

```php
        foreach ($this->elements as $key => $el) {
            [$xsdNs, $local] = explode('#', $key, 2);
            $namespace = $this->namespaceFor($xsdNs)->phpNamespace;
            $xp = $this->xpath($this->ownerDocOf($el));
            $inlineComplex = $this->query($xp, 'xs:complexType', $el)->item(0);
            if ($inlineComplex instanceof \DOMElement) {
```

(the following lines — `buildComplexClass()`/`resolveParticleType()`/`note()` calls — are unchanged; `{$typeInfo['phpType']}` stays array-access here, it's migrated in Task 9)

- [ ] **Step 5: Fix `indexSchemas()`**

Find (around L128-146):

```php
    private function indexSchemas(): void
    {
        $query = implode(' | ', array_map(
            static fn (string $localName): string => "/xs:schema/xs:{$localName}[@name]",
            array_keys(self::SCHEMA_NAME_BUCKETS),
        ));

        foreach ($this->config->xsdPaths as $file) {
            $dom = new \DOMDocument();
            $dom->load($file);
            $xp = $this->xpath($dom);
            $targetNs = $dom->documentElement?->getAttribute('targetNamespace') ?? '';

            foreach ($xp->query($query) as $node) {
                $property = self::SCHEMA_NAME_BUCKETS[$node->localName];
                $this->{$property}[$targetNs.'#'.$node->getAttribute('name')] = $node;
            }
        }
    }
```

Replace with:

```php
    private function indexSchemas(): void
    {
        $query = implode(' | ', array_map(
            static fn (string $localName): string => "/xs:schema/xs:{$localName}[@name]",
            array_keys(self::SCHEMA_NAME_BUCKETS),
        ));

        foreach ($this->config->xsdPaths as $file) {
            $dom = new \DOMDocument();
            $dom->load($file);
            $xp = $this->xpath($dom);
            $targetNs = $dom->documentElement?->getAttribute('targetNamespace') ?? '';

            foreach ($this->query($xp, $query) as $node) {
                if (!$node instanceof \DOMElement || !isset(self::SCHEMA_NAME_BUCKETS[$node->localName])) {
                    $this->warn('non-element or unrecognized node from indexSchemas() query, skipping');
                    continue;
                }
                $property = self::SCHEMA_NAME_BUCKETS[$node->localName];
                $this->{$property}[$targetNs.'#'.$node->getAttribute('name')] = $node;
            }
        }
    }
```

- [ ] **Step 6: Fixture diff — capture "after" snapshot and compare**

Run the "after" half into `/tmp/xsd2php-diff-after`, then `diff -r /tmp/xsd2php-diff-before /tmp/xsd2php-diff-after`.
Expected: no output (byte-identical).

- [ ] **Step 7: Re-run tests**

Run: `composer test`
Expected: PASS.

- [ ] **Step 8: Standard Verification Loop**

Expect the baseline to shrink by exactly 12 entries.

- [ ] **Step 9: Commit**

```bash
git add src/Generator.php phpstan-baseline.neon
git commit -m "fix: guard generate()/indexSchemas() DOM access, add query()/ownerDocOf() helpers"
```

---

### Task 8: `resolveQName()` + `collectParticleElements()` + `collectGroupRefElements()` + `collectAttributes()` + `resolveNamedRef()` (Cluster C)

**Files:**

- Modify: `../../src/Generator.php`

**Interfaces:**

- `resolveQName()`'s return type is unchanged (`array{0: string, 1: string}`) — only its docblock format changes (see below), which is what actually fixes the finding.
- `collectGroupRefElements()`'s **docblock** return type is corrected from `\DOMElement[]` to `array{0: \DOMElement, 1: ?\DOMElement}[]` — this matches its actual runtime behavior (it delegates straight to `collectParticleElements()`, which already returns tuple pairs) and matches the existing, already-correct `$groupElementsCache` property docblock at the top of the class (L55). No caller-visible behavior change — this was a stale docblock, not a logic bug.

Root cause for L218/221/226 (`resolveNamedRef()`'s `$ns`.`$local`. concatenation showing as `mixed`): `resolveQName()`'s docblock crams a description and an `@return` tag onto one single-line `/** ... */` comment — PHPStan's docblock parser doesn't associate the `@return` tag correctly in that form, so it sees no return type at all. Splitting it into a proper multi-line docblock (no logic change) fixes `resolveQName()`'s own finding directly, and fixes `resolveNamedRef()`'s three findings as a pure cascade — no code change needed inside `resolveNamedRef()` itself.

Ground-truth errors fixed here (25): `resolveQName()` (1: L149). `collectParticleElements()` (7: L174 param value type, L176 xpath/ownerDocument, L179 foreach, L185 destructuring x1 + param type x1, L190 param type, L193 return type). `resolveNamedRef()` cascade (4: L218 x2, L221, L226 — no code change, see above). `collectGroupRefElements()` (3: L237 param value type, L239 `$seen` arg type, L244 return type). `collectGroupRefElements()`'s own body (3: L247 xpath/ownerDocument, L248 item(), L251 return type — same finding as L244, both return statements). `collectAttributes()` (7: L255 param value type, L257 xpath/ownerDocument, L260 foreach, L263 foreach, L264 x2 arg types, L277 return type).

- [ ] **Step 1: Confirm current behavior**

Run: `composer test`
Expected: PASS.

- [ ] **Step 2: Fixture diff — capture "before" snapshot**

Same script as Task 7 Step 2, fresh run.

- [ ] **Step 3: Fix `resolveQName()`'s docblock**

Find (around L148-154):

```php
    /** Resolves a possibly-prefixed QName against $contextNode's in-scope namespace bindings. @return array{0: string, 1: string} [namespaceURI, localName] */
    private function resolveQName(\DOMElement $contextNode, string $qname): array
    {
        [$prefix, $local] = Naming::splitQName($qname);

        return [$contextNode->lookupNamespaceURI($prefix) ?? '', $local];
    }
```

Replace with:

```php
    /**
     * Resolves a possibly-prefixed QName against $contextNode's in-scope namespace bindings.
     *
     * @return array{0: string, 1: string} [namespaceURI, localName]
     */
    private function resolveQName(\DOMElement $contextNode, string $qname): array
    {
        [$prefix, $local] = Naming::splitQName($qname);

        return [$contextNode->lookupNamespaceURI($prefix) ?? '', $local];
    }
```

- [ ] **Step 4: Fix `collectParticleElements()`**

Find (around L166-194):

```php
    /**
     * @return array{0: \DOMElement, 1: ?\DOMElement}[] [element, enclosing xs:choice particle] pairs,
     *                                                  xs:sequence/xs:choice/xs:all nesting flattened, xs:group refs inlined. The enclosing choice
     *                                                  is the innermost xs:choice ancestor within this particle tree (null if the element sits
     *                                                  under xs:sequence/xs:all only) - callers use it to treat choice-branch elements as mutually
     *                                                  exclusive alternatives (nullable, "exactly one of" constraint) instead of independently
     *                                                  required siblings, which xs:sequence-style flattening alone would wrongly imply.
     */
    private function collectParticleElements(\DOMElement $particle, array &$seenGroups = [], ?\DOMElement $enclosingChoice = null): array
    {
        $xp = $this->xpath($particle->ownerDocument);
        $ownChoice = 'choice' === $particle->localName ? $particle : $enclosingChoice;
        $elements = [];
        foreach ($xp->query('xs:element | xs:sequence | xs:choice | xs:all | xs:group', $particle) as $child) {
            if ('element' === $child->localName) {
                $elements[] = [$child, $ownChoice];
                continue;
            }
            if ('group' === $child->localName) {
                foreach ($this->collectGroupRefElements($child, $seenGroups) as [$el, $intrinsicChoice]) {
                    $elements[] = [$el, $intrinsicChoice ?? $ownChoice];
                }
                continue;
            }
            $elements = [...$elements, ...$this->collectParticleElements($child, $seenGroups, $ownChoice)];
        }

        return $elements;
    }
```

Replace with:

```php
    /**
     * @param array<string, true> &$seenGroups
     *
     * @return array{0: \DOMElement, 1: ?\DOMElement}[] [element, enclosing xs:choice particle] pairs,
     *                                                  xs:sequence/xs:choice/xs:all nesting flattened, xs:group refs inlined. The enclosing choice
     *                                                  is the innermost xs:choice ancestor within this particle tree (null if the element sits
     *                                                  under xs:sequence/xs:all only) - callers use it to treat choice-branch elements as mutually
     *                                                  exclusive alternatives (nullable, "exactly one of" constraint) instead of independently
     *                                                  required siblings, which xs:sequence-style flattening alone would wrongly imply.
     */
    private function collectParticleElements(\DOMElement $particle, array &$seenGroups = [], ?\DOMElement $enclosingChoice = null): array
    {
        $xp = $this->xpath($this->ownerDocOf($particle));
        $ownChoice = 'choice' === $particle->localName ? $particle : $enclosingChoice;
        $elements = [];
        foreach ($this->query($xp, 'xs:element | xs:sequence | xs:choice | xs:all | xs:group', $particle) as $child) {
            if (!$child instanceof \DOMElement) {
                $this->warn('non-element node in particle content, skipping');
                continue;
            }
            if ('element' === $child->localName) {
                $elements[] = [$child, $ownChoice];
                continue;
            }
            if ('group' === $child->localName) {
                foreach ($this->collectGroupRefElements($child, $seenGroups) as [$el, $intrinsicChoice]) {
                    $elements[] = [$el, $intrinsicChoice ?? $ownChoice];
                }
                continue;
            }
            $elements = [...$elements, ...$this->collectParticleElements($child, $seenGroups, $ownChoice)];
        }

        return $elements;
    }
```

- [ ] **Step 5: Fix `collectGroupRefElements()`**

Find (around L236-252):

```php
    /** @return \DOMElement[] */
    private function collectGroupRefElements(\DOMElement $groupRef, array &$seenGroups): array
    {
        [$key, $group] = $this->resolveNamedRef($groupRef, $this->groups, $seenGroups, 'group');
        if (!$group instanceof \DOMElement) {
            return [];
        }
        if (isset($this->groupElementsCache[$key])) {
            return $this->groupElementsCache[$key];
        }

        $xp = $this->xpath($group->ownerDocument);
        $groupParticle = $xp->query('xs:sequence | xs:choice | xs:all', $group)->item(0);
        $result = $groupParticle instanceof \DOMElement ? $this->collectParticleElements($groupParticle, $seenGroups) : [];

        return $this->groupElementsCache[$key] = $result;
    }
```

Replace with:

```php
    /**
     * @param array<string, true> &$seenGroups
     *
     * @return array{0: \DOMElement, 1: ?\DOMElement}[]
     */
    private function collectGroupRefElements(\DOMElement $groupRef, array &$seenGroups): array
    {
        [$key, $group] = $this->resolveNamedRef($groupRef, $this->groups, $seenGroups, 'group');
        if (!$group instanceof \DOMElement) {
            return [];
        }
        if (isset($this->groupElementsCache[$key])) {
            return $this->groupElementsCache[$key];
        }

        $xp = $this->xpath($this->ownerDocOf($group));
        $groupParticle = $this->query($xp, 'xs:sequence | xs:choice | xs:all', $group)->item(0);
        $result = $groupParticle instanceof \DOMElement ? $this->collectParticleElements($groupParticle, $seenGroups) : [];

        return $this->groupElementsCache[$key] = $result;
    }
```

- [ ] **Step 6: Fix `collectAttributes()`**

Find (around L254-278):

```php
    /** @return \DOMElement[] xs:attribute nodes, attributeGroup refs resolved recursively */
    private function collectAttributes(\DOMElement $container, array &$seenGroups = []): array
    {
        $xp = $this->xpath($container->ownerDocument);

        $attrs = [];
        foreach ($xp->query('xs:attribute', $container) as $attr) {
            $attrs[] = $attr;
        }
        foreach ($xp->query('xs:attributeGroup', $container) as $ref) {
            [$key, $attributeGroup] = $this->resolveNamedRef($ref, $this->attributeGroups, $seenGroups, 'attributeGroup');
            if (!$attributeGroup instanceof \DOMElement) {
                continue;
            }
            if (isset($this->attributeGroupCache[$key])) {
                $attrs = [...$attrs, ...$this->attributeGroupCache[$key]];
                continue;
            }
            $resolved = $this->collectAttributes($attributeGroup, $seenGroups);
            $this->attributeGroupCache[$key] = $resolved;
            $attrs = [...$attrs, ...$resolved];
        }

        return $attrs;
    }
```

Replace with:

```php
    /**
     * @param array<string, true> &$seenGroups
     *
     * @return \DOMElement[] xs:attribute nodes, attributeGroup refs resolved recursively
     */
    private function collectAttributes(\DOMElement $container, array &$seenGroups = []): array
    {
        $xp = $this->xpath($this->ownerDocOf($container));

        $attrs = [];
        foreach ($this->query($xp, 'xs:attribute', $container) as $attr) {
            if (!$attr instanceof \DOMElement) {
                $this->warn('non-element xs:attribute node, skipping');
                continue;
            }
            $attrs[] = $attr;
        }
        foreach ($this->query($xp, 'xs:attributeGroup', $container) as $ref) {
            if (!$ref instanceof \DOMElement) {
                $this->warn('non-element xs:attributeGroup node, skipping');
                continue;
            }
            [$key, $attributeGroup] = $this->resolveNamedRef($ref, $this->attributeGroups, $seenGroups, 'attributeGroup');
            if (!$attributeGroup instanceof \DOMElement) {
                continue;
            }
            if (isset($this->attributeGroupCache[$key])) {
                $attrs = [...$attrs, ...$this->attributeGroupCache[$key]];
                continue;
            }
            $resolved = $this->collectAttributes($attributeGroup, $seenGroups);
            $this->attributeGroupCache[$key] = $resolved;
            $attrs = [...$attrs, ...$resolved];
        }

        return $attrs;
    }
```

- [ ] **Step 7: Fixture diff — capture "after" snapshot and compare**

Expected: no output (byte-identical).

- [ ] **Step 8: Re-run tests**

Run: `composer test`
Expected: PASS.

- [ ] **Step 9: Standard Verification Loop**

Expect the baseline to shrink by exactly 25 entries.

- [ ] **Step 10: Commit**

```bash
git add src/Generator.php phpstan-baseline.neon
git commit -m "fix: guard particle/group/attribute collection DOM access, fix resolveQName() docblock"
```

---

### Task 9: `TypeInfo` migration — `resolveSimpleTypeRef()`, `resolvePrimitiveOrNamedSimpleType()`, `mergeFacets()`, `extractFacets()`, `toEnumResult()`/`ensureEnumClass()`, `fallbackScalar()`, `makeProperty()` (Cluster B, atomic)

This is the largest task — every one of these methods either produces, consumes, or passes through the `$typeInfo`/`$baseInfo` shape, so they must migrate together in one commit (a partial migration wouldn't compile: e.g. `resolveSimpleTypeRef()` assigns `resolvePrimitiveOrNamedSimpleType()`'s result straight into a variable it then reads `->kind`/`->phpType` from).

**Files:**

- Modify: `../../src/Generator.php`

**Interfaces:**

- `fallbackScalar(string $reason): TypeInfo` (was `: array`)
- `resolvePrimitiveOrNamedSimpleType(\DOMElement $contextNode, string $qname): TypeInfo` (was `: array`)
- `mergeFacets(TypeInfo $typeInfo, \DOMElement $restriction): TypeInfo` (was `array $typeInfo, ...): array`) — now returns a **new** `TypeInfo` instance (immutable, no in-place mutation)
- `toEnumResult(string $name, \DOMNodeList $enumerations, string $backingPhpType, string $namespace): TypeInfo` (was `: array`)
- `resolveSimpleTypeRef(string $key): TypeInfo` (was `: array`)
- `resolveParticleType(\DOMElement $node, string $ownerClassName, string $ownerNamespace): TypeInfo` (was `: array`)
- `makeProperty(string $name, PropertyRole $role, bool $isArray, bool $nullable, TypeInfo $typeInfo, ?string $doc): Property` (was `array $typeInfo`) — this is the `TypeInfo` -> `Property` boundary: `kind: match ($typeInfo->kind) { TypeKind::Scalar => 'scalar', TypeKind::Class_ => 'class', TypeKind::Enum => 'enum' }`.
- `$resolvedSimple` property's docblock changes from `array<string, array{kind: string, phpType: string, dateOnly?: bool}>` to `array<string, TypeInfo>`.
- Consumes: `TypeInfo`/`TypeKind` from Task 6.

Ground-truth errors fixed here (44): `resolveSimpleTypeRef()` region (L281 return value type, L296 xpath/ownerDocument, L300 item(), L308/L318/L336 the three `$resolvedSimple` property-type mismatches, L315 `->length` on `|false`, L317 x2 `toEnumResult()` arg types). `fallbackScalar()` (1: L342). `mergeFacets()` (2: L350 param + return value types) plus the unpacking error it currently masks (1: L355). `extractFacets()` (1: L393, return shape). `resolvePrimitiveOrNamedSimpleType()` (3: L397 return value type, L400 x2 binOp on `mixed`, L405 `xsPrimitiveToPhp()` arg). `toEnumResult()`/`ensureEnumClass()` (3: L409 x2 generics + value type, L414 generics). `resolveParticleType()` (11: L469 x2 binOp, L474/L493/L503/L508/L511 return-shape mismatches x5, L477 xpath/ownerDocument, L482 item(), L491 item(), L497 `->length`, L503 x2 `toEnumResult()` arg types). `makeProperty()` (6: L716 param value type, L724-728 five `Property`-constructor arg-type mismatches).

- [ ] **Step 1: Confirm current behavior**

Run: `composer test`
Expected: PASS.

- [ ] **Step 2: Fixture diff — capture "before" snapshot**

Same script as Task 7 Step 2, fresh run.

- [ ] **Step 3: `STRING_FALLBACK` constant + `fallbackScalar()`**

Find (around L28-29):

```php
    private const string XS_NS = 'http://www.w3.org/2001/XMLSchema';
    private const array STRING_FALLBACK = ['kind' => 'scalar', 'phpType' => 'string'];
```

Replace with:

```php
    private const string XS_NS = 'http://www.w3.org/2001/XMLSchema';
    private const TypeInfo STRING_FALLBACK = new TypeInfo(kind: TypeKind::Scalar, phpType: 'string');
```

Run `php -l src/Generator.php` right after this one edit (before continuing) — this checks PHP 8.4's "new in initializers" support for an object-typed class constant compiles as expected (spec's flagged edge case). Expected: `No syntax errors detected`. **If this fails**, replace the constant with a factory method instead — `private static function stringFallback(): TypeInfo { return new TypeInfo(kind: TypeKind::Scalar, phpType: 'string'); }` — and replace every `self::STRING_FALLBACK` reference below with `self::stringFallback()`; the rest of this task is unaffected either way.

Find (around L341-347):

```php
    /** Logs $reason via note() and returns the plain-string fallback type-info - every silent generator fallback goes through here so none can be added without a diagnostic. */
    private function fallbackScalar(string $reason): array
    {
        $this->note($reason);

        return self::STRING_FALLBACK;
    }
```

Replace with:

```php
    /** Logs $reason via note() and returns the plain-string fallback type-info - every silent generator fallback goes through here so none can be added without a diagnostic. */
    private function fallbackScalar(string $reason): TypeInfo
    {
        $this->note($reason);

        return self::STRING_FALLBACK;
    }
```

- [ ] **Step 4: `extractFacets()`**

Find (around L363-394):

```php
    /**
     * Reads this restriction's own xs:length/minLength/maxLength/pattern/minInclusive/
     * maxInclusive/minExclusive/maxExclusive/totalDigits/fractionDigits facets - shallow, does
     * not walk further up a chain of nested named-simpleType restrictions.
     *
     * @return array{length?: int, minLength?: int, maxLength?: int, pattern?: string, minInclusive?: string, maxInclusive?: string, minExclusive?: string, maxExclusive?: string, totalDigits?: int, fractionDigits?: int}
     */
    private function extractFacets(\DOMElement $restriction): array
    {
        /** @var array<string, bool> which facet keys are integer-valued */
        static $intFacets = ['length' => true, 'minLength' => true, 'maxLength' => true, 'totalDigits' => true, 'fractionDigits' => true];
        /** @var array<string, bool> which xs:* child element names are recognized facets */
        static $knownFacets = [
            'length' => true, 'minLength' => true, 'maxLength' => true, 'pattern' => true,
            'minInclusive' => true, 'maxInclusive' => true, 'minExclusive' => true, 'maxExclusive' => true,
            'totalDigits' => true, 'fractionDigits' => true,
        ];

        $facets = [];
        foreach ($restriction->childNodes as $child) {
            if (!$child instanceof \DOMElement || self::XS_NS !== $child->namespaceURI || !isset($knownFacets[$child->localName])) {
                continue;
            }
            if (isset($facets[$child->localName])) {
                continue; // first occurrence wins, matches the previous item(0) lookup
            }
            $value = $child->getAttribute('value');
            $facets[$child->localName] = isset($intFacets[$child->localName]) ? (int) $value : $value;
        }

        return $facets;
    }
```

Replace with (adds a targeted `@var` cast on the return, justified by the `isset($knownFacets[...])` guard above it):

```php
    /**
     * Reads this restriction's own xs:length/minLength/maxLength/pattern/minInclusive/
     * maxInclusive/minExclusive/maxExclusive/totalDigits/fractionDigits facets - shallow, does
     * not walk further up a chain of nested named-simpleType restrictions.
     *
     * @return array{length?: int, minLength?: int, maxLength?: int, pattern?: string, minInclusive?: string, maxInclusive?: string, minExclusive?: string, maxExclusive?: string, totalDigits?: int, fractionDigits?: int}
     */
    private function extractFacets(\DOMElement $restriction): array
    {
        /** @var array<string, bool> which facet keys are integer-valued */
        static $intFacets = ['length' => true, 'minLength' => true, 'maxLength' => true, 'totalDigits' => true, 'fractionDigits' => true];
        /** @var array<string, bool> which xs:* child element names are recognized facets */
        static $knownFacets = [
            'length' => true, 'minLength' => true, 'maxLength' => true, 'pattern' => true,
            'minInclusive' => true, 'maxInclusive' => true, 'minExclusive' => true, 'maxExclusive' => true,
            'totalDigits' => true, 'fractionDigits' => true,
        ];

        $facets = [];
        foreach ($restriction->childNodes as $child) {
            if (!$child instanceof \DOMElement || self::XS_NS !== $child->namespaceURI || !isset($knownFacets[$child->localName])) {
                continue;
            }
            if (isset($facets[$child->localName])) {
                continue; // first occurrence wins, matches the previous item(0) lookup
            }
            $value = $child->getAttribute('value');
            $facets[$child->localName] = isset($intFacets[$child->localName]) ? (int) $value : $value;
        }

        /** @var array{length?: int, minLength?: int, maxLength?: int, pattern?: string, minInclusive?: string, maxInclusive?: string, minExclusive?: string, maxExclusive?: string, totalDigits?: int, fractionDigits?: int} $facets */
        return $facets;
    }
```

- [ ] **Step 5: `mergeFacets()`**

Find (around L349-361):

```php
    /** Merges $restriction's own facets onto $typeInfo's (already possibly inherited) ones - own facets win on key collision. No-op if $typeInfo isn't a scalar. */
    private function mergeFacets(array $typeInfo, \DOMElement $restriction): array
    {
        if ('scalar' !== $typeInfo['kind']) {
            return $typeInfo;
        }
        $facets = [...($typeInfo['facets'] ?? []), ...$this->extractFacets($restriction)];
        if ([] !== $facets) {
            $typeInfo['facets'] = $facets;
        }

        return $typeInfo;
    }
```

Replace with:

```php
    /** Merges $restriction's own facets onto $typeInfo's (already possibly inherited) ones - own facets win on key collision. No-op if $typeInfo isn't a scalar. */
    private function mergeFacets(TypeInfo $typeInfo, \DOMElement $restriction): TypeInfo
    {
        if (TypeKind::Scalar !== $typeInfo->kind) {
            return $typeInfo;
        }
        $facets = [...$typeInfo->facets, ...$this->extractFacets($restriction)];
        if ([] === $facets) {
            return $typeInfo;
        }

        return new TypeInfo(
            kind: $typeInfo->kind,
            phpType: $typeInfo->phpType,
            dateOnly: $typeInfo->dateOnly,
            facets: $facets,
            namedType: $typeInfo->namedType,
        );
    }
```

- [ ] **Step 6: `resolvePrimitiveOrNamedSimpleType()`**

Find (around L396-406):

```php
    /** Resolves either "xs:string" style primitives or a reference to another named simpleType. */
    private function resolvePrimitiveOrNamedSimpleType(\DOMElement $contextNode, string $qname): array
    {
        [$ns, $local] = $this->resolveQName($contextNode, $qname);
        $key = $ns.'#'.$local;
        if (self::XS_NS !== $ns && isset($this->simpleTypes[$key])) {
            return $this->resolveSimpleTypeRef($key);
        }

        return ['kind' => 'scalar', 'phpType' => Naming::xsPrimitiveToPhp($local), 'dateOnly' => 'date' === $local];
    }
```

Replace with:

```php
    /** Resolves either "xs:string" style primitives or a reference to another named simpleType. */
    private function resolvePrimitiveOrNamedSimpleType(\DOMElement $contextNode, string $qname): TypeInfo
    {
        [$ns, $local] = $this->resolveQName($contextNode, $qname);
        $key = $ns.'#'.$local;
        if (self::XS_NS !== $ns && isset($this->simpleTypes[$key])) {
            return $this->resolveSimpleTypeRef($key);
        }

        return new TypeInfo(kind: TypeKind::Scalar, phpType: Naming::xsPrimitiveToPhp($local), dateOnly: 'date' === $local);
    }
```

- [ ] **Step 7: `toEnumResult()` + `ensureEnumClass()`**

Find (around L408-456):

```php
    /** Wraps ensureEnumClass()'s result as a resolveXxxType()-style type-info array. */
    private function toEnumResult(string $name, \DOMNodeList $enumerations, string $backingPhpType, string $namespace): array
    {
        return ['kind' => 'enum', 'phpType' => $this->ensureEnumClass($name, $enumerations, $backingPhpType, $namespace), 'dateOnly' => false];
    }

    private function ensureEnumClass(string $simpleTypeName, \DOMNodeList $enumerations, string $backingPhpType, string $namespace): string
    {
        $backing = 'int' === $backingPhpType ? 'int' : 'string';
        $className = Naming::toClassName($simpleTypeName);

        $usedCaseNames = [];
        $cases = [];
        foreach ($enumerations as $enum) {
            /** @var \DOMElement $enum */
            $value = $enum->getAttribute('value');
```

Replace with:

```php
    /** Wraps ensureEnumClass()'s result as a resolveXxxType()-style TypeInfo. */
    private function toEnumResult(string $name, \DOMNodeList $enumerations, string $backingPhpType, string $namespace): TypeInfo
    {
        return new TypeInfo(kind: TypeKind::Enum, phpType: $this->ensureEnumClass($name, $enumerations, $backingPhpType, $namespace));
    }

    /** @param \DOMNodeList<\DOMElement> $enumerations */
    private function ensureEnumClass(string $simpleTypeName, \DOMNodeList $enumerations, string $backingPhpType, string $namespace): string
    {
        $backing = 'int' === $backingPhpType ? 'int' : 'string';
        $className = Naming::toClassName($simpleTypeName);

        $usedCaseNames = [];
        $cases = [];
        foreach ($enumerations as $enum) {
            $value = $enum->getAttribute('value');
```

(only the `/** @var \DOMElement $enum */` line is removed — it's redundant once the param itself carries the `\DOMNodeList<\DOMElement>` generic; the rest of `ensureEnumClass()`'s body, from `$caseName = ...` through the closing `}`, is unchanged)

- [ ] **Step 8: `resolveSimpleTypeRef()`**

Find (around L280-339):

```php
    /** Resolves a named simpleType to either a backed enum or a scalar PHP type. */
    private function resolveSimpleTypeRef(string $key): array
    {
        if (isset($this->resolvedSimple[$key])) {
            return $this->resolvedSimple[$key];
        }
        // break self-reference cycles defensively
        $this->resolvedSimple[$key] = self::STRING_FALLBACK;

        if (!isset($this->simpleTypes[$key])) {
            $this->warn("unknown simpleType '{$key}', falling back to string");

            return $this->resolvedSimple[$key];
        }

        $node = $this->simpleTypes[$key];
        $xp = $this->xpath($node->ownerDocument);

        // xs:list/xs:restriction/xs:union are mutually exclusive per the XSD schema-for-schema -
        // a simpleType has at most one of them, so a combined query is unambiguous.
        $listOrRestriction = $xp->query('xs:list | xs:restriction', $node)->item(0);
        if ($listOrRestriction instanceof \DOMElement && 'list' === $listOrRestriction->localName) {
            $this->note("simpleType '{$key}' is xs:list, mapped to plain string");
            $this->resolvedSimple[$key] = self::STRING_FALLBACK;

            return self::STRING_FALLBACK;
        }
        if (!$listOrRestriction instanceof \DOMElement) {
            return $this->resolvedSimple[$key] = $this->fallbackScalar("simpleType '{$key}' is xs:union or has an unsupported restriction, mapped to plain string");
        }
        $restriction = $listOrRestriction;

        $baseInfo = $this->resolvePrimitiveOrNamedSimpleType($restriction, $restriction->getAttribute('base'));

        $enumerations = $xp->query('xs:enumeration', $restriction);
        if ($enumerations->length > 0 && 'scalar' === $baseInfo['kind']) {
            [$xsdNs, $local] = explode('#', $key, 2);
            $result = $this->toEnumResult($local, $enumerations, $baseInfo['phpType'], $this->namespaceFor($xsdNs)->phpNamespace);
            $this->resolvedSimple[$key] = $result;

            return $result;
        }

        // merge, not overwrite: XSD restriction is cumulative - a chain of named simpleTypes each
        // restricting the previous keeps every ancestor's facets, narrowed further by whatever
        // this level itself adds. This level's facets win on a key collision (e.g. a tighter
        // maxLength further down the chain).
        $baseInfo = $this->mergeFacets($baseInfo, $restriction);
        if ('scalar' === $baseInfo['kind']) {
            // the type as directly referenced (not an ancestor further up a restriction chain,
            // if $baseInfo already carried one from resolving its own base) - semantic-type
            // alias matching keys off this name.
            [, $selfLocal] = explode('#', $key, 2);
            $baseInfo['namedType'] = $selfLocal;
        }

        $this->resolvedSimple[$key] = $baseInfo;

        return $baseInfo;
    }
```

Replace with:

```php
    /** Resolves a named simpleType to either a backed enum or a scalar PHP type. */
    private function resolveSimpleTypeRef(string $key): TypeInfo
    {
        if (isset($this->resolvedSimple[$key])) {
            return $this->resolvedSimple[$key];
        }
        // break self-reference cycles defensively
        $this->resolvedSimple[$key] = self::STRING_FALLBACK;

        if (!isset($this->simpleTypes[$key])) {
            $this->warn("unknown simpleType '{$key}', falling back to string");

            return $this->resolvedSimple[$key];
        }

        $node = $this->simpleTypes[$key];
        $xp = $this->xpath($this->ownerDocOf($node));

        // xs:list/xs:restriction/xs:union are mutually exclusive per the XSD schema-for-schema -
        // a simpleType has at most one of them, so a combined query is unambiguous.
        $listOrRestriction = $this->query($xp, 'xs:list | xs:restriction', $node)->item(0);
        if ($listOrRestriction instanceof \DOMElement && 'list' === $listOrRestriction->localName) {
            $this->note("simpleType '{$key}' is xs:list, mapped to plain string");
            $this->resolvedSimple[$key] = self::STRING_FALLBACK;

            return self::STRING_FALLBACK;
        }
        if (!$listOrRestriction instanceof \DOMElement) {
            return $this->resolvedSimple[$key] = $this->fallbackScalar("simpleType '{$key}' is xs:union or has an unsupported restriction, mapped to plain string");
        }
        $restriction = $listOrRestriction;

        $baseInfo = $this->resolvePrimitiveOrNamedSimpleType($restriction, $restriction->getAttribute('base'));

        /** @var \DOMNodeList<\DOMElement> $enumerations */
        $enumerations = $this->query($xp, 'xs:enumeration', $restriction);
        if ($enumerations->length > 0 && TypeKind::Scalar === $baseInfo->kind) {
            [$xsdNs, $local] = explode('#', $key, 2);
            $result = $this->toEnumResult($local, $enumerations, $baseInfo->phpType, $this->namespaceFor($xsdNs)->phpNamespace);
            $this->resolvedSimple[$key] = $result;

            return $result;
        }

        // merge, not overwrite: XSD restriction is cumulative - a chain of named simpleTypes each
        // restricting the previous keeps every ancestor's facets, narrowed further by whatever
        // this level itself adds. This level's facets win on a key collision (e.g. a tighter
        // maxLength further down the chain).
        $baseInfo = $this->mergeFacets($baseInfo, $restriction);
        if (TypeKind::Scalar === $baseInfo->kind) {
            // the type as directly referenced (not an ancestor further up a restriction chain,
            // if $baseInfo already carried one from resolving its own base) - semantic-type
            // alias matching keys off this name.
            [, $selfLocal] = explode('#', $key, 2);
            $baseInfo = new TypeInfo(kind: $baseInfo->kind, phpType: $baseInfo->phpType, dateOnly: $baseInfo->dateOnly, facets: $baseInfo->facets, namedType: $selfLocal);
        }

        $this->resolvedSimple[$key] = $baseInfo;

        return $baseInfo;
    }
```

Also find (the class property docblock, around L44):

```php
    /** @var array<string, array{kind: string, phpType: string, dateOnly?: bool}> */
    private array $resolvedSimple = [];
```

Replace with:

```php
    /** @var array<string, TypeInfo> */
    private array $resolvedSimple = [];
```

- [ ] **Step 9: `resolveParticleType()`**

Find (around L458-512):

```php
    /**
     * Resolves the type of an xs:element or xs:attribute node: named @var ref,
     * or an inline anonymous xs:complexType/xs:simpleType child.
     *
     * @return array{kind: string, phpType: string, dateOnly?: bool}
     */
    private function resolveParticleType(\DOMElement $node, string $ownerClassName, string $ownerNamespace): array
    {
        $typeAttr = $node->getAttribute('type');
        if ('' !== $typeAttr) {
            [$ns, $local] = $this->resolveQName($node, $typeAttr);
            $key = $ns.'#'.$local;
            if (self::XS_NS !== $ns && isset($this->complexTypes[$key])) {
                return ['kind' => 'class', 'phpType' => $this->ensureComplexClass($key)];
            }

            return $this->resolvePrimitiveOrNamedSimpleType($node, $typeAttr);
        }

        $xp = $this->xpath($node->ownerDocument);
        $nestedNamespace = $ownerNamespace.'\\'.$ownerClassName;

        // xs:complexType/xs:simpleType are mutually exclusive per the XSD schema-for-schema - an
        // element/attribute has at most one, so a combined query is unambiguous.
        $inlineType = $xp->query('xs:complexType | xs:simpleType', $node)->item(0);
        if ($inlineType instanceof \DOMElement && 'complexType' === $inlineType->localName) {
            $anonName = Naming::toClassName($node->getAttribute('name'));
            $className = $this->buildComplexClass($inlineType, $anonName, $nestedNamespace);

            return ['kind' => 'class', 'phpType' => $className];
        }

        if ($inlineType instanceof \DOMElement) {
            $restriction = $xp->query('xs:restriction', $inlineType)->item(0);
            if (!$restriction instanceof \DOMElement) {
                return $this->fallbackScalar("inline simpleType without xs:restriction on '{$node->getAttribute('name')}', mapped to plain string");
            }

            $enumerations = $xp->query('xs:enumeration', $restriction);
            if ($enumerations->length > 0) {
                $anonName = Naming::toClassName($node->getAttribute('name')).'Enum';
                $base = $this->resolvePrimitiveOrNamedSimpleType($restriction, $restriction->getAttribute('base'));

                // nested under the owner's namespace, like inline complex types above, so two
                // unrelated owners with a same-named inline enum member don't collide on output
                return $this->toEnumResult($anonName, $enumerations, $base['phpType'], $nestedNamespace);
            }

            $base = $this->resolvePrimitiveOrNamedSimpleType($restriction, $restriction->getAttribute('base'));

            return $this->mergeFacets($base, $restriction);
        }

        return $this->fallbackScalar("no @type/inline type definition on '{$node->getAttribute('name')}' (xs:anyType equivalent), mapped to plain string");
    }
```

Replace with:

```php
    /** Resolves the type of an xs:element or xs:attribute node: named @var ref, or an inline anonymous xs:complexType/xs:simpleType child. */
    private function resolveParticleType(\DOMElement $node, string $ownerClassName, string $ownerNamespace): TypeInfo
    {
        $typeAttr = $node->getAttribute('type');
        if ('' !== $typeAttr) {
            [$ns, $local] = $this->resolveQName($node, $typeAttr);
            $key = $ns.'#'.$local;
            if (self::XS_NS !== $ns && isset($this->complexTypes[$key])) {
                return new TypeInfo(kind: TypeKind::Class_, phpType: $this->ensureComplexClass($key));
            }

            return $this->resolvePrimitiveOrNamedSimpleType($node, $typeAttr);
        }

        $xp = $this->xpath($this->ownerDocOf($node));
        $nestedNamespace = $ownerNamespace.'\\'.$ownerClassName;

        // xs:complexType/xs:simpleType are mutually exclusive per the XSD schema-for-schema - an
        // element/attribute has at most one, so a combined query is unambiguous.
        $inlineType = $this->query($xp, 'xs:complexType | xs:simpleType', $node)->item(0);
        if ($inlineType instanceof \DOMElement && 'complexType' === $inlineType->localName) {
            $anonName = Naming::toClassName($node->getAttribute('name'));
            $className = $this->buildComplexClass($inlineType, $anonName, $nestedNamespace);

            return new TypeInfo(kind: TypeKind::Class_, phpType: $className);
        }

        if ($inlineType instanceof \DOMElement) {
            $restriction = $this->query($xp, 'xs:restriction', $inlineType)->item(0);
            if (!$restriction instanceof \DOMElement) {
                return $this->fallbackScalar("inline simpleType without xs:restriction on '{$node->getAttribute('name')}', mapped to plain string");
            }

            /** @var \DOMNodeList<\DOMElement> $enumerations */
            $enumerations = $this->query($xp, 'xs:enumeration', $restriction);
            if ($enumerations->length > 0) {
                $anonName = Naming::toClassName($node->getAttribute('name')).'Enum';
                $base = $this->resolvePrimitiveOrNamedSimpleType($restriction, $restriction->getAttribute('base'));

                // nested under the owner's namespace, like inline complex types above, so two
                // unrelated owners with a same-named inline enum member don't collide on output
                return $this->toEnumResult($anonName, $enumerations, $base->phpType, $nestedNamespace);
            }

            $base = $this->resolvePrimitiveOrNamedSimpleType($restriction, $restriction->getAttribute('base'));

            return $this->mergeFacets($base, $restriction);
        }

        return $this->fallbackScalar("no @type/inline type definition on '{$node->getAttribute('name')}' (xs:anyType equivalent), mapped to plain string");
    }
```

- [ ] **Step 10: `makeProperty()` + `generate()`'s note-line**

Find (around L715-731):

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

Replace with:

```php
    /** Builds one property-model entry; shared by the simpleContent-value, element, and attribute sites in collectProperties(). */
    private function makeProperty(string $name, PropertyRole $role, bool $isArray, bool $nullable, TypeInfo $typeInfo, ?string $doc): Property
    {
        return new Property(
            phpName: PropertyRole::Text === $role ? $name : Naming::toPropName($name),
            xmlName: PropertyRole::Text === $role ? null : $name,
            role: $role,
            isArray: $isArray,
            nullable: $nullable,
            kind: match ($typeInfo->kind) {
                TypeKind::Scalar => 'scalar',
                TypeKind::Class_ => 'class',
                TypeKind::Enum => 'enum',
            },
            phpType: $typeInfo->phpType,
            dateOnly: $typeInfo->dateOnly,
            facets: $typeInfo->facets,
            namedType: $typeInfo->namedType,
            doc: $doc,
        );
    }
```

Find (in `generate()`, around L94-95 — only this one line changes, from Task 7's edit):

```php
                $typeInfo = $this->resolveParticleType($el, Naming::toClassName($local), $namespace);
                $this->note("root element '{$key}' aliases {$typeInfo['phpType']}");
```

Replace with:

```php
                $typeInfo = $this->resolveParticleType($el, Naming::toClassName($local), $namespace);
                $this->note("root element '{$key}' aliases {$typeInfo->phpType}");
```

- [ ] **Step 11: Fixture diff — capture "after" snapshot and compare**

Expected: no output (byte-identical).

- [ ] **Step 12: Re-run tests**

Run: `composer test`
Expected: PASS. If any test that asserts on `#[Assert\...]`/serializer attribute output fails, check whether it's exercising `SymfonyValidatorAttributeStrategy.php`'s `'scalar' === $property->kind` comparisons — those read `Property::$kind` (unaffected, still a string) via `makeProperty()`'s new `match` — a failure here means the `match` arm mapping is wrong, not that the boundary decision itself was wrong.

- [ ] **Step 13: Standard Verification Loop**

Expect the baseline to shrink by exactly 44 entries.

- [ ] **Step 14: Commit**

```bash
git add src/Generator.php phpstan-baseline.neon
git commit -m "refactor: migrate \$typeInfo array shape to TypeInfo/TypeKind value objects"
```

---

### Task 10: `collectProperties()` DOM guards + `extractDoc()` + `buildComplexClass()` cleanup (Cluster C)

**Files:**

- Modify: `../../src/Generator.php`

**Interfaces:** None changed — `collectProperties()`'s signature/return shape, `extractDoc()`'s signature, `buildComplexClass()`'s signature are all unchanged.

Root-cause note: the original code's complexContent branch had `/* @var DOMElement $ext */` — a **single-star** comment (`/* ... */`, not `/** ... */`) — which PHPStan does not parse as a type-hint annotation at all (only `/** @var ... */` is). This silently masked the fact that `$ext` genuinely can be non-`\DOMElement` at that point (if a `complexContent` has neither `xs:extension` nor `xs:restriction` — impossible for a well-formed XSD, but not something PHPStan can rule out statically). Fixed here with a real `instanceof` guard (warn-and-skip, matching the file's convention) instead of a fake type-hint comment.

Ground-truth errors fixed here (23): `collectProperties()` — L517 xpath/ownerDocument, L523/L529/L534/L545 four `item()` calls, L538 x3 (getAttribute + resolveQName's two params), L539 x2 (binOp on the resulting `mixed`), L557 x2 (foreach + query's context-node param type), L558 particle param type, L563 x2 binOp, L565 encapsed string, L597 xpath/ownerDocument, L598 `->length`, L610 `collectAttributes()`'s container param type. `extractDoc()` — L735 xpath/ownerDocument, L736 item(), L740 `trim()` on `preg_replace()`'s `string|null`. `buildComplexClass()` — L827 `substr()` on `strrpos()`'s `int|false`.

- [ ] **Step 1: Confirm current behavior**

Run: `composer test`
Expected: PASS.

- [ ] **Step 2: Fixture diff — capture "before" snapshot**

Same script as Task 7 Step 2, fresh run.

- [ ] **Step 3: Fix `collectProperties()`**

Find (the whole method, around L514-677):

```php
    /** @return array{properties: list<Property>, choiceGroups: list<array{fields: list<string>, required: bool}>} */
    private function collectProperties(\DOMElement $ctNode, string $ownerClassName, string $ownerNamespace): array
    {
        $xp = $this->xpath($ctNode->ownerDocument);

        $properties = [];

        // xs:complexContent/xs:simpleContent are mutually exclusive per the XSD schema-for-schema -
        // a complexType has at most one, so a combined query is unambiguous.
        $content = $xp->query('xs:complexContent | xs:simpleContent', $ctNode)->item(0);

        $contentContainer = $ctNode;
        $baseProperties = [];

        if ($content instanceof \DOMElement && 'complexContent' === $content->localName) {
            $ext = $xp->query('xs:extension', $content)->item(0);
            if (!$ext instanceof \DOMElement) {
                // xs:restriction narrows/redefines the base content model rather than adding to
                // it; treated like extension (union of base + local content) rather than
                // implemented properly. Warn loud instead of silently generating a wrong shape.
                $ext = $xp->query('xs:restriction', $content)->item(0);
                $this->warn("'{$ownerClassName}' uses complexContent/xs:restriction, treated as extension");
            }
            /* @var DOMElement $ext */
            [$baseNs, $baseLocal] = $this->resolveQName($ext, $ext->getAttribute('base'));
            $baseKey = $baseNs.'#'.$baseLocal;
            if ('' !== $baseLocal && 'anyType' !== $baseLocal && isset($this->complexTypes[$baseKey])) {
                $baseProperties = $this->resolveBaseProperties($baseKey);
            }
            $contentContainer = $ext;
        } elseif ($content instanceof \DOMElement) {
            $ext = $xp->query('xs:extension', $content)->item(0);
            /** @var \DOMElement $ext */
            $baseInfo = $this->resolvePrimitiveOrNamedSimpleType($ext, $ext->getAttribute('base'));
            $properties[] = $this->makeProperty('value', PropertyRole::Text, false, false, $baseInfo, null);
            $contentContainer = $ext;
        }

        $properties = [...$baseProperties, ...$properties];

        /** @var array<int, array{particle: \DOMElement, members: array{phpName: string, prop: Property}[], directChildCount: int}> keyed by spl_object_id() of the enclosing xs:choice particle */
        $choiceGroups = [];

        foreach ($xp->query('xs:sequence | xs:choice | xs:all', $contentContainer) as $particle) {
            foreach ($this->collectParticleElements($particle) as [$el, $choiceParticle]) {
                /** @var \DOMElement $el */
                $refAttr = $el->getAttribute('ref');
                if ('' !== $refAttr && !$el->hasAttribute('name')) {
                    [$refNs, $refLocal] = $this->resolveQName($el, $refAttr);
                    $refKey = $refNs.'#'.$refLocal;
                    if (!isset($this->elements[$refKey])) {
                        $this->warn("unknown element ref '{$refLocal}'");
                        continue;
                    }
                    $typeSource = $this->elements[$refKey];
                    $name = $typeSource->getAttribute('name');
                    $doc = $this->extractDoc($el) ?? $this->extractDoc($typeSource);
                } else {
                    $typeSource = $el;
                    $name = $el->getAttribute('name');
                    $doc = $this->extractDoc($el);
                }
                // default="..."/fixed="..." only legal on the actual declaration - $typeSource,
                // never a bare xs:element ref="..." site.
                $doc = $this->appendXsdDefaultHint($doc, $typeSource);

                $minOccurs = $el->hasAttribute('minOccurs') ? $el->getAttribute('minOccurs') : '1';
                $maxOccurs = $el->hasAttribute('maxOccurs') ? $el->getAttribute('maxOccurs') : '1';
                $isArray = 'unbounded' === $maxOccurs || (is_numeric($maxOccurs) && (int) $maxOccurs > 1);
                // xs:choice elements are mutually exclusive alternatives, not independently
                // required siblings - nullable regardless of the element's own minOccurs.
                $nullable = ('0' === $minOccurs || $choiceParticle instanceof \DOMElement) && !$isArray;

                $typeInfo = $this->resolveParticleType($typeSource, $ownerClassName, $ownerNamespace);

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
            }
        }

        foreach ($this->collectAttributes($contentContainer) as $attr) {
            $name = $attr->getAttribute('name');
            if ('' === $name) {
                $refAttr = $attr->getAttribute('ref');
                $this->warn('' !== $refAttr
                    ? "xs:attribute ref='{$refAttr}' is not supported, skipping"
                    : 'xs:attribute without name or ref, skipping');
                continue;
            }
            $use = $attr->hasAttribute('use') ? $attr->getAttribute('use') : 'optional';
            $typeInfo = $this->resolveParticleType($attr, $ownerClassName, $ownerNamespace);
            $doc = $this->appendXsdDefaultHint($this->extractDoc($attr), $attr);

            $properties[] = $this->makeProperty($name, PropertyRole::Attribute, false, 'required' !== $use, $typeInfo, $doc);
        }
```

Replace with (only the top through the attribute-collection `foreach` changes — everything from `// de-dup by phpName` onward, to the method's closing `}`, is unchanged, do not touch it):

```php
    /** @return array{properties: list<Property>, choiceGroups: list<array{fields: list<string>, required: bool}>} */
    private function collectProperties(\DOMElement $ctNode, string $ownerClassName, string $ownerNamespace): array
    {
        $xp = $this->xpath($this->ownerDocOf($ctNode));

        $properties = [];

        // xs:complexContent/xs:simpleContent are mutually exclusive per the XSD schema-for-schema -
        // a complexType has at most one, so a combined query is unambiguous.
        $content = $this->query($xp, 'xs:complexContent | xs:simpleContent', $ctNode)->item(0);

        $contentContainer = $ctNode;
        $baseProperties = [];

        if ($content instanceof \DOMElement && 'complexContent' === $content->localName) {
            $ext = $this->query($xp, 'xs:extension', $content)->item(0);
            if (!$ext instanceof \DOMElement) {
                // xs:restriction narrows/redefines the base content model rather than adding to
                // it; treated like extension (union of base + local content) rather than
                // implemented properly. Warn loud instead of silently generating a wrong shape.
                $ext = $this->query($xp, 'xs:restriction', $content)->item(0);
                $this->warn("'{$ownerClassName}' uses complexContent/xs:restriction, treated as extension");
            }
            if ($ext instanceof \DOMElement) {
                [$baseNs, $baseLocal] = $this->resolveQName($ext, $ext->getAttribute('base'));
                $baseKey = $baseNs.'#'.$baseLocal;
                if ('' !== $baseLocal && 'anyType' !== $baseLocal && isset($this->complexTypes[$baseKey])) {
                    $baseProperties = $this->resolveBaseProperties($baseKey);
                }
                $contentContainer = $ext;
            } else {
                $this->warn("'{$ownerClassName}' has complexContent without xs:extension or xs:restriction, skipping base resolution");
            }
        } elseif ($content instanceof \DOMElement) {
            $ext = $this->query($xp, 'xs:extension', $content)->item(0);
            if ($ext instanceof \DOMElement) {
                $baseInfo = $this->resolvePrimitiveOrNamedSimpleType($ext, $ext->getAttribute('base'));
                $properties[] = $this->makeProperty('value', PropertyRole::Text, false, false, $baseInfo, null);
                $contentContainer = $ext;
            } else {
                $this->warn("'{$ownerClassName}' has simpleContent without xs:extension, skipping value property");
            }
        }

        $properties = [...$baseProperties, ...$properties];

        /** @var array<int, array{particle: \DOMElement, members: array{phpName: string, prop: Property}[], directChildCount: int}> keyed by spl_object_id() of the enclosing xs:choice particle */
        $choiceGroups = [];

        foreach ($this->query($xp, 'xs:sequence | xs:choice | xs:all', $contentContainer) as $particle) {
            if (!$particle instanceof \DOMElement) {
                $this->warn("'{$ownerClassName}' has a non-element sequence/choice/all node, skipping");
                continue;
            }
            foreach ($this->collectParticleElements($particle) as [$el, $choiceParticle]) {
                $refAttr = $el->getAttribute('ref');
                if ('' !== $refAttr && !$el->hasAttribute('name')) {
                    [$refNs, $refLocal] = $this->resolveQName($el, $refAttr);
                    $refKey = $refNs.'#'.$refLocal;
                    if (!isset($this->elements[$refKey])) {
                        $this->warn("unknown element ref '{$refLocal}'");
                        continue;
                    }
                    $typeSource = $this->elements[$refKey];
                    $name = $typeSource->getAttribute('name');
                    $doc = $this->extractDoc($el) ?? $this->extractDoc($typeSource);
                } else {
                    $typeSource = $el;
                    $name = $el->getAttribute('name');
                    $doc = $this->extractDoc($el);
                }
                // default="..."/fixed="..." only legal on the actual declaration - $typeSource,
                // never a bare xs:element ref="..." site.
                $doc = $this->appendXsdDefaultHint($doc, $typeSource);

                $minOccurs = $el->hasAttribute('minOccurs') ? $el->getAttribute('minOccurs') : '1';
                $maxOccurs = $el->hasAttribute('maxOccurs') ? $el->getAttribute('maxOccurs') : '1';
                $isArray = 'unbounded' === $maxOccurs || (is_numeric($maxOccurs) && (int) $maxOccurs > 1);
                // xs:choice elements are mutually exclusive alternatives, not independently
                // required siblings - nullable regardless of the element's own minOccurs.
                $nullable = ('0' === $minOccurs || $choiceParticle instanceof \DOMElement) && !$isArray;

                $typeInfo = $this->resolveParticleType($typeSource, $ownerClassName, $ownerNamespace);

                $prop = $this->makeProperty($name, PropertyRole::Element, $isArray, $nullable, $typeInfo, $doc);
                $properties[] = $prop;

                if ($choiceParticle instanceof \DOMElement) {
                    $groupKey = spl_object_id($choiceParticle);
                    $choiceGroups[$groupKey] ??= [
                        'particle' => $choiceParticle,
                        'members' => [],
                        'directChildCount' => $this->query($xp, 'xs:element | xs:sequence | xs:choice | xs:all | xs:group', $choiceParticle)->length,
                    ];
                    // $prop's own identity (not a separately-tracked DOM node) is what the
                    // dedup-survivor check below compares against - "did this exact Property
                    // instance survive dedup under its phpName" tells apart "a same-named
                    // non-choice property (e.g. an xs:attribute) won the de-dup instead",
                    // without Property itself needing to carry DOM bookkeeping.
                    $choiceGroups[$groupKey]['members'][] = ['phpName' => $prop->phpName, 'prop' => $prop];
                }
            }
        }

        foreach ($this->collectAttributes($contentContainer) as $attr) {
            $name = $attr->getAttribute('name');
            if ('' === $name) {
                $refAttr = $attr->getAttribute('ref');
                $this->warn('' !== $refAttr
                    ? "xs:attribute ref='{$refAttr}' is not supported, skipping"
                    : 'xs:attribute without name or ref, skipping');
                continue;
            }
            $use = $attr->hasAttribute('use') ? $attr->getAttribute('use') : 'optional';
            $typeInfo = $this->resolveParticleType($attr, $ownerClassName, $ownerNamespace);
            $doc = $this->appendXsdDefaultHint($this->extractDoc($attr), $attr);

            $properties[] = $this->makeProperty($name, PropertyRole::Attribute, false, 'required' !== $use, $typeInfo, $doc);
        }
```

- [ ] **Step 4: Fix `extractDoc()`**

Find (around L733-743):

```php
    private function extractDoc(\DOMElement $node): ?string
    {
        $xp = $this->xpath($node->ownerDocument);
        $doc = $xp->query('xs:annotation/xs:documentation', $node)->item(0);
        if (!$doc instanceof \DOMElement) {
            return null;
        }
        $text = trim(preg_replace('/\s+/', ' ', $doc->textContent));

        return '' === $text ? null : $text;
    }
```

Replace with:

```php
    private function extractDoc(\DOMElement $node): ?string
    {
        $xp = $this->xpath($this->ownerDocOf($node));
        $doc = $this->query($xp, 'xs:annotation/xs:documentation', $node)->item(0);
        if (!$doc instanceof \DOMElement) {
            return null;
        }
        $normalized = preg_replace('/\s+/', ' ', $doc->textContent);
        if (null === $normalized) {
            return null;
        }
        $text = trim($normalized);

        return '' === $text ? null : $text;
    }
```

- [ ] **Step 5: Fix `buildComplexClass()`'s `substr()`/`strrpos()`**

Find (around L826-828):

```php
            $fqcn = $p->phpType;
            if (substr($fqcn, 0, strrpos($fqcn, '\\')) === $namespace) {
                $sameNamespaceTypes[$fqcn] = true;
                continue;
            }
```

Replace with:

```php
            $fqcn = $p->phpType;
            $lastBackslash = strrpos($fqcn, '\\');
            if (false !== $lastBackslash && substr($fqcn, 0, $lastBackslash) === $namespace) {
                $sameNamespaceTypes[$fqcn] = true;
                continue;
            }
```

- [ ] **Step 6: Fixture diff — capture "after" snapshot and compare**

Expected: no output (byte-identical).

- [ ] **Step 7: Re-run tests**

Run: `composer test`
Expected: PASS.

- [ ] **Step 8: Standard Verification Loop**

Expect the baseline to shrink by exactly 23 entries.

- [ ] **Step 9: Commit**

```bash
git add src/Generator.php phpstan-baseline.neon
git commit -m "fix: guard collectProperties()/extractDoc() DOM access, buildComplexClass() strrpos guard"
```

---

### Task 11: `../../src/Attribute/SymfonyValidatorAttributeStrategy.php` iterable type fixes (Cluster D)

**Files:**

- Modify: `../../src/Attribute/SymfonyValidatorAttributeStrategy.php`

**Interfaces:** `attributesFor(Property $property): array` implements `PropertyAttributeStrategy` (interface already declares `list<array{fqcn: string, args: string}>` — this task only makes the implementation's own docblocks match what the interface already promises). No behavior change — pure annotation additions.

Ground-truth errors (7): `attributesFor()`'s two `return` statements don't match the interface's declared shape because the method itself has no matching `@return` (L50, L53); `presenceConstraint()` (L56) and `facetConstraints()` (L83) return arrays with no value type; `minMaxArgs()`'s `$facets` param has no value type (L131), cascading into two `mixed`-cast-to-string errors when read back out (L135, L138).

- [ ] **Step 1: Confirm current behavior**

Run: `vendor/bin/phpunit tests/SymfonyValidatorAttributeStrategyTest.php`
Expected: PASS.

- [ ] **Step 2: Apply the fix**

Find (around L39-54):

```php
final class SymfonyValidatorAttributeStrategy implements PropertyAttributeStrategy
{
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
```

Replace with:

```php
final class SymfonyValidatorAttributeStrategy implements PropertyAttributeStrategy
{
    /** @return list<array{fqcn: string, args: string}> */
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

    /** @return list<array{fqcn: string, args: string}> */
    private function presenceConstraint(Property $property): array
    {
```

Find (around L82-83):

```php
    /** @param array{length?: int, minLength?: int, maxLength?: int, pattern?: string, minInclusive?: string, maxInclusive?: string, minExclusive?: string, maxExclusive?: string, totalDigits?: int, fractionDigits?: int} $facets */
    private function facetConstraints(array $facets): array
    {
```

Replace with:

```php
    /**
     * @param array{length?: int, minLength?: int, maxLength?: int, pattern?: string, minInclusive?: string, maxInclusive?: string, minExclusive?: string, maxExclusive?: string, totalDigits?: int, fractionDigits?: int} $facets
     *
     * @return list<array{fqcn: string, args: string}>
     */
    private function facetConstraints(array $facets): array
    {
```

Find (around L131):

```php
    private function minMaxArgs(array $facets, string $minKey, string $maxKey): string
    {
```

Replace with:

```php
    /** @param array{length?: int, minLength?: int, maxLength?: int, pattern?: string, minInclusive?: string, maxInclusive?: string, minExclusive?: string, maxExclusive?: string, totalDigits?: int, fractionDigits?: int} $facets */
    private function minMaxArgs(array $facets, string $minKey, string $maxKey): string
    {
```

- [ ] **Step 3: Re-run tests**

Run: `vendor/bin/phpunit tests/SymfonyValidatorAttributeStrategyTest.php`
Expected: PASS.

- [ ] **Step 4: Standard Verification Loop**

Expect the baseline to shrink by exactly 7 entries.

- [ ] **Step 5: Commit**

```bash
git add src/Attribute/SymfonyValidatorAttributeStrategy.php phpstan-baseline.neon
git commit -m "fix: type-safety pass over SymfonyValidatorAttributeStrategy"
```

---

### Task 12: `../../tests/GeneratorTest.php` infra type fixes (Cluster D)

**Files:**

- Modify: `../../tests/GeneratorTest.php`

**Interfaces:** None — all changes are inside private test-helper methods and one inline test assertion.

Ground-truth errors (4): `generate()`'s test helper passes `glob()`'s `list<string>|false` straight into `Config::$xsdPaths` (L1236, declared `array<string>`); `readGenerated()`'s `file_get_contents()` result returned as `string` without a guard (L1251); a `\ReflectionClass` constructed from a plain `string` instead of a `class-string` (L578); `assertCount()` given `glob()`'s `list<string>|false` directly (L900).

- [ ] **Step 1: Confirm current behavior**

Run: `vendor/bin/phpunit tests/GeneratorTest.php`
Expected: PASS.

- [ ] **Step 2: Fix the `generate()` helper**

Find (around L1233-1243):

```php
    /** @param array<string, NamespaceMapping>|null $namespaceMap */
    private function generate(?PropertyAttributeStrategy $attributeStrategy = null, ?array $namespaceMap = null): void
    {
        $config = new Config(
            xsdPaths: glob($this->tmpDir.'/xsd/*.xsd'),
            namespaceMap: $namespaceMap ?? [
                self::TEST_NS => new NamespaceMapping('TestGen', $this->tmpDir.'/out'),
            ],
            attributeStrategy: $attributeStrategy ?? new SymfonySerializerAttributeStrategy(),
        );

        new Generator($config)->generate();
    }
```

Replace with:

```php
    /** @param array<string, NamespaceMapping>|null $namespaceMap */
    private function generate(?PropertyAttributeStrategy $attributeStrategy = null, ?array $namespaceMap = null): void
    {
        $xsdPaths = glob($this->tmpDir.'/xsd/*.xsd');
        $this->assertIsArray($xsdPaths);

        $config = new Config(
            xsdPaths: $xsdPaths,
            namespaceMap: $namespaceMap ?? [
                self::TEST_NS => new NamespaceMapping('TestGen', $this->tmpDir.'/out'),
            ],
            attributeStrategy: $attributeStrategy ?? new SymfonySerializerAttributeStrategy(),
        );

        new Generator($config)->generate();
    }
```

- [ ] **Step 3: Fix `readGenerated()`**

Find (around L1246-1252):

```php
    private function readGenerated(string $filename): string
    {
        $path = $this->tmpDir.'/out/'.$filename;
        $this->assertFileExists($path);

        return file_get_contents($path);
    }
```

Replace with:

```php
    private function readGenerated(string $filename): string
    {
        $path = $this->tmpDir.'/out/'.$filename;
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        return $contents;
    }
```

- [ ] **Step 4: Fix the `\ReflectionClass` construction**

Find (around L578):

```php
        $reflection = new \ReflectionClass('TestGen\CartItemType');
```

Replace with:

```php
        $reflection = new \ReflectionClass(\TestGen\CartItemType::class);
```

(this compiles and analyzes fine even though `TestGen\CartItemType` only exists at test runtime after the `require` two lines above — `::class` resolves to the literal FQCN string without triggering autoload or an existence check)

- [ ] **Step 5: Fix the `glob()`-into-`assertCount()` call**

Find (around L900):

```php
        $this->assertCount(1, glob($this->tmpDir.'/out/ColorEnum.php'));
```

Replace with:

```php
        $colorEnumFiles = glob($this->tmpDir.'/out/ColorEnum.php');
        $this->assertIsArray($colorEnumFiles);
        $this->assertCount(1, $colorEnumFiles);
```

- [ ] **Step 6: Re-run tests**

Run: `vendor/bin/phpunit tests/GeneratorTest.php`
Expected: PASS.

- [ ] **Step 7: Standard Verification Loop**

Expect the baseline to shrink by exactly 4 entries.

- [ ] **Step 8: Commit**

```bash
git add tests/GeneratorTest.php phpstan-baseline.neon
git commit -m "fix: type-safety pass over GeneratorTest infra helpers"
```

---

### Task 13: `$violations[0]` -> `$violations->get(0)` + anonymous-class docblocks (Cluster D)

**Files:**

- Modify: `../../tests/SymfonyValidatorAttributeStrategyTest.php`
- Modify: `../../tests/Attribute/SemanticTypeAttributeStrategyTest.php`
- Modify: `../../tests/Validator/ExactlyOneOfValidatorTest.php`

**Interfaces:** Adds one new `private function readGenerated(string $filename): string` helper to `SymfonyValidatorAttributeStrategyTest`, mirroring `GeneratorTest::readGenerated()` — internal to that test class only, nothing outside it consumes this.

Root cause 1: `symfony/validator`'s `ConstraintViolationListInterface::offsetGet()` (used by `$violations[0]`) is typed to allow a `null` result; every call site here is already preceded by `$this->assertGreaterThanOrEqual(1, \count($violations))`, so index `0` is guaranteed to exist at runtime — `ConstraintViolationList::get(int $offset): ConstraintViolationInterface` (non-nullable, the same list's own named accessor) is the exact, minimal fix, no behavior change.

Root cause 2: unlike `../../tests/GeneratorTest.php`, `../../tests/SymfonyValidatorAttributeStrategyTest.php` has **no** shared `readGenerated()` helper — it calls `file_get_contents($this->tmpDir.'/out/<Name>.php')` inline at 5 separate sites. Some of those sites already paper over the resulting `string|false` with an ad-hoc `(string) $code` cast at the point of use (harmless but inconsistent); the 2 `substr_count()` sites and 3 `assertMatchesRegularExpression()` sites don't, which is exactly what PHPStan flags. Fixed the same way as `GeneratorTest.php`'s equivalent method: add a private `readGenerated()` helper to this class too and route all 5 sites through it.

Ground-truth errors: `../../tests/SymfonyValidatorAttributeStrategyTest.php` (20: 15 `$violations[0]->` sites + 2 `substr_count()` + 3 `assertMatchesRegularExpression()`, the last 5 fixed by the new `readGenerated()` helper). `../../tests/Attribute/SemanticTypeAttributeStrategyTest.php` (2: `$violations[0]->` at L105, L111). `../../tests/Validator/ExactlyOneOfValidatorTest.php` (2: two anonymous classes' untyped `$items` property, L61, L67). Total 24.

- [ ] **Step 1: Confirm current behavior**

Run: `vendor/bin/phpunit tests/SymfonyValidatorAttributeStrategyTest.php tests/Attribute/SemanticTypeAttributeStrategyTest.php tests/Validator/ExactlyOneOfValidatorTest.php`
Expected: PASS.

- [ ] **Step 2: Add a `readGenerated()` helper to `../../tests/SymfonyValidatorAttributeStrategyTest.php` and route all 5 inline `file_get_contents()` sites through it**

Find (the end of the class, after `writeXsdAndGenerate()`'s closing `}`, around the last lines of the file):

```php
        new Generator($config)->generate();
    }
}
```

Replace with:

```php
        new Generator($config)->generate();
    }

    private function readGenerated(string $filename): string
    {
        $path = $this->tmpDir.'/out/'.$filename;
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        return $contents;
    }
}
```

Then replace each of the 5 inline calls:

```bash
sed -i '' \
  -e "s/file_get_contents(\$this->tmpDir\.'\/out\/ContactType\.php')/\$this->readGenerated('ContactType.php')/" \
  -e "s/file_get_contents(\$this->tmpDir\.'\/out\/FacetType\.php')/\$this->readGenerated('FacetType.php')/" \
  -e "s/file_get_contents(\$this->tmpDir\.'\/out\/PersonType\.php')/\$this->readGenerated('PersonType.php')/" \
  -e "s/file_get_contents(\$this->tmpDir\.'\/out\/ChainType\.php')/\$this->readGenerated('ChainType.php')/" \
  -e "s/file_get_contents(\$this->tmpDir\.'\/out\/MarkerHolderType\.php')/\$this->readGenerated('MarkerHolderType.php')/" \
  tests/SymfonyValidatorAttributeStrategyTest.php
```

Verify all 5 landed and no inline `file_get_contents` calls remain in the file:

```bash
grep -c "readGenerated('" tests/SymfonyValidatorAttributeStrategyTest.php   # expect 5
grep -c "file_get_contents(\$this->tmpDir" tests/SymfonyValidatorAttributeStrategyTest.php   # expect 0
```

(the pre-existing `(string) $code` casts elsewhere in the file, e.g. in `assertStringContainsString()` calls, are now redundant but harmless — leave them, not in scope for this task)

- [ ] **Step 3: Replace every `$violations[0]->` with `$violations->get(0)->` in both files**

Run:

```bash
sed -i '' 's/\$violations\[0\]->/$violations->get(0)->/g' tests/SymfonyValidatorAttributeStrategyTest.php tests/Attribute/SemanticTypeAttributeStrategyTest.php
```

Verify the replacement count matches exactly (15 + 2 = 17 total):

```bash
grep -c '\$violations->get(0)->' tests/SymfonyValidatorAttributeStrategyTest.php tests/Attribute/SemanticTypeAttributeStrategyTest.php
grep -c '\$violations\[0\]' tests/SymfonyValidatorAttributeStrategyTest.php tests/Attribute/SemanticTypeAttributeStrategyTest.php
```

Expected: first command reports `15` and `2` respectively; second command reports `0` and `0` (no leftover `[0]`-style access).

- [ ] **Step 4: Fix `../../tests/Validator/ExactlyOneOfValidatorTest.php`'s anonymous classes**

Find (around L58-70):

```php
    public function testEmptyArrayCountsAsNotSet(): void
    {
        // array-typed choice fields (xs:element maxOccurs="unbounded") default to [], never
        // null - must count as "not set" the same as null, or a repeated-element choice branch
        // would always look "set" even when untouched.
        $validator = Validation::createValidator();
        $constraint = new ExactlyOneOf(fields: ['items', 'single']);

        $untouched = new class {
            public array $items = [];
            public ?string $single = 'x';
        };
        $this->assertCount(0, $validator->validate($untouched, $constraint));

        $populated = new class {
            public array $items = ['a'];
            public ?string $single = null;
        };
        $this->assertCount(0, $validator->validate($populated, $constraint));
    }
```

Replace with:

```php
    public function testEmptyArrayCountsAsNotSet(): void
    {
        // array-typed choice fields (xs:element maxOccurs="unbounded") default to [], never
        // null - must count as "not set" the same as null, or a repeated-element choice branch
        // would always look "set" even when untouched.
        $validator = Validation::createValidator();
        $constraint = new ExactlyOneOf(fields: ['items', 'single']);

        $untouched = new class {
            /** @var list<string> */
            public array $items = [];
            public ?string $single = 'x';
        };
        $this->assertCount(0, $validator->validate($untouched, $constraint));

        $populated = new class {
            /** @var list<string> */
            public array $items = ['a'];
            public ?string $single = null;
        };
        $this->assertCount(0, $validator->validate($populated, $constraint));
    }
```

- [ ] **Step 5: Re-run tests**

Run: `vendor/bin/phpunit tests/SymfonyValidatorAttributeStrategyTest.php tests/Attribute/SemanticTypeAttributeStrategyTest.php tests/Validator/ExactlyOneOfValidatorTest.php`
Expected: PASS.

- [ ] **Step 6: Standard Verification Loop**

Expect the baseline to shrink by exactly 24 entries (20 + 2 + 2).

- [ ] **Step 7: Commit**

```bash
git add tests/SymfonyValidatorAttributeStrategyTest.php tests/Attribute/SemanticTypeAttributeStrategyTest.php tests/Validator/ExactlyOneOfValidatorTest.php phpstan-baseline.neon
git commit -m "fix: type-safety pass over validator constraint assertion tests"
```

---

### Task 14: Delete the baseline, update `phpstan.dist.neon` and `../backlog.md`, final verification

**Files:**

- Delete: `phpstan-baseline.neon`
- Modify: `phpstan.dist.neon`
- Modify: `../backlog.md`

**Interfaces:** None.

By this point every prior task's Standard Verification Loop should have shrunk the baseline to 0 entries (12+25+44+23+7+4+21+10+15+7+3+1 = 172... plus Task 3's 7 = confirm the running total below before proceeding).

- [ ] **Step 1: Confirm the baseline is empty**

Run: `cat phpstan-baseline.neon`
Expected: a `parameters: ignoreErrors: []` shell (or equivalent near-empty structure) — no entries left. If any entries remain, STOP — go back and check which earlier task's fix didn't fully land (re-run `vendor/bin/phpstan analyse --error-format=raw` and compare against the remaining baseline entries' `path`/`message` to identify the gap) before continuing.

- [ ] **Step 2: Delete the baseline file**

```bash
git rm phpstan-baseline.neon
```

- [ ] **Step 3: Remove the `includes:` block from `phpstan.dist.neon`**

Find (full current file):

```yaml
includes:
  - phpstan-baseline.neon

parameters:
  level: max
  paths:
    - src
    - bin
    - tests
```

Replace with:

```yaml
parameters:
  level: max
  paths:
    - src
    - bin
    - tests
```

- [ ] **Step 4: Update `../backlog.md`**

Find the `## Static analysis` section (its full current content — two bullets on `$typeInfo`'s untyped array shape and `ext-dom`'s own typing) and the `## Resolved` heading immediately after it.

Remove the entire `## Static analysis` section (both bullets are now fully resolved — `$typeInfo` is `TypeInfo`, and every remaining raw `ext-dom` access has an `instanceof \DOMElement` guard). Add one new bullet at the **top** of the existing `## Resolved` list (matching the Property value object entry's own style directly below it):

```markdown
## Resolved

- **`phpstan-baseline.neon` eliminated entirely** — the two remaining root causes from the old
  "Static analysis" section above are both fixed: `$typeInfo`'s untyped array shape replaced with
  a `TypeInfo` value object + `TypeKind` enum (mirroring the `Property`/`PropertyRole` pattern),
  and every remaining raw `\DOMNodeList` iteration site in `Generator.php` got the codebase's
  existing `instanceof \DOMElement` guard idiom. `vendor/bin/phpstan analyse` runs clean with no
  baseline at all (see `docs/specs/2026-08-27-phpstan-baseline-zero-design.md`).
- **`makeProperty()`'s untyped array property bag** — replaced with a `Property` value object +
  ...
```

(the rest of the existing `## Resolved` list — starting from `**makeProperty()`'s untyped array property bag**` — is unchanged, just now preceded by the new bullet above it)

- [ ] **Step 5: Full verification**

Run: `composer phpstan`
Expected: `[OK] No errors`

Run: `composer test`
Expected: full suite green (72+ tests, exact count may have grown slightly if any task added an assertion — none of this plan's tasks add new test _methods_, only guard/type assertions inside existing ones).

Run the fixture-diff script from Global Constraints one final time, comparing the CURRENT generator's output against a snapshot taken from the commit just before Task 7 (the first DOM-touching task) — if a local copy of that snapshot wasn't kept, instead just confirm `../../tests/OfficialSchemaFixtureTest.php` still passes (it asserts on this exact fixture's generated output) as the equivalent end-to-end check:

```bash
vendor/bin/phpunit tests/OfficialSchemaFixtureTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add phpstan.dist.neon docs/backlog.md
git commit -m "chore: delete phpstan-baseline.neon, PHPStan runs clean with no baseline"
```

## Self-Review Notes (for the plan author, not part of execution)

- **Spec coverage:** Cluster A (Tasks 1-2), Cluster B (Tasks 6, 9), Cluster C (Tasks 7, 8, 10), Cluster D (Tasks 3, 4, 5, 11, 12, 13), final step (Task 14) — every spec section has at least one task. The spec's Edge Cases section (constant-with-object-default compile check, `mergeFacets()`'s immutable reconstruction) are both called out explicitly in Task 9's steps.
- **Placeholder scan:** every step shows real code, not a description of code; every "Expected" line names a concrete, checkable outcome.
- **Type consistency:** `TypeInfo`/`TypeKind` signatures introduced in Task 6 are used identically (same constructor arg names, same enum case names) in every later task that touches them (9, 10 read-only via `$typeInfo->phpType` etc.).
- **Entry-count arithmetic:** Task 1 (10) + Task 2 (15) + Task 3 (7) + Task 4 (3) + Task 5 (1) + Task 6 (0) + Task 7 (12) + Task 8 (25) + Task 9 (44) + Task 10 (23) + Task 11 (7) + Task 12 (4) + Task 13 (24) = 175, matching the ground-truth total exactly (verified against `vendor/bin/phpstan analyse` run with the baseline stripped, at plan-writing time). Task 14's Step 1 (confirm the baseline is actually empty) is still the authoritative runtime check — treat this arithmetic as a cross-check, not a substitute for it.
