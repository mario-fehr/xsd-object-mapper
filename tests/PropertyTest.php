<?php

declare(strict_types=1);

namespace XsdObjectMapper\Tests;

use PHPUnit\Framework\TestCase;
use XsdObjectMapper\Property;
use XsdObjectMapper\PropertyRole;

final class PropertyTest extends TestCase
{
    public function testTextPropertyWithNullXmlNameIsValid(): void
    {
        $property = new Property(phpName: 'Value', xmlName: null, role: PropertyRole::Text);

        $this->assertNull($property->xmlName);
    }

    public function testElementPropertyWithNonNullXmlNameIsValid(): void
    {
        $property = new Property(phpName: 'Name', xmlName: 'Name', role: PropertyRole::Element);

        $this->assertSame('Name', $property->xmlName);
    }

    public function testAttributePropertyWithNonNullXmlNameIsValid(): void
    {
        $property = new Property(phpName: 'Id', xmlName: 'id', role: PropertyRole::Attribute);

        $this->assertSame('id', $property->xmlName);
    }

    public function testTextPropertyWithNonNullXmlNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Property(phpName: 'Value', xmlName: 'value', role: PropertyRole::Text);
    }

    public function testElementPropertyWithNullXmlNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Property(phpName: 'Name', xmlName: null, role: PropertyRole::Element);
    }

    public function testAttributePropertyWithNullXmlNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Property(phpName: 'Id', xmlName: null, role: PropertyRole::Attribute);
    }
}
