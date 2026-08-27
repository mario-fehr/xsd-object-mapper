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
        $this->tmpDir = sys_get_temp_dir() . '/xsd2php-po-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testGeneratesFromTheOfficialPurchaseOrderSchema(): void
    {
        $config = new Config(
            xsdPaths: [__DIR__ . '/fixtures/w3c-purchase-order.xsd'],
            namespaceMap: ['' => new NamespaceMapping('PurchaseOrder', $this->tmpDir)],
            attributeStrategy: new SymfonySerializerAttributeStrategy(),
        );

        $written = (new Generator($config))->generate();

        self::assertSame(4, $written);

        $orderCode = file_get_contents($this->tmpDir . '/PurchaseOrderType.php');
        self::assertStringContainsString('public USAddress $shipTo,', $orderCode);
        self::assertStringContainsString('public Items $items,', $orderCode);
        // ref="comment" with minOccurs="0" -> nullable, type resolved from the global element decl
        self::assertStringContainsString('public ?string $comment = null,', $orderCode);
        // attribute type="xsd:date" -> \DateTimeImmutable, day-only Context
        self::assertStringContainsString('public ?\DateTimeImmutable $orderDate = null,', $orderCode);

        $addressCode = file_get_contents($this->tmpDir . '/USAddress.php');
        // fixed="US" on the attribute surfaces as a doc hint, doesn't change nullability
        self::assertStringContainsString('(XSD-Fixed: US)', $addressCode);
        self::assertStringContainsString('public ?string $country = null,', $addressCode);

        $itemsCode = file_get_contents($this->tmpDir . '/Items.php');
        // minOccurs="0" maxOccurs="unbounded" on the anonymous inline item complexType -> array
        self::assertStringContainsString('public array $item = [],', $itemsCode);
    }
}
