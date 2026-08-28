<?php

declare(strict_types=1);

namespace XsdObjectMapper\Attribute;

use XsdObjectMapper\Property;
use XsdObjectMapper\PropertyRole;

/**
 * Emits symfony/serializer's #[SerializedName]/#[Context] attributes: '@Name' for
 * an xs:attribute, '#' for simpleContent text, bare 'Name' for an xs:element.
 * #[Context(['datetime_format' => 'Y-m-d'])] is added for date-only (not dateTime) properties.
 */
final class SymfonySerializerAttributeStrategy implements PropertyAttributeStrategy
{
    public function attributesFor(Property $property): array
    {
        $serializedName = match ($property->role) {
            PropertyRole::Text => '#',
            PropertyRole::Attribute => '@'.$property->xmlName,
            PropertyRole::Element => $property->xmlName,
        };

        $attrs = [[
            'fqcn' => 'Symfony\Component\Serializer\Attribute\SerializedName',
            'args' => var_export($serializedName, true),
        ]];

        if ($property->dateOnly) {
            $attrs[] = [
                'fqcn' => 'Symfony\Component\Serializer\Attribute\Context',
                'args' => "['datetime_format' => 'Y-m-d']",
            ];
        }

        return $attrs;
    }
}
