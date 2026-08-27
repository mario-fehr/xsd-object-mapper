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

const FIXTURES_DIR = __DIR__ . '/../tests/fixtures';
const REPORT_TOOL = __DIR__ . '/xsd-construct-report.php';
const BASELINE_FILE = __DIR__ . '/../tests/fixtures/coverage-baseline.json';

function currentCounts(): array
{
    $json = shell_exec('php ' . escapeshellarg(REPORT_TOOL) . ' ' . escapeshellarg(FIXTURES_DIR) . ' --json');
    if ($json === null || $json === false) {
        fwrite(STDERR, "Failed to run xsd-construct-report.php.\n");
        exit(1);
    }
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Unexpected report tool output.\n");
        exit(1);
    }
    return $decoded;
}

function main(array $argv): int
{
    $updateBaseline = in_array('--update-baseline', array_slice($argv, 1), true);
    $current = currentCounts();

    if (!file_exists(BASELINE_FILE)) {
        fwrite(STDOUT, "No baseline yet at " . BASELINE_FILE . " - writing the current counts as the initial baseline.\n");
        file_put_contents(BASELINE_FILE, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return 0;
    }

    $baseline = json_decode(file_get_contents(BASELINE_FILE), true, flags: JSON_THROW_ON_ERROR);

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

    if ($changed === []) {
        fwrite(STDOUT, "No drift - tests/fixtures/*.xsd construct usage matches the recorded baseline.\n");
        return 0;
    }

    fwrite(STDOUT, "Construct usage drift detected against " . BASELINE_FILE . ":\n\n");
    foreach ($changed as $label => $diff) {
        $before = $diff['before'];
        $after = $diff['after'] ?? 'removed from report tool';
        $flag = $before === 0 && is_int($diff['after']) && $diff['after'] > 0 ? '  <-- newly exercised by a fixture' : '';
        fwrite(STDOUT, sprintf("  %-40s %s -> %s%s\n", $label, $before, $after, $flag));
    }

    if ($updateBaseline) {
        file_put_contents(BASELINE_FILE, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        fwrite(STDOUT, "\nBaseline updated.\n");
        return 0;
    }

    fwrite(STDOUT, "\nRun with --update-baseline once you've reviewed the drift, to record the new counts.\n");
    return 1;
}

exit(main($argv));
