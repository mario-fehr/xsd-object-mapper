<?php

declare(strict_types=1);

namespace Xsd2Php\Tests;

use PHPUnit\Framework\TestCase;
use Xsd2Php\Attribute\PropertyAttributeStrategy;
use Xsd2Php\Attribute\SymfonySerializerAttributeStrategy;
use Xsd2Php\Config;
use Xsd2Php\Generator;
use Xsd2Php\NamespaceMapping;
use Xsd2Php\Property;

final class GeneratorTest extends TestCase
{
    use RemovesTempDir;

    private const string TEST_NS = 'urn:xsd2php-test';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/xsd2php-test-'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir.'/xsd', 0o777, true);
        mkdir($this->tmpDir.'/out', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testResolvesElementRef(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:element name="Name" type="xs:string"/>
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element ref="Name" minOccurs="0" maxOccurs="unbounded"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('PersonType.php');

        // ref-target's name ("Name") and type (xs:string) both come from the global
        // element declaration; occurrence (here: array, from maxOccurs="unbounded")
        // comes from the reference site, not the global declaration.
        $this->assertStringContainsString('final readonly class PersonType', $code);
        $this->assertStringContainsString('use Symfony\Component\Serializer\Attribute\SerializedName;', $code);
        $this->assertStringContainsString("#[SerializedName('Name')]", $code);
        $this->assertStringContainsString('public array $name = [],', $code);
        $this->assertStringContainsString('@var string[]', $code);
    }

    public function testXsdDefaultAndFixedAppearAsDocHintWithoutChangingNullability(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:element name="Greeting" type="xs:string" default="Hello"/>
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="SendMail" type="xs:boolean" minOccurs="0" default="false">
                    <xs:annotation><xs:documentation>Whether to send mail.</xs:documentation></xs:annotation>
                  </xs:element>
                  <xs:element ref="Greeting" minOccurs="0"/>
                </xs:sequence>
                <xs:attribute name="Status" type="xs:string" use="optional" default="Active"/>
                <xs:attribute name="Code" type="xs:string" fixed="X"/>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('PersonType.php');

        // default/fixed are purely informational - nullability/type stay exactly as they'd be
        // without them, driven only by minOccurs/use as before this fix.
        $this->assertStringContainsString('/** Whether to send mail. (XSD-Default: false) */', $code);
        $this->assertStringContainsString('public ?bool $sendMail = null,', $code);
        $this->assertStringContainsString('/** (XSD-Default: Active) */', $code);
        $this->assertStringContainsString('public ?string $status = null,', $code);
        $this->assertStringContainsString('/** (XSD-Fixed: X) */', $code);
        // xs:element ref="..." itself can't carry default/fixed (XSD forbids it) - the hint
        // comes from the referenced global element's own declaration.
        $this->assertStringContainsString('/** (XSD-Default: Hello) */', $code);
        $this->assertStringContainsString('public ?string $greeting = null,', $code);
    }

    public function testUnknownElementRefWarnsAndSkipsInsteadOfCrashing(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element ref="DoesNotExist"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('PersonType.php');

        $this->assertStringContainsString('public function __construct(', $code);
        $this->assertStringNotContainsString('DoesNotExist', $code);
    }

    public function testUnsupportedAttributeRefIsSkippedInsteadOfCrashing(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="Name" type="xs:string"/>
                </xs:sequence>
                <xs:attribute ref="SomeAttribute"/>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('PersonType.php');

        // xs:attribute ref="..." isn't resolved (unlike xs:element ref) - must be skipped with a
        // warning, not silently, same as an unknown element ref above.
        $this->assertStringContainsString('public function __construct(', $code);
        $this->assertStringNotContainsString('SomeAttribute', $code);
    }

    public function testResolvesGroupRef(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:group name="AddressGroup">
                <xs:sequence>
                  <xs:element name="Street" type="xs:string"/>
                  <xs:element name="City" type="xs:string" minOccurs="0"/>
                </xs:sequence>
              </xs:group>
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:group ref="AddressGroup"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('PersonType.php');

        // xs:group ref flattens the group's own sequence into PersonType's constructor -
        // required Street before optional City, no separate "AddressGroup" artifact.
        $this->assertStringContainsString('public string $street,', $code);
        $this->assertStringContainsString('public ?string $city = null,', $code);
        $this->assertStringNotContainsString('AddressGroup', $code);
    }

    public function testCircularGroupRefStopsInsteadOfInfiniteRecursion(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:group name="A">
                <xs:sequence>
                  <xs:element name="X" type="xs:string"/>
                  <xs:group ref="B"/>
                </xs:sequence>
              </xs:group>
              <xs:group name="B">
                <xs:sequence>
                  <xs:element name="Y" type="xs:string"/>
                  <xs:group ref="A"/>
                </xs:sequence>
              </xs:group>
              <xs:complexType name="CyclicType">
                <xs:sequence>
                  <xs:group ref="A"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('CyclicType.php');

        // A -> B -> A: the second "A" is where the cycle guard must stop the recursion.
        // X (from A) and Y (from B) still make it in; A isn't duplicated.
        $this->assertStringContainsString('public string $x,', $code);
        $this->assertStringContainsString('public string $y,', $code);
        $this->assertSame(1, substr_count($code, 'public string $x,'));
    }

    public function testInlineNestedTypeOnExtensionBaseIsOwnedByBaseNotSubclass(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="BaseType">
                <xs:sequence>
                  <xs:element name="Details" minOccurs="0">
                    <xs:complexType>
                      <xs:sequence>
                        <xs:element name="Info" type="xs:string"/>
                      </xs:sequence>
                    </xs:complexType>
                  </xs:element>
                </xs:sequence>
              </xs:complexType>
              <xs:complexType name="SubAType">
                <xs:complexContent>
                  <xs:extension base="BaseType"/>
                </xs:complexContent>
              </xs:complexType>
              <xs:complexType name="SubBType">
                <xs:complexContent>
                  <xs:extension base="BaseType"/>
                </xs:complexContent>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        // The inline anonymous complexType on BaseType's own "Details" element is declared by
        // BaseType, not by either subclass - it must be generated exactly once, under BaseType's
        // own namespace, and both subclasses must reference that same class (not get their own
        // private copy under their own namespace).
        $this->assertFileExists($this->tmpDir.'/out/BaseType/Details.php');
        $this->assertFileDoesNotExist($this->tmpDir.'/out/SubAType/Details.php');
        $this->assertFileDoesNotExist($this->tmpDir.'/out/SubBType/Details.php');

        foreach (['SubAType.php', 'SubBType.php'] as $filename) {
            $code = $this->readGenerated($filename);
            $this->assertStringContainsString('use TestGen\BaseType\Details;', $code);
            $this->assertStringContainsString('public ?Details $details = null,', $code);
        }
    }

    public function testAmbiguousAttributeBasenameFallsBackToFqcn(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="Name" type="xs:string"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        // Two different attribute classes that happen to share a basename ("Marker") - the
        // generator can't safely alias both to the same short name, so both must fall back
        // to an inline fully-qualified name and neither gets a `use` line.
        $this->generate(new class implements PropertyAttributeStrategy {
            public function attributesFor(Property $property): array
            {
                return [
                    ['fqcn' => 'Vendor\\One\\Marker', 'args' => ''],
                    ['fqcn' => 'Vendor\\Two\\Marker', 'args' => ''],
                ];
            }
        });

        $code = $this->readGenerated('PersonType.php');

        $this->assertStringNotContainsString('use Vendor', $code);
        $this->assertStringContainsString('#[\Vendor\One\Marker()]', $code);
        $this->assertStringContainsString('#[\Vendor\Two\Marker()]', $code);
    }

    public function testSameNamespacePropertyTypeIsBareWithNoImport(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="AddressType">
                <xs:sequence>
                  <xs:element name="City" type="xs:string"/>
                </xs:sequence>
              </xs:complexType>
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="Address" type="AddressType"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('PersonType.php');

        // AddressType lives in the same PHP namespace (TestGen) as PersonType itself - PHP
        // resolves the unqualified name against the file's own namespace automatically, so
        // this needs neither a `use` line nor a leading backslash.
        $this->assertStringNotContainsString('use TestGen\AddressType;', $code);
        $this->assertStringNotContainsString('\AddressType', $code);
        $this->assertStringContainsString('public AddressType $address,', $code);
    }

    public function testCrossNamespacePropertyTypeGetsUseImport(): void
    {
        $this->writeXsdFile('a.xsd', <<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test-a"
                       targetNamespace="urn:xsd2php-test-a"
                       elementFormDefault="qualified">
              <xs:complexType name="AddressType">
                <xs:sequence>
                  <xs:element name="City" type="xs:string"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);
        $this->writeXsdFile('b.xsd', <<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test-b"
                       xmlns:a="urn:xsd2php-test-a"
                       targetNamespace="urn:xsd2php-test-b"
                       elementFormDefault="qualified">
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="Address" type="a:AddressType"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate(namespaceMap: [
            'urn:xsd2php-test-a' => new NamespaceMapping('GenA', $this->tmpDir.'/out-a'),
            'urn:xsd2php-test-b' => new NamespaceMapping('GenB', $this->tmpDir.'/out-b'),
        ]);

        $path = $this->tmpDir.'/out-b/PersonType.php';
        $this->assertFileExists($path);
        $code = file_get_contents($path);

        $this->assertStringContainsString('use GenA\AddressType;', (string) $code);
        $this->assertStringContainsString('public AddressType $address,', (string) $code);
    }

    public function testPathForPicksLongestNamespacePrefixMatch(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="Details" minOccurs="0">
                    <xs:complexType>
                      <xs:sequence>
                        <xs:element name="Info" type="xs:string"/>
                      </xs:sequence>
                    </xs:complexType>
                  </xs:element>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        // "TestGen" and "TestGen\PersonType" (the inline nested "Details" type's own namespace)
        // both match as a prefix - the broader one is listed first, but the more specific one
        // must still win.
        $this->generate(namespaceMap: [
            self::TEST_NS => new NamespaceMapping('TestGen', $this->tmpDir.'/out'),
            'urn:xsd2php-test-unused' => new NamespaceMapping('TestGen\\PersonType', $this->tmpDir.'/out-nested'),
        ]);

        $this->assertFileExists($this->tmpDir.'/out/PersonType.php');
        $this->assertFileExists($this->tmpDir.'/out-nested/Details.php');
        $this->assertFileDoesNotExist($this->tmpDir.'/out/PersonType/Details.php');
    }

    public function testChoiceElementsAreNullableWithExactlyOneOfConstraint(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="CartItemType">
                <xs:choice>
                  <xs:element name="Accommodation" type="xs:string"/>
                  <xs:element name="Brochure" type="xs:string"/>
                </xs:choice>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('CartItemType.php');

        // xs:choice elements are mutually exclusive alternatives, not siblings that are all
        // required at once - both nullable, plus a class-level "exactly one of" constraint.
        $this->assertStringContainsString('use Xsd2Php\Validator\ExactlyOneOf;', $code);
        $this->assertStringContainsString("#[ExactlyOneOf(fields: ['accommodation', 'brochure'])]", $code);
        $this->assertStringContainsString('public ?string $accommodation = null,', $code);
        $this->assertStringContainsString('public ?string $brochure = null,', $code);
    }

    public function testSequenceElementsRemainRequiredWithoutChoiceConstraint(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="FirstName" type="xs:string"/>
                  <xs:element name="LastName" type="xs:string"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('PersonType.php');

        // xs:sequence siblings stay independently required - the choice-nullable fix must not
        // leak into plain sequences.
        $this->assertStringNotContainsString('ExactlyOneOf', $code);
        $this->assertStringContainsString('public string $firstName,', $code);
        $this->assertStringContainsString('public string $lastName,', $code);
    }

    public function testGroupRefInsideChoiceIsTreatedAsChoiceMember(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:group name="AddressGroup">
                <xs:sequence>
                  <xs:element name="Street" type="xs:string"/>
                </xs:sequence>
              </xs:group>
              <xs:complexType name="CartItemType">
                <xs:choice>
                  <xs:group ref="AddressGroup"/>
                  <xs:element name="Brochure" type="xs:string"/>
                </xs:choice>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('CartItemType.php');

        // An xs:group ref sitting directly inside an xs:choice inlines its own elements as
        // choice members too (ambient choice membership), not as independently required
        // siblings of the choice.
        $this->assertStringContainsString("#[ExactlyOneOf(fields: ['street', 'brochure'])]", $code);
        $this->assertStringContainsString('public ?string $street = null,', $code);
        $this->assertStringContainsString('public ?string $brochure = null,', $code);
    }

    public function testRepeatableChoiceSkipsExactlyOneOfConstraint(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="CartItemType">
                <xs:choice maxOccurs="unbounded">
                  <xs:element name="Accommodation" type="xs:string"/>
                  <xs:element name="Brochure" type="xs:string"/>
                </xs:choice>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('CartItemType.php');

        // A repeatable choice (maxOccurs > 1 on the xs:choice itself) picks a branch per
        // occurrence, not once overall - "exactly one of" globally would be wrong, so the
        // constraint is skipped; elements stay nullable regardless.
        $this->assertStringNotContainsString('ExactlyOneOf', $code);
        $this->assertStringContainsString('public ?string $accommodation = null,', $code);
        $this->assertStringContainsString('public ?string $brochure = null,', $code);
    }

    public function testTwoIndependentChoicesEmitTwoExactlyOneOfAttributesWithoutFatalError(): void
    {
        // ExactlyOneOf must be PHP-attribute-repeatable - a class with two unrelated xs:choice
        // particles emits two #[ExactlyOneOf(...)] instances on the same class.
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="CartItemType">
                <xs:sequence>
                  <xs:choice>
                    <xs:element name="Accommodation" type="xs:string"/>
                    <xs:element name="Brochure" type="xs:string"/>
                  </xs:choice>
                  <xs:choice>
                    <xs:element name="Foo" type="xs:string"/>
                    <xs:element name="Bar" type="xs:string"/>
                  </xs:choice>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('CartItemType.php');

        $this->assertSame(2, substr_count($code, '#[ExactlyOneOf('));
        $this->assertStringContainsString("#[ExactlyOneOf(fields: ['accommodation', 'brochure'])]", $code);
        $this->assertStringContainsString("#[ExactlyOneOf(fields: ['foo', 'bar'])]", $code);

        require $this->tmpDir.'/out/CartItemType.php';
        $reflection = new \ReflectionClass('TestGen\CartItemType');
        // ReflectionClass::getAttributes() throws if PHP itself rejects a repeated non-repeatable
        // attribute - constructing it at all is the regression check.
        $this->assertCount(2, $reflection->getAttributes());
    }

    public function testChoiceElementNameCollisionWithAttributeSkipsConstraintInsteadOfBindingWrongField(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="CartItemType">
                <xs:choice>
                  <xs:element name="Type" type="xs:string"/>
                  <xs:element name="Brochure" type="xs:string"/>
                </xs:choice>
                <xs:attribute name="type" type="xs:string"/>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('CartItemType.php');

        // the xs:attribute "type" de-dups onto the same phpName as the choice's "Type" element
        // (last one wins) - the constraint must not silently bind to the unrelated attribute.
        $this->assertStringNotContainsString('ExactlyOneOf', $code);
    }

    public function testMultiElementChoiceBranchSkipsConstraintInsteadOfTreatingMembersIndependently(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="CartItemType">
                <xs:choice>
                  <xs:sequence>
                    <xs:element name="StreetA" type="xs:string"/>
                    <xs:element name="StreetB" type="xs:string"/>
                  </xs:sequence>
                  <xs:element name="Brochure" type="xs:string"/>
                </xs:choice>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('CartItemType.php');

        // one xs:choice branch (the nested xs:sequence) expands to 2 elements - they're one
        // atomic alternative together, not 2 independent "one of" members; not representable
        // by this generator, so the constraint is skipped rather than emitted wrong. Elements
        // stay nullable regardless.
        $this->assertStringNotContainsString('ExactlyOneOf', $code);
        $this->assertStringContainsString('public ?string $streetA = null,', $code);
        $this->assertStringContainsString('public ?string $streetB = null,', $code);
        $this->assertStringContainsString('public ?string $brochure = null,', $code);
    }

    public function testOptionalChoiceEmitsAtMostOneOfConstraint(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="CartItemType">
                <xs:choice minOccurs="0">
                  <xs:element name="Accommodation" type="xs:string"/>
                  <xs:element name="Brochure" type="xs:string"/>
                </xs:choice>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('CartItemType.php');

        // xs:choice minOccurs="0": zero branches selected is valid too - "at most one", not
        // "exactly one".
        $this->assertStringContainsString("#[ExactlyOneOf(fields: ['accommodation', 'brochure'], required: false)]", $code);
    }

    public function testEnumerationGeneratesBackedStringEnum(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
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
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $enumCode = $this->readGenerated('ColorEnum.php');
        $this->assertStringContainsString('enum ColorEnum: string', $enumCode);
        $this->assertStringContainsString("case Red = 'Red';", $enumCode);
        $this->assertStringContainsString("case Green = 'Green';", $enumCode);

        $code = $this->readGenerated('PersonType.php');
        $this->assertStringContainsString('public ColorEnum $color,', $code);
    }

    public function testEnumerationValueRequiringSanitizationBecomesValidCaseName(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:simpleType name="SubTypeEnum">
                <xs:restriction base="xs:string">
                  <xs:enumeration value="1"/>
                  <xs:enumeration value="2"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="SubType" type="SubTypeEnum"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        // a leading digit isn't a legal PHP identifier start - the case *name* gets a "V" prefix,
        // but the case *value* (what actually round-trips through XML) stays the real XSD value.
        // Real schema case: InfraStructureSubTypeEnum has an enumeration value="1".
        $enumCode = $this->readGenerated('SubTypeEnum.php');
        $this->assertStringContainsString("case V1 = '1';", $enumCode);
        $this->assertStringContainsString("case V2 = '2';", $enumCode);
    }

    public function testDuplicateSanitizedEnumCaseNamesGetSuffixed(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:simpleType name="CollisionEnum">
                <xs:restriction base="xs:string">
                  <xs:enumeration value="A B"/>
                  <xs:enumeration value="A_B"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="Collision" type="CollisionEnum"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        // "A B" and "A_B" both sanitize to the same PHP identifier "A_B" - the second occurrence
        // must not silently overwrite/collide with the first case.
        $enumCode = $this->readGenerated('CollisionEnum.php');
        $this->assertStringContainsString("case A_B = 'A B';", $enumCode);
        $this->assertStringContainsString("case A_B_2 = 'A_B';", $enumCode);
    }

    public function testEnumValueEqualToPhpReservedWordGetsTypeSuffix(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:simpleType name="ReservedEnum">
                <xs:restriction base="xs:string">
                  <xs:enumeration value="class"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="Reserved" type="ReservedEnum"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        // "class" ucfirst's to "Class", which collides case-insensitively with the PHP reserved
        // word - gets a "Type" suffix, same rule as class name collisions elsewhere in the generator.
        $enumCode = $this->readGenerated('ReservedEnum.php');
        $this->assertStringContainsString("case ClassType = 'class';", $enumCode);
    }

    public function testIntBackedEnumUsesIntLiteralCases(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:simpleType name="PriorityEnum">
                <xs:restriction base="xs:int">
                  <xs:enumeration value="1"/>
                  <xs:enumeration value="2"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="Priority" type="PriorityEnum"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        // xs:int-based enumeration - PHP backing type and case literals must both be int, not the
        // default string.
        $enumCode = $this->readGenerated('PriorityEnum.php');
        $this->assertStringContainsString('enum PriorityEnum: int', $enumCode);
        $this->assertStringContainsString('case V1 = 1;', $enumCode);
        $this->assertStringContainsString('case V2 = 2;', $enumCode);
    }

    public function testAnonymousInlineEnumOnElementGeneratesEnumToo(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="Status" minOccurs="0">
                    <xs:simpleType>
                      <xs:restriction base="xs:string">
                        <xs:enumeration value="On"/>
                        <xs:enumeration value="Off"/>
                      </xs:restriction>
                    </xs:simpleType>
                  </xs:element>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        // xs:enumeration inside an anonymous inline simpleType (not a named one, its own code path
        // in resolveParticleType()) - less common in practice than a named simpleType enumeration,
        // but must still work.
        $enumCode = $this->readGenerated('PersonType/StatusEnum.php');
        $this->assertStringContainsString('enum StatusEnum: string', $enumCode);
        $this->assertStringContainsString("case On = 'On';", $enumCode);
        $this->assertStringContainsString("case Off = 'Off';", $enumCode);

        $code = $this->readGenerated('PersonType.php');
        $this->assertStringContainsString('use TestGen\PersonType\StatusEnum;', $code);
        $this->assertStringContainsString('public ?StatusEnum $status = null,', $code);
    }

    public function testSameNamedEnumSimpleTypeReferencedTwiceResolvesToTheSameEnumClass(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
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
              </xs:complexType>
              <xs:complexType name="OtherType">
                <xs:sequence>
                  <xs:element name="Color2" type="ColorEnum"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        // two complexTypes share the same named enum simpleType - matches the real schema pattern
        // (many complexTypes reuse the same enum). Both must resolve to the identical ColorEnum
        // type, not two independently-generated (potentially divergent) copies; the file itself
        // is always exactly one regardless, since ensureEnumClass() is deterministic - this
        // doesn't prove resolveSimpleTypeRef()'s cache fired, only that consistency holds either way.
        $this->assertCount(1, glob($this->tmpDir.'/out/ColorEnum.php'));
        $this->assertStringContainsString('public ColorEnum $color,', $this->readGenerated('PersonType.php'));
        $this->assertStringContainsString('public ColorEnum $color2,', $this->readGenerated('OtherType.php'));
    }

    public function testAttributeGroupRefResolvesAttributesIntoOwner(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:attributeGroup name="Identification">
                <xs:attribute name="Id" type="xs:string" use="required"/>
              </xs:attributeGroup>
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="Name" type="xs:string"/>
                </xs:sequence>
                <xs:attributeGroup ref="Identification"/>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('PersonType.php');
        $this->assertStringContainsString("#[SerializedName('@Id')]", $code);
        $this->assertStringContainsString('public string $id,', $code);
    }

    public function testMultipleAttributeGroupRefsPlusOwnAttributeAllCombine(): void
    {
        // a common real-world pattern: combining several attributeGroup refs with an own attribute
        // on the same complexType.
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:attributeGroup name="Identification">
                <xs:attribute name="Id" type="xs:string" use="required"/>
              </xs:attributeGroup>
              <xs:attributeGroup name="Activation">
                <xs:attribute name="Active" type="xs:boolean" use="required"/>
              </xs:attributeGroup>
              <xs:complexType name="EventType">
                <xs:attributeGroup ref="Activation"/>
                <xs:attributeGroup ref="Identification"/>
                <xs:attribute name="ChangeDate" type="xs:dateTime" use="required"/>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('EventType.php');
        $this->assertStringContainsString('public \DateTimeImmutable $changeDate,', $code);
        $this->assertStringContainsString('public bool $active,', $code);
        $this->assertStringContainsString('public string $id,', $code);
        // each property declared exactly once - not silently duplicated by combining 2 group refs
        // with an own attribute.
        $this->assertSame(1, substr_count($code, 'public \DateTimeImmutable $changeDate,'));
        $this->assertSame(1, substr_count($code, 'public bool $active,'));
        $this->assertSame(1, substr_count($code, 'public string $id,'));
    }

    public function testNestedAttributeGroupRefIsResolvedRecursively(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:attributeGroup name="Identification">
                <xs:attribute name="Id" type="xs:string" use="required"/>
              </xs:attributeGroup>
              <xs:attributeGroup name="Wrapper">
                <xs:attributeGroup ref="Identification"/>
              </xs:attributeGroup>
              <xs:complexType name="PersonType">
                <xs:attributeGroup ref="Wrapper"/>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        // Wrapper -> Identification -> Id: the group ref's own attributeGroup ref must be
        // followed too, not just the direct xs:attribute children.
        $this->assertStringContainsString('public string $id,', $this->readGenerated('PersonType.php'));
    }

    public function testCircularAttributeGroupRefStopsInsteadOfInfiniteRecursion(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:attributeGroup name="CircularA">
                <xs:attribute name="A" type="xs:string" use="required"/>
                <xs:attributeGroup ref="CircularB"/>
              </xs:attributeGroup>
              <xs:attributeGroup name="CircularB">
                <xs:attribute name="B" type="xs:string" use="required"/>
                <xs:attributeGroup ref="CircularA"/>
              </xs:attributeGroup>
              <xs:complexType name="PersonType">
                <xs:attributeGroup ref="CircularA"/>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        // same resolveNamedRef() cycle guard as xs:group (testCircularGroupRefStopsInsteadOfInfiniteRecursion),
        // but never exercised for the attributeGroup variant before. CircularA -> CircularB ->
        // CircularA: the second "CircularA" is where the cycle guard must stop the recursion - A's
        // and B's own attributes (collected before the cycle is hit) still make it in, neither is
        // duplicated, and it must warn and stop rather than recurse forever.
        $code = $this->readGenerated('PersonType.php');
        $this->assertStringContainsString('public string $a,', $code);
        $this->assertStringContainsString('public string $b,', $code);
        $this->assertSame(1, substr_count($code, 'public string $a,'));
        $this->assertSame(1, substr_count($code, 'public string $b,'));
    }

    public function testUnknownAttributeGroupRefWarnsAndSkipsInsteadOfCrashing(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="PersonType">
                <xs:attributeGroup ref="DoesNotExist"/>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('PersonType.php');
        $this->assertStringContainsString('public function __construct(', $code);
        $this->assertStringNotContainsString('DoesNotExist', $code);
    }

    public function testAttributeGroupCacheReturnsSameResolvedAttributesOnReuse(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:attributeGroup name="Identification">
                <xs:attribute name="Id" type="xs:string" use="required"/>
              </xs:attributeGroup>
              <xs:attributeGroup name="Activation">
                <xs:attribute name="Active" type="xs:boolean" use="required"/>
              </xs:attributeGroup>
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="Name" type="xs:string"/>
                </xs:sequence>
                <xs:attributeGroup ref="Identification"/>
              </xs:complexType>
              <xs:complexType name="OtherPersonType">
                <xs:sequence>
                  <xs:element name="Nick" type="xs:string"/>
                </xs:sequence>
                <xs:attributeGroup ref="Activation"/>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        // 2 different attributeGroups, each referenced from its own complexType (matches the real
        // 137x reuse pattern of "Identification" alongside other groups like "Activation") - each
        // owner must get exactly its own group's attribute, not the other's, which a
        // cache keyed/scoped incorrectly (e.g. leaking across distinct attributeGroupCache entries)
        // would produce.
        $personCode = $this->readGenerated('PersonType.php');
        $this->assertStringContainsString('public string $id,', $personCode);
        $this->assertStringNotContainsString('active', $personCode);

        $otherCode = $this->readGenerated('OtherPersonType.php');
        $this->assertStringContainsString('public bool $active,', $otherCode);
        $this->assertStringNotContainsString('$id', $otherCode);
    }

    public function testSimpleContentExtensionGeneratesTextValuePropertyPlusAttributes(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="DescriptionType">
                <xs:simpleContent>
                  <xs:extension base="xs:string">
                    <xs:attribute name="Language" type="xs:string" use="required"/>
                  </xs:extension>
                </xs:simpleContent>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('DescriptionType.php');
        $this->assertStringContainsString("#[SerializedName('#')]", $code);
        $this->assertStringContainsString('public string $value,', $code);
        $this->assertStringContainsString("#[SerializedName('@Language')]", $code);
        $this->assertStringContainsString('public string $language,', $code);
    }

    public function testSimpleContentValuePropertyIsAlwaysRequired(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:complexType name="DescriptionType">
                <xs:simpleContent>
                  <xs:extension base="xs:string">
                    <xs:attribute name="Language" type="xs:string" use="optional"/>
                  </xs:extension>
                </xs:simpleContent>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        // the text-content "value" property is never nullable/defaulted, regardless of how
        // optional the surrounding attributes are - locks in current behavior against an
        // accidental future change.
        $code = $this->readGenerated('DescriptionType.php');
        $this->assertStringContainsString('public string $value,', $code);
        $this->assertStringNotContainsString('?string $value', $code);
    }

    public function testSimpleContentWithAttributeGroupRefCombinesBothAttributeSources(): void
    {
        // a common real-world pattern: simpleContent/extension + attributeGroup ref + an own
        // attribute, all on the same complexType.
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:attributeGroup name="Identification">
                <xs:attribute name="Id" type="xs:string" use="required"/>
              </xs:attributeGroup>
              <xs:complexType name="DescriptionWithGroupType">
                <xs:simpleContent>
                  <xs:extension base="xs:string">
                    <xs:attributeGroup ref="Identification"/>
                    <xs:attribute name="Language" type="xs:string" use="required"/>
                  </xs:extension>
                </xs:simpleContent>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        $code = $this->readGenerated('DescriptionWithGroupType.php');
        $this->assertStringContainsString('public string $value,', $code);
        $this->assertStringContainsString('public string $language,', $code);
        $this->assertStringContainsString('public string $id,', $code);
        // each property declared exactly once - not silently duplicated by combining simpleContent,
        // an attributeGroup ref, and an own attribute.
        $this->assertSame(1, substr_count($code, 'public string $value,'));
        $this->assertSame(1, substr_count($code, 'public string $language,'));
        $this->assertSame(1, substr_count($code, 'public string $id,'));
    }

    public function testSimpleContentExtensionWithNamedSimpleTypeBaseUsesThatType(): void
    {
        $this->writeXsd(<<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd2php-test"
                       targetNamespace="urn:xsd2php-test"
                       elementFormDefault="qualified">
              <xs:simpleType name="LanguageEnum">
                <xs:restriction base="xs:string">
                  <xs:enumeration value="de"/>
                  <xs:enumeration value="en"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:complexType name="TypedValueType">
                <xs:simpleContent>
                  <xs:extension base="LanguageEnum">
                    <xs:attribute name="Id" type="xs:string" use="required"/>
                  </xs:extension>
                </xs:simpleContent>
              </xs:complexType>
            </xs:schema>
            XSD);

        $this->generate();

        // extension base is a named simpleType (an enum here), not a bare xs:string - the "value"
        // property must resolve to that type, not fall back to plain string.
        $code = $this->readGenerated('TypedValueType.php');
        $this->assertStringContainsString('public LanguageEnum $value,', $code);
    }

    private function writeXsd(string $content): void
    {
        $this->writeXsdFile('schema.xsd', $content);
    }

    private function writeXsdFile(string $filename, string $content): void
    {
        file_put_contents($this->tmpDir.'/xsd/'.$filename, $content);
    }

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

    private function readGenerated(string $filename): string
    {
        $path = $this->tmpDir.'/out/'.$filename;
        $this->assertFileExists($path);

        return file_get_contents($path);
    }
}
