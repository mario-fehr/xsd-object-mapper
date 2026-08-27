<?php

declare(strict_types=1);

namespace Xsd2Php\Tests;

use PHPUnit\Framework\TestCase;

/** Exercises bin/check-fixture-drift.php as a real subprocess against a throwaway copy of tests/fixtures/. */
final class FixtureDriftToolTest extends TestCase
{
    use RemovesTempDir;

    private string $tmpDir;
    private string $toolPath;
    private string $fixturesDir;
    private string $baselineFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/xsd2php-drift-test-'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir.'/bin', 0o777, true);
        mkdir($this->tmpDir.'/tests/fixtures', 0o777, true);

        // check-fixture-drift.php resolves its paths relative to its own location - copy it
        // alongside a throwaway fixtures/ dir so this test never touches the real one.
        copy(__DIR__.'/../bin/check-fixture-drift.php', $this->tmpDir.'/bin/check-fixture-drift.php');
        copy(__DIR__.'/../bin/xsd-construct-report.php', $this->tmpDir.'/bin/xsd-construct-report.php');

        $this->toolPath = $this->tmpDir.'/bin/check-fixture-drift.php';
        $this->fixturesDir = $this->tmpDir.'/tests/fixtures';
        $this->baselineFile = $this->fixturesDir.'/coverage-baseline.json';
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testWritesAnInitialBaselineWhenNoneExists(): void
    {
        file_put_contents($this->fixturesDir.'/a.xsd', $this->minimalSchema(sequenceCount: 1));

        [, $exitCode] = $this->runTool();

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->baselineFile);
        $baselineJson = file_get_contents($this->baselineFile);
        $this->assertIsString($baselineJson);
        $baseline = json_decode($baselineJson, true);
        $this->assertIsArray($baseline);
        $this->assertSame(1, $baseline['xs:sequence']);
    }

    public function testReportsNoDriftWhenFixturesMatchTheBaseline(): void
    {
        file_put_contents($this->fixturesDir.'/a.xsd', $this->minimalSchema(sequenceCount: 1));
        $this->runTool(); // writes the initial baseline

        [$output, $exitCode] = $this->runTool();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No drift', $output);
    }

    public function testDetectsDriftAndUpdatesBaselineOnRequest(): void
    {
        file_put_contents($this->fixturesDir.'/a.xsd', $this->minimalSchema(sequenceCount: 1));
        $this->runTool(); // baseline: xs:sequence = 1

        // a fixture changed - now 2 xs:sequence occurrences
        file_put_contents($this->fixturesDir.'/a.xsd', $this->minimalSchema(sequenceCount: 2));

        [$output, $exitCode] = $this->runTool();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('xs:sequence', $output);
        $this->assertStringContainsString('1 -> 2', $output);

        [, $exitCode] = $this->runTool(['--update-baseline']);
        $this->assertSame(0, $exitCode);
        $baselineJson = file_get_contents($this->baselineFile);
        $this->assertIsString($baselineJson);
        $baseline = json_decode($baselineJson, true);
        $this->assertIsArray($baseline);
        $this->assertSame(2, $baseline['xs:sequence']);

        [$output, $exitCode] = $this->runTool();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No drift', $output);
    }

    private function minimalSchema(int $sequenceCount): string
    {
        $types = '';
        for ($i = 0; $i < $sequenceCount; ++$i) {
            $types .= "<xs:complexType name=\"Type{$i}\"><xs:sequence><xs:element name=\"A\" type=\"xs:string\"/></xs:sequence></xs:complexType>\n";
        }

        return <<<XSD
            <?xml version="1.0"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
            {$types}
            </xs:schema>
            XSD;
    }

    /**
     * @param list<string> $extraArgs
     *
     * @return array{0: string, 1: int} [combined stdout+stderr, exit code]
     */
    private function runTool(array $extraArgs = []): array
    {
        $cmd = 'php '.escapeshellarg($this->toolPath).' '.implode(' ', array_map(escapeshellarg(...), $extraArgs)).' 2>&1';
        exec($cmd, $output, $exitCode);

        return [implode("\n", $output), $exitCode];
    }
}
