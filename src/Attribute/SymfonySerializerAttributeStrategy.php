<?php

declare(strict_types=1);

namespace Xsd2Php\Attribute;

/**
 * Emits symfony/serializer's #[SerializedName]/#[Context] attributes: '@Name' for
 * an xs:attribute, '#' for simpleContent text, bare 'Name' for an xs:element.
 * #[Context(['datetime_format' => 'Y-m-d'])] is added for date-only (not dateTime) properties.
 */
final class SymfonySerializerAttributeStrategy implements PropertyAttributeStrategy
{
    public function attributesFor(array $property): array
    {
        $serializedName = $property['isText']
            ? '#'
            : ($property['isAttribute'] ? '@' . $property['xmlName'] : $property['xmlName']);

        $attrs = [[
            'fqcn' => 'Symfony\Component\Serializer\Attribute\SerializedName',
            'args' => var_export($serializedName, true),
        ]];

        if (!empty($property['dateOnly'])) {
            $attrs[] = [
                'fqcn' => 'Symfony\Component\Serializer\Attribute\Context',
                'args' => "['datetime_format' => 'Y-m-d']",
            ];
        }

        return $attrs;
    }
}
