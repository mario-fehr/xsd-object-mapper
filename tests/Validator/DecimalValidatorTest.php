<?php

declare(strict_types=1);

namespace Xsd2Php\Tests\Validator;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Xsd2Php\Validator\Decimal;

final class DecimalValidatorTest extends TestCase
{
    public function testFractionDigits(): void
    {
        $validator = Validation::createValidator();
        $constraint = new Decimal(fractionDigits: 2);

        self::assertCount(0, $validator->validate(12.34, $constraint));
        self::assertCount(0, $validator->validate(12, $constraint));
        self::assertCount(0, $validator->validate('12.3', $constraint));
        self::assertCount(1, $validator->validate(12.345, $constraint));
        self::assertCount(1, $validator->validate('12.345', $constraint));
    }

    public function testTotalDigits(): void
    {
        $validator = Validation::createValidator();
        $constraint = new Decimal(totalDigits: 3);

        self::assertCount(0, $validator->validate(123, $constraint));
        self::assertCount(0, $validator->validate(1.23, $constraint));
        self::assertCount(0, $validator->validate(0.12, $constraint));
        // leading zeros in the integer part don't count as significant digits
        self::assertCount(0, $validator->validate('001.23', $constraint));
        // 0 itself is 1 significant digit, not 0
        self::assertCount(0, $validator->validate(0, $constraint));
        self::assertCount(1, $validator->validate(1234, $constraint));
        self::assertCount(1, $validator->validate(1.2345, $constraint));
    }

    public function testTotalDigitsIgnoresLeadingZerosInFractionWhenIntegerPartIsZero(): void
    {
        // '0.05' has 1 significant digit (the '5'), not 2 - the leading zero right after the
        // decimal point isn't significant when the integer part is zero, same as the leading
        // zeros in the integer part itself.
        $validator = Validation::createValidator();
        $constraint = new Decimal(totalDigits: 1);

        self::assertCount(0, $validator->validate(0.05, $constraint));
        self::assertCount(0, $validator->validate('0.05', $constraint));
        self::assertCount(0, $validator->validate('0.005', $constraint));
        // a non-zero integer part still counts every fraction digit as significant
        self::assertCount(1, $validator->validate('1.05', new Decimal(totalDigits: 2)));
    }

    public function testBothFacetsCombined(): void
    {
        $validator = Validation::createValidator();
        $constraint = new Decimal(fractionDigits: 2, totalDigits: 4);

        self::assertCount(0, $validator->validate(12.34, $constraint));
        // fails only fractionDigits
        self::assertCount(1, $validator->validate(1.234, $constraint));
        // fails only totalDigits (1 fraction digit is fine, but 4+1=5 significant digits > 4)
        self::assertCount(1, $validator->validate(1234.5, $constraint));
        // fails both
        self::assertCount(2, $validator->validate(123.456, $constraint));
    }

    public function testNullAndEmptyStringAreIgnored(): void
    {
        $validator = Validation::createValidator();
        $constraint = new Decimal(fractionDigits: 2);

        self::assertCount(0, $validator->validate(null, $constraint));
        self::assertCount(0, $validator->validate('', $constraint));
    }

    public function testScientificNotationFloatIsNormalizedBeforeCounting(): void
    {
        // (string) 0.000012 casts to "1.2E-5" in PHP - without normalization, the naive
        // '.'-split would misparse the exponent as fraction digits instead of counting the
        // real 6 fraction digits this value actually has.
        $validator = Validation::createValidator();

        self::assertCount(0, $validator->validate(0.000012, new Decimal(fractionDigits: 6)));
        self::assertCount(1, $validator->validate(0.000012, new Decimal(fractionDigits: 5)));
    }

    public function testLargeFloatScientificNotationIsNormalizedForTotalDigits(): void
    {
        // (string) 123456789012345.0 casts to scientific notation once it exceeds ~14-15
        // significant digits (depends on the `precision` ini setting) - same misparse risk.
        $validator = Validation::createValidator();
        $constraint = new Decimal(totalDigits: 15);

        self::assertCount(0, $validator->validate(123456789012345.0, $constraint));
        self::assertCount(1, $validator->validate(1234567890123456.0, $constraint));
    }
}
