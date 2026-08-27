<?php

declare(strict_types=1);

namespace Xsd2Php\Attribute;

use Xsd2Php\Property;

/** Merges the attributesFor() results of multiple strategies, in the order given. */
final readonly class CompositeAttributeStrategy implements PropertyAttributeStrategy
{
    /** @var list<PropertyAttributeStrategy> */
    private array $strategies;

    public function __construct(PropertyAttributeStrategy ...$strategies)
    {
        $this->strategies = array_values($strategies);
    }

    public function attributesFor(Property $property): array
    {
        $attrs = [];
        foreach ($this->strategies as $strategy) {
            $attrs = [...$attrs, ...$strategy->attributesFor($property)];
        }

        return $attrs;
    }
}
