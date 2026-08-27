<?php

declare(strict_types=1);

namespace Xsd2Php\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Validates xs:choice semantics (no symfony/validator built-in): exactly one of the given
 * properties must be set - or, for a choice that is itself optional (xs:choice minOccurs="0"),
 * at most one.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class ExactlyOneOf extends Constraint
{
    public string $message = 'Exactly one of "{{ fields }}" must be set.';
    public string $atMostOneMessage = 'At most one of "{{ fields }}" may be set.';

    /** @param string[] $fields */
    public function __construct(
        public readonly array $fields,
        public readonly bool $required = true,
        ?string $message = null,
        ?string $atMostOneMessage = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);

        $this->message = $message ?? $this->message;
        $this->atMostOneMessage = $atMostOneMessage ?? $this->atMostOneMessage;
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
