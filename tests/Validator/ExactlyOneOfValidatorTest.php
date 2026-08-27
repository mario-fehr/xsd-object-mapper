<?php

declare(strict_types=1);

namespace Xsd2Php\Tests\Validator;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Xsd2Php\Validator\ExactlyOneOf;

final class ExactlyOneOfValidatorTest extends TestCase
{
    public function testExactlyOneSetPasses(): void
    {
        $validator = Validation::createValidator();
        $constraint = new ExactlyOneOf(fields: ['a', 'b']);

        $object = new class {
            public ?string $a = 'x';
            public ?string $b = null;
        };

        $this->assertCount(0, $validator->validate($object, $constraint));
    }

    public function testNoneSetFails(): void
    {
        $validator = Validation::createValidator();
        $constraint = new ExactlyOneOf(fields: ['a', 'b']);

        $object = new class {
            public ?string $a = null;
            public ?string $b = null;
        };

        $this->assertCount(1, $validator->validate($object, $constraint));
    }

    public function testBothSetFails(): void
    {
        $validator = Validation::createValidator();
        $constraint = new ExactlyOneOf(fields: ['a', 'b']);

        $object = new class {
            public ?string $a = 'x';
            public ?string $b = 'y';
        };

        $this->assertCount(1, $validator->validate($object, $constraint));
    }

    public function testEmptyArrayCountsAsNotSet(): void
    {
        // array-typed choice fields (xs:element maxOccurs="unbounded") default to [], never
        // null - must count as "not set" the same as null, or a repeated-element choice branch
        // would always look "set" even when untouched.
        $validator = Validation::createValidator();
        $constraint = new ExactlyOneOf(fields: ['items', 'single']);

        $untouched = new class {
            public array $items = [];
            public ?string $single = 'x';
        };
        $this->assertCount(0, $validator->validate($untouched, $constraint));

        $populated = new class {
            public array $items = ['a'];
            public ?string $single = null;
        };
        $this->assertCount(0, $validator->validate($populated, $constraint));
    }

    public function testOptionalGroupAllowsNoneSetButNotBothSet(): void
    {
        $validator = Validation::createValidator();
        $constraint = new ExactlyOneOf(fields: ['a', 'b'], required: false);

        $noneSet = new class {
            public ?string $a = null;
            public ?string $b = null;
        };
        $this->assertCount(0, $validator->validate($noneSet, $constraint));

        $oneSet = new class {
            public ?string $a = 'x';
            public ?string $b = null;
        };
        $this->assertCount(0, $validator->validate($oneSet, $constraint));

        $bothSet = new class {
            public ?string $a = 'x';
            public ?string $b = 'y';
        };
        $this->assertCount(1, $validator->validate($bothSet, $constraint));
    }
}
