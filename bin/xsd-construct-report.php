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

/** @return string[] absolute paths to every *.xsd file under $path (or [$path] if it's a file) */
function collectXsdFiles(string $path): array
{
    if (is_file($path)) {
        return [$path];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'xsd') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

/** @param string[] $files @return array<string, int> label => total occurrence count across all files */
function countConstructs(array $files): array
{
    $definitions = constructDefinitions();
    $counts = array_fill_keys(array_keys($definitions), 0);

    foreach ($files as $file) {
        $dom = new DOMDocument();
        if (!$dom->load($file)) {
            fwrite(STDERR, "WARN: failed to parse '{$file}', skipping\n");
            continue;
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('xs', XS_NS);

        foreach ($definitions as $label => $expression) {
            $counts[$label] += $xp->query($expression)->length;
        }
    }

    return $counts;
}

function main(array $argv): int
{
    $args = array_slice($argv, 1);
    $asJson = in_array('--json', $args, true);
    $args = array_values(array_filter($args, static fn (string $a): bool => $a !== '--json'));

    if (count($args) !== 1) {
        fwrite(STDERR, "Usage: php xsd-construct-report.php <xsd-dir-or-file> [--json]\n");
        return 1;
    }

    $target = $args[0];
    if (!file_exists($target)) {
        fwrite(STDERR, "'{$target}' does not exist.\n");
        return 1;
    }

    $files = collectXsdFiles($target);
    if ($files === []) {
        fwrite(STDERR, "No *.xsd files found under '{$target}'.\n");
        return 1;
    }

    $counts = countConstructs($files);

    if ($asJson) {
        echo json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return 0;
    }

    $labelWidth = max(array_map('strlen', array_keys($counts)));
    fwrite(STDOUT, sprintf("Scanned %d *.xsd file(s) under '%s':\n\n", count($files), $target));
    foreach ($counts as $label => $count) {
        fwrite(STDOUT, sprintf("  %-{$labelWidth}s  %d\n", $label, $count));
    }

    return 0;
}

exit(main($argv));
