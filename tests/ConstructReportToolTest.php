<?php

declare(strict_types=1);

namespace Xsd2Php\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Exercises bin/xsd-construct-report.php as a real subprocess (not by extracting its functions
 * into a class and unit-testing those) - it's a standalone CLI tool by design, schema-agnostic and
 * independent of any specific package class, so testing it the way a user actually invokes it is
 * the accurate check.
 */
final class ConstructReportToolTest extends TestCase
{
    use RemovesTempDir;

    private string $tmpDir;
    private string $toolPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/xsd2php-report-test-'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0o777, true);
        $this->toolPath = __DIR__.'/../bin/xsd-construct-report.php';
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testCountsConstructsAcrossAllXsdFilesInADirectory(): void
    {
        file_put_contents($this->tmpDir.'/a.xsd', <<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns="urn:test" targetNamespace="urn:test">
              <xs:simpleType name="ColorEnum">
                <xs:restriction base="xs:string">
                  <xs:enumeration value="Red"/>
                  <xs:enumeration value="Green"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="Color" type="ColorEnum"/>
                </xs:sequence>
                <xs:attribute name="Id" type="xs:string" use="prohibited"/>
              </xs:complexType>
            </xs:schema>
            XSD);
        // a second file in the same directory - counts must sum across both, not just the first.
        file_put_contents($this->tmpDir.'/b.xsd', <<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns="urn:test-b" targetNamespace="urn:test-b">
              <xs:complexType name="OtherType">
                <xs:choice>
                  <xs:element name="A" type="xs:string"/>
                  <xs:element name="B" type="xs:string"/>
                </xs:choice>
              </xs:complexType>
            </xs:schema>
            XSD);

        $counts = $this->runTool($this->tmpDir);

        $this->assertSame(1, $counts['xs:sequence']);
        $this->assertSame(1, $counts['xs:choice']);
        $this->assertSame(2, $counts['xs:enumeration']);
        $this->assertSame(1, $counts['use="prohibited"']);
        $this->assertSame(0, $counts['xs:any']);
    }

    public function testAcceptsASingleFileNotJustADirectory(): void
    {
        $file = $this->tmpDir.'/single.xsd';
        file_put_contents($file, <<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns="urn:test" targetNamespace="urn:test">
              <xs:complexType name="PersonType">
                <xs:all>
                  <xs:element name="Name" type="xs:string"/>
                </xs:all>
              </xs:complexType>
            </xs:schema>
            XSD);

        $counts = $this->runTool($file);

        $this->assertSame(1, $counts['xs:all']);
    }

    public function testExitsNonZeroWhenNoXsdFilesFound(): void
    {
        exec('php '.escapeshellarg($this->toolPath).' '.escapeshellarg($this->tmpDir).' 2>&1', $output, $exitCode);

        $this->assertSame(1, $exitCode);
    }

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
}
