<?php

declare(strict_types=1);

namespace Xsd2Php\Tests;

use PHPUnit\Framework\TestCase;
use Xsd2Php\Attribute\SymfonySerializerAttributeStrategy;
use Xsd2Php\Config;
use Xsd2Php\Generator;
use Xsd2Php\NamespaceMapping;

/**
 * Runs the generator against the W3C XML Schema Primer's purchase-order example
 * (tests/fixtures/w3c-purchase-order.xsd) - an officially published, publicly documented schema,
 * independent of any specific customer schema. Confirms the generator works end-to-end against a
 * real-world schema nobody here authored, not just against this test suite's own synthetic
 * fixtures.
 */
final class OfficialSchemaFixtureTest extends TestCase
{
    use RemovesTempDir;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/xsd2php-po-test-'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testGeneratesFromTheOfficialPurchaseOrderSchema(): void
    {
        $config = new Config(
            xsdPaths: [__DIR__.'/fixtures/w3c-purchase-order.xsd'],
            namespaceMap: ['' => new NamespaceMapping('PurchaseOrder', $this->tmpDir)],
            attributeStrategy: new SymfonySerializerAttributeStrategy(),
        );

        $written = new Generator($config)->generate();

        $this->assertSame(4, $written);

        $orderCode = file_get_contents($this->tmpDir.'/PurchaseOrderType.php');
        $this->assertStringContainsString('public USAddress $shipTo,', (string) $orderCode);
        $this->assertStringContainsString('public Items $items,', (string) $orderCode);
        // ref="comment" with minOccurs="0" -> nullable, type resolved from the global element decl
        $this->assertStringContainsString('public ?string $comment = null,', (string) $orderCode);
        // attribute type="xsd:date" -> \DateTimeImmutable, day-only Context
        $this->assertStringContainsString('public ?\DateTimeImmutable $orderDate = null,', (string) $orderCode);

        $addressCode = file_get_contents($this->tmpDir.'/USAddress.php');
        // fixed="US" on the attribute surfaces as a doc hint, doesn't change nullability
        $this->assertStringContainsString('(XSD-Fixed: US)', (string) $addressCode);
        $this->assertStringContainsString('public ?string $country = null,', (string) $addressCode);

        $itemsCode = file_get_contents($this->tmpDir.'/Items.php');
        // minOccurs="0" maxOccurs="unbounded" on the anonymous inline item complexType -> array
        $this->assertStringContainsString('public array $item = [],', (string) $itemsCode);
    }
}
