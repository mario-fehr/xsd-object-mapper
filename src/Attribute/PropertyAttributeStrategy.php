<?php

declare(strict_types=1);

namespace XsdObjectMapper\Attribute;

use XsdObjectMapper\Property;

/**
 * Extension point: decides which PHP attributes get emitted above a generated
 * constructor-promoted property (e.g. serializer attributes for XML mapping).
 */
interface PropertyAttributeStrategy
{
    /**
     * @return list<array{fqcn: string, args: string}> one entry per attribute to emit; fqcn
     *                                                 is the attribute class without a leading backslash, args the already-rendered PHP
     *                                                 argument list (e.g. "'Foo'" or "['datetime_format' => 'Y-m-d']"). The generator
     *                                                 collects a `use` import per distinct fqcn across the class (falling back to an
     *                                                 inline fully-qualified name only if two different fqcns share a class basename).
     */
    public function attributesFor(Property $property): array;
}
