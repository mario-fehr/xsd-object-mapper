<?php

declare(strict_types=1);

namespace Xsd2Php\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Country;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validation;
use Xsd2Php\Attribute\SemanticTypeAttributeStrategy;
use Xsd2Php\Config;
use Xsd2Php\Generator;
use Xsd2Php\NamespaceMapping;
use Xsd2Php\Property;
use Xsd2Php\PropertyRole;
use Xsd2Php\Tests\RemovesTempDir;

final class SemanticTypeAttributeStrategyTest extends TestCase
{
    use RemovesTempDir;

    private const array ALIAS_MAP = [
        'EmailType' => ['fqcn' => Email::class, 'args' => ''],
    ];

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

    /**
     * End-to-end: real generated class, real symfony/validator run. This is deliberately not
     * just a structural string-assertion - Assert\Country requires symfony/intl at runtime
     * (throws LogicException without it), which a structural test alone would never catch.
     */
    public function testAliasedConstraintsAreActuallyEnforcedAtRuntime(): void
    {
        $tmpDir = sys_get_temp_dir().'/xsd2php-semantic-test-'.bin2hex(random_bytes(8));
        $phpNamespace = 'SemanticTestGen'.bin2hex(random_bytes(4));
        mkdir($tmpDir.'/xsd', 0o777, true);
        mkdir($tmpDir.'/out', 0o777, true);

        try {
            file_put_contents($tmpDir.'/xsd/schema.xsd', <<<'XSD'
                <?xml version="1.0" encoding="utf-8"?>
                <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                           xmlns="urn:xsd2php-semantic-test"
                           targetNamespace="urn:xsd2php-semantic-test"
                           elementFormDefault="qualified">
                  <xs:simpleType name="EmailType">
                    <xs:restriction base="xs:string"/>
                  </xs:simpleType>
                  <xs:simpleType name="CountryCodeType">
                    <xs:restriction base="xs:string">
                      <xs:pattern value="\w{2}"/>
                    </xs:restriction>
                  </xs:simpleType>
                  <xs:complexType name="ContactType">
                    <xs:sequence>
                      <xs:element name="Email" type="EmailType"/>
                      <xs:element name="Country" type="CountryCodeType"/>
                    </xs:sequence>
                  </xs:complexType>
                </xs:schema>
                XSD);

            $config = new Config(
                xsdPaths: [$tmpDir.'/xsd/schema.xsd'],
                namespaceMap: ['urn:xsd2php-semantic-test' => new NamespaceMapping($phpNamespace, $tmpDir.'/out')],
                attributeStrategy: new SemanticTypeAttributeStrategy(self::ALIAS_MAP + [
                    'CountryCodeType' => ['fqcn' => Country::class, 'args' => ''],
                ]),
            );
            new Generator($config)->generate();

            require $tmpDir.'/out/ContactType.php';
            $class = $phpNamespace.'\\ContactType';

            $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

            $this->assertCount(0, $validator->validate(new $class(email: 'test@example.com', country: 'AT')));

            $violations = $validator->validate(new $class(email: 'not-an-email', country: 'AT'));
            $this->assertGreaterThanOrEqual(1, \count($violations));
            $this->assertInstanceOf(Email::class, $violations[0]->getConstraint());

            // "XX" matches the schema's own \w{2} pattern (no Regex here since this type has
            // none) but isn't a real ISO-3166 code - exactly the gap Assert\Country closes.
            $violations = $validator->validate(new $class(email: 'test@example.com', country: 'XX'));
            $this->assertGreaterThanOrEqual(1, \count($violations));
            $this->assertInstanceOf(Country::class, $violations[0]->getConstraint());
        } finally {
            $this->removeDir($tmpDir);
        }
    }
}
