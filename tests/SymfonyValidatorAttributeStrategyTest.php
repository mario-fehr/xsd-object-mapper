<?php

declare(strict_types=1);

namespace XsdObjectMapper\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThan;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Validation;
use XsdObjectMapper\Attribute\CompositeAttributeStrategy;
use XsdObjectMapper\Attribute\SymfonySerializerAttributeStrategy;
use XsdObjectMapper\Attribute\SymfonyValidatorAttributeStrategy;
use XsdObjectMapper\Config;
use XsdObjectMapper\Generator;
use XsdObjectMapper\NamespaceMapping;
use XsdObjectMapper\Validator\Decimal;

final class SymfonyValidatorAttributeStrategyTest extends TestCase
{
    use RemovesTempDir;

    private const string TEST_NS = 'urn:xsd-object-mapper-validator-test';

    private string $tmpDir;
    private string $phpNamespace;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/xsd-object-mapper-validator-test-'.bin2hex(random_bytes(8));
        // unique per test run so `require`-ing the generated class below never redeclares
        $this->phpNamespace = 'ValidatorTestGen'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir.'/xsd', 0o777, true);
        mkdir($this->tmpDir.'/out', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testPresenceConstraintsMatchThePropertyModel(): void
    {
        $this->writeXsdAndGenerate(new CompositeAttributeStrategy(
            new SymfonySerializerAttributeStrategy(),
            new SymfonyValidatorAttributeStrategy(),
        ));

        $code = $this->readGenerated('ContactType.php');

        // required string -> NotBlank; required non-string -> NotNull;
        // required array (minOccurs>=1) -> Count(min: 1); optional -> nothing.
        // Composite order is (Serializer, Validator), so SerializedName renders before the
        // validator constraint for each property.
        $this->assertMatchesRegularExpression('/#\[SerializedName\(\'Name\'\)\]\s+#\[NotBlank\(\)\]\s+public string \$name,/', $code);
        $this->assertMatchesRegularExpression('/#\[SerializedName\(\'Age\'\)\]\s+#\[NotNull\(\)\]\s+public int \$age,/', $code);
        // array properties always get a `= []` default (PHP can't express "required" via
        // absence of default for arrays) - Count(min: 1) is the only actual enforcement.
        $this->assertMatchesRegularExpression('/#\[SerializedName\(\'Tag\'\)\]\s+#\[Count\(min: 1\)\]\s+public array \$tag = \[\],/', $code);
        $this->assertStringContainsString("#[SerializedName('Nickname')]\n        public ?string \$nickname = null,", $code);

        // serializer and validator attributes coexist without a `use` collision (different basenames).
        $this->assertStringContainsString('use Symfony\Component\Serializer\Attribute\SerializedName;', $code);
        $this->assertStringContainsString('use Symfony\Component\Validator\Constraints\Count;', $code);
        $this->assertStringContainsString('use Symfony\Component\Validator\Constraints\NotBlank;', $code);
        $this->assertStringContainsString('use Symfony\Component\Validator\Constraints\NotNull;', $code);
    }

    public function testConstraintsAreActuallyEnforcedAtRuntime(): void
    {
        $this->writeXsdAndGenerate(new SymfonyValidatorAttributeStrategy());

        require $this->tmpDir.'/out/ContactType.php';
        $class = $this->phpNamespace.'\\ContactType';

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $valid = new $class(name: 'Mario', age: 40, tag: ['x']);
        $this->assertCount(0, $validator->validate($valid));

        $blankName = new $class(name: '', age: 40, tag: ['x']);
        $violations = $validator->validate($blankName);
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertInstanceOf(NotBlank::class, $violations->get(0)->getConstraint());
        $this->assertSame('name', $violations->get(0)->getPropertyPath());

        // PHP's type system allows an empty array for `array $tag` - only Count(min: 1) catches
        // "must have at least one item", which is exactly why it exists alongside plain typing.
        $emptyTag = new $class(name: 'Mario', age: 40, tag: []);
        $violations = $validator->validate($emptyTag);
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertInstanceOf(Count::class, $violations->get(0)->getConstraint());
    }

    public function testFacetConstraintsMatchXsdRestrictions(): void
    {
        $this->writeXsdAndGenerate(new SymfonyValidatorAttributeStrategy(), <<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd-object-mapper-validator-test"
                       targetNamespace="urn:xsd-object-mapper-validator-test"
                       elementFormDefault="qualified">
              <xs:simpleType name="GuidType">
                <xs:restriction base="xs:string">
                  <xs:pattern value="[0-9a-fA-F]{8}"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:simpleType name="ShortCode">
                <xs:restriction base="xs:string">
                  <xs:minLength value="2"/>
                  <xs:maxLength value="5"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:simpleType name="Percentage">
                <xs:restriction base="xs:int">
                  <xs:minInclusive value="0"/>
                  <xs:maxInclusive value="100"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:simpleType name="ExactCode">
                <xs:restriction base="xs:string">
                  <xs:length value="3"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:simpleType name="Score">
                <xs:restriction base="xs:int">
                  <xs:minExclusive value="0"/>
                  <xs:maxExclusive value="10"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:simpleType name="AmountType">
                <xs:restriction base="xs:decimal">
                  <xs:fractionDigits value="2"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:complexType name="FacetType">
                <xs:sequence>
                  <xs:element name="Guid" type="GuidType"/>
                  <xs:element name="Guids" type="GuidType" maxOccurs="unbounded"/>
                  <xs:element name="Code" type="ShortCode"/>
                  <xs:element name="Percent" type="Percentage"/>
                  <xs:element name="ExactCode" type="ExactCode"/>
                  <xs:element name="Score" type="Score"/>
                  <xs:element name="Amount" type="AmountType"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $code = $this->readGenerated('FacetType.php');

        $this->assertStringContainsString("#[Regex(pattern: '#^(?:[0-9a-fA-F]{8})\$#u')]", $code);
        $this->assertStringContainsString('#[Length(min: 2, max: 5)]', $code);
        $this->assertStringContainsString('#[Range(min: 0, max: 100)]', $code);
        $this->assertStringContainsString('#[Length(exactly: 3)]', $code);
        $this->assertStringContainsString('#[GreaterThan(value: 0)]', $code);
        $this->assertStringContainsString('#[LessThan(value: 10)]', $code);
        $this->assertStringContainsString('#[Decimal(fractionDigits: 2)]', $code);
        $this->assertStringContainsString('use XsdObjectMapper\Validator\Decimal;', $code);
        // Guids (same GuidType, but maxOccurs="unbounded" -> array) skips facets entirely -
        // Regex/Length/Range validate a single scalar value, not each array item.
        $this->assertStringContainsString('public array $guids = [],', $code);
        $this->assertSame(1, substr_count($code, 'Regex(pattern:'));
    }

    public function testFacetConstraintsAreActuallyEnforcedAtRuntime(): void
    {
        $this->writeXsdAndGenerate(new SymfonyValidatorAttributeStrategy(), <<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd-object-mapper-validator-test"
                       targetNamespace="urn:xsd-object-mapper-validator-test"
                       elementFormDefault="qualified">
              <xs:simpleType name="ShortCode">
                <xs:restriction base="xs:string">
                  <xs:minLength value="2"/>
                  <xs:maxLength value="5"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:simpleType name="Percentage">
                <xs:restriction base="xs:int">
                  <xs:minInclusive value="0"/>
                  <xs:maxInclusive value="100"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:simpleType name="ExactCode">
                <xs:restriction base="xs:string">
                  <xs:length value="3"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:simpleType name="Score">
                <xs:restriction base="xs:int">
                  <xs:minExclusive value="0"/>
                  <xs:maxExclusive value="10"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:simpleType name="AmountType">
                <xs:restriction base="xs:decimal">
                  <xs:fractionDigits value="2"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:complexType name="FacetType">
                <xs:sequence>
                  <xs:element name="Code" type="ShortCode"/>
                  <xs:element name="Percent" type="Percentage"/>
                  <xs:element name="ExactCode" type="ExactCode"/>
                  <xs:element name="Score" type="Score"/>
                  <xs:element name="Amount" type="AmountType"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        require $this->tmpDir.'/out/FacetType.php';
        $class = $this->phpNamespace.'\\FacetType';

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $class(code: 'AB', percent: 50, exactCode: 'ABC', score: 5, amount: 12.34)));

        $violations = $validator->validate(new $class(code: 'A', percent: 50, exactCode: 'ABC', score: 5, amount: 12.34));
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertInstanceOf(Length::class, $violations->get(0)->getConstraint());

        $violations = $validator->validate(new $class(code: 'AB', percent: 150, exactCode: 'ABC', score: 5, amount: 12.34));
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertInstanceOf(Range::class, $violations->get(0)->getConstraint());

        $violations = $validator->validate(new $class(code: 'AB', percent: 50, exactCode: 'AB', score: 5, amount: 12.34));
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertInstanceOf(Length::class, $violations->get(0)->getConstraint());

        $violations = $validator->validate(new $class(code: 'AB', percent: 50, exactCode: 'ABC', score: 5, amount: 12.345));
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertInstanceOf(Decimal::class, $violations->get(0)->getConstraint());

        // minExclusive/maxExclusive: the boundary values themselves must fail (0 and 10 are
        // excluded, unlike Range's minInclusive/maxInclusive case tested above).
        $violations = $validator->validate(new $class(code: 'AB', percent: 50, exactCode: 'ABC', score: 0, amount: 12.34));
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertInstanceOf(GreaterThan::class, $violations->get(0)->getConstraint());

        $violations = $validator->validate(new $class(code: 'AB', percent: 50, exactCode: 'ABC', score: 10, amount: 12.34));
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertInstanceOf(LessThan::class, $violations->get(0)->getConstraint());
    }

    public function testValidCascadesIntoBothSingleAndArrayNestedObjects(): void
    {
        $this->writeXsdAndGenerate(new SymfonyValidatorAttributeStrategy(), <<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd-object-mapper-validator-test"
                       targetNamespace="urn:xsd-object-mapper-validator-test"
                       elementFormDefault="qualified">
              <xs:complexType name="AddressType">
                <xs:sequence>
                  <xs:element name="City" type="xs:string"/>
                </xs:sequence>
              </xs:complexType>
              <xs:complexType name="PersonType">
                <xs:sequence>
                  <xs:element name="HomeAddress" type="AddressType"/>
                  <xs:element name="OtherAddress" type="AddressType" maxOccurs="unbounded"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $code = $this->readGenerated('PersonType.php');
        $this->assertStringContainsString('use Symfony\Component\Validator\Constraints\Valid;', $code);
        $this->assertStringContainsString('#[Valid()]', $code);

        require $this->tmpDir.'/out/AddressType.php';
        require $this->tmpDir.'/out/PersonType.php';
        $addressClass = $this->phpNamespace.'\\AddressType';
        $personClass = $this->phpNamespace.'\\PersonType';

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        // without #[Assert\Valid] on the nested properties, validating PersonType would never
        // reach AddressType's own NotBlank on `city` - this is exactly what would silently make
        // every generated constraint on a nested type inert.
        $this->assertCount(0, $validator->validate(new $personClass(
            homeAddress: new $addressClass(city: 'Vienna'),
            otherAddress: [new $addressClass(city: 'Graz')],
        )));

        $violations = $validator->validate(new $personClass(
            homeAddress: new $addressClass(city: ''),
            otherAddress: [new $addressClass(city: 'Graz')],
        ));
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertSame('homeAddress.city', $violations->get(0)->getPropertyPath());

        $violations = $validator->validate(new $personClass(
            homeAddress: new $addressClass(city: 'Vienna'),
            otherAddress: [new $addressClass(city: '')],
        ));
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertSame('otherAddress[0].city', $violations->get(0)->getPropertyPath());
    }

    public function testFacetsMergeAcrossANamedSimpleTypeRestrictionChain(): void
    {
        $this->writeXsdAndGenerate(new SymfonyValidatorAttributeStrategy(), <<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd-object-mapper-validator-test"
                       targetNamespace="urn:xsd-object-mapper-validator-test"
                       elementFormDefault="qualified">
              <xs:simpleType name="BaseCode">
                <xs:restriction base="xs:string">
                  <xs:pattern value="[A-Z]+"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:simpleType name="NarrowCode">
                <xs:restriction base="BaseCode">
                  <xs:minLength value="3"/>
                  <xs:maxLength value="3"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:simpleType name="OverriddenCode">
                <xs:restriction base="BaseCode">
                  <xs:pattern value="[0-9]+"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:complexType name="ChainType">
                <xs:sequence>
                  <xs:element name="Narrow" type="NarrowCode"/>
                  <xs:element name="Overridden" type="OverriddenCode"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $code = $this->readGenerated('ChainType.php');

        // NarrowCode adds no pattern of its own - BaseCode's must still apply, merged with
        // NarrowCode's own minLength/maxLength, not lost.
        $this->assertStringContainsString("#[Regex(pattern: '#^(?:[A-Z]+)\$#u')]", $code);
        $this->assertStringContainsString('#[Length(min: 3, max: 3)]', $code);

        // OverriddenCode redefines pattern - its own value must win over BaseCode's ancestor
        // value on the same key (a single merged 'pattern' key, not two competing attributes).
        $this->assertStringContainsString("#[Regex(pattern: '#^(?:[0-9]+)\$#u')]", $code);
        $this->assertSame(2, substr_count($code, 'Regex(pattern:'));

        require $this->tmpDir.'/out/ChainType.php';
        $class = $this->phpNamespace.'\\ChainType';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $class(narrow: 'ABC', overridden: '123')));

        // fails BaseCode's inherited pattern (lowercase), even though NarrowCode itself never
        // redeclares a pattern - this is exactly what the merge (not overwrite) fix protects.
        $violations = $validator->validate(new $class(narrow: 'abc', overridden: '123'));
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertInstanceOf(Regex::class, $violations->get(0)->getConstraint());

        // fails NarrowCode's own minLength/maxLength=3
        $violations = $validator->validate(new $class(narrow: 'AB', overridden: '123'));
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertInstanceOf(Length::class, $violations->get(0)->getConstraint());

        // OverriddenCode's own pattern applies, not BaseCode's ancestor pattern
        $this->assertCount(0, $validator->validate(new $class(narrow: 'ABC', overridden: '456')));
        $violations = $validator->validate(new $class(narrow: 'ABC', overridden: 'XYZ'));
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertInstanceOf(Regex::class, $violations->get(0)->getConstraint());
    }

    public function testPatternWithBothHashAndTildeGetsASafeDelimiter(): void
    {
        $this->writeXsdAndGenerate(new SymfonyValidatorAttributeStrategy(), <<<'XSD'
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd-object-mapper-validator-test"
                       targetNamespace="urn:xsd-object-mapper-validator-test"
                       elementFormDefault="qualified">
              <xs:simpleType name="MarkerType">
                <xs:restriction base="xs:string">
                  <xs:pattern value="[#~]"/>
                </xs:restriction>
              </xs:simpleType>
              <xs:complexType name="MarkerHolderType">
                <xs:sequence>
                  <xs:element name="Marker" type="MarkerType"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD);

        $code = $this->readGenerated('MarkerHolderType.php');

        // neither '#' nor '~' can be the PCRE delimiter here - both appear in the pattern
        // itself; the generator must fall back to the next candidate ('!').
        $this->assertStringContainsString("#[Regex(pattern: '!^(?:[#~])\$!u')]", $code);

        require $this->tmpDir.'/out/MarkerHolderType.php';
        $class = $this->phpNamespace.'\\MarkerHolderType';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $class(marker: '#')));
        $this->assertCount(0, $validator->validate(new $class(marker: '~')));
        $violations = $validator->validate(new $class(marker: 'x'));
        $this->assertGreaterThanOrEqual(1, \count($violations));
        $this->assertInstanceOf(Regex::class, $violations->get(0)->getConstraint());
    }

    private function writeXsdAndGenerate(\XsdObjectMapper\Attribute\PropertyAttributeStrategy $attributeStrategy, ?string $xsd = null): void
    {
        $xsd ??= <<<XSD
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                       xmlns="urn:xsd-object-mapper-validator-test"
                       targetNamespace="urn:xsd-object-mapper-validator-test"
                       elementFormDefault="qualified">
              <xs:complexType name="ContactType">
                <xs:sequence>
                  <xs:element name="Name" type="xs:string"/>
                  <xs:element name="Age" type="xs:int"/>
                  <xs:element name="Tag" type="xs:string" maxOccurs="unbounded"/>
                  <xs:element name="Nickname" type="xs:string" minOccurs="0"/>
                </xs:sequence>
              </xs:complexType>
            </xs:schema>
            XSD;

        file_put_contents($this->tmpDir.'/xsd/schema.xsd', $xsd);

        $config = new Config(
            xsdPaths: [$this->tmpDir.'/xsd/schema.xsd'],
            namespaceMap: [
                self::TEST_NS => new NamespaceMapping($this->phpNamespace, $this->tmpDir.'/out'),
            ],
            attributeStrategy: $attributeStrategy,
        );

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
