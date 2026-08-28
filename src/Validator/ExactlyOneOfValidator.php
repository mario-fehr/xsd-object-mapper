<?php

declare(strict_types=1);

namespace XsdObjectMapper\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ExactlyOneOfValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ExactlyOneOf) {
            throw new UnexpectedTypeException($constraint, ExactlyOneOf::class);
        }

        if (null === $value) {
            return;
        }

        $setCount = 0;
        foreach ($constraint->fields as $field) {
            // array-typed choice fields (xs:element maxOccurs="unbounded" inside xs:choice)
            // default to [], never null - both count as "not set" alongside plain null.
            $fieldValue = $value->{$field};
            if (null !== $fieldValue && [] !== $fieldValue) {
                ++$setCount;
            }
        }

        if ($constraint->required && 1 !== $setCount) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ fields }}', implode('", "', $constraint->fields))
                ->addViolation();
        } elseif (!$constraint->required && $setCount > 1) {
            $this->context->buildViolation($constraint->atMostOneMessage)
                ->setParameter('{{ fields }}', implode('", "', $constraint->fields))
                ->addViolation();
        }
    }
}
