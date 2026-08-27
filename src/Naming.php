<?php

declare(strict_types=1);

namespace Xsd2Php;

/** PHP identifier/type-name helpers shared across the generator. */
final class Naming
{
    /** @var array<string, true> keyed for O(1) lookup - built once, checked on every generated class/enum name */
    private const array PHP_RESERVED_WORDS = [
        'abstract' => true, 'and' => true, 'array' => true, 'as' => true, 'break' => true,
        'callable' => true, 'case' => true, 'catch' => true, 'class' => true, 'clone' => true,
        'const' => true, 'continue' => true, 'declare' => true, 'default' => true, 'do' => true,
        'echo' => true, 'else' => true, 'elseif' => true, 'empty' => true, 'enddeclare' => true,
        'endfor' => true, 'endforeach' => true, 'endif' => true, 'endswitch' => true, 'endwhile' => true,
        'enum' => true, 'eval' => true, 'exit' => true, 'extends' => true, 'final' => true,
        'finally' => true, 'fn' => true, 'for' => true, 'foreach' => true, 'function' => true,
        'global' => true, 'goto' => true, 'if' => true, 'implements' => true, 'include' => true,
        'include_once' => true, 'instanceof' => true, 'insteadof' => true, 'interface' => true,
        'isset' => true, 'list' => true, 'match' => true, 'namespace' => true, 'new' => true,
        'or' => true, 'print' => true, 'private' => true, 'protected' => true, 'public' => true,
        'readonly' => true, 'require' => true, 'require_once' => true, 'return' => true,
        'static' => true, 'switch' => true, 'throw' => true, 'trait' => true, 'try' => true,
        'unset' => true, 'use' => true, 'var' => true, 'while' => true, 'xor' => true,
        'yield' => true, 'self' => true, 'parent' => true, 'true' => true, 'false' => true, 'null' => true,
    ];

    private function __construct()
    {
    }

    /** @return array{0: ?string, 1: string} [prefix, localName] - prefix is null if $qname is unprefixed */
    public static function splitQName(string $qname): array
    {
        $pos = strpos($qname, ':');

        return false === $pos ? [null, $qname] : [substr($qname, 0, $pos), substr($qname, $pos + 1)];
    }

    public static function localName(string $qname): string
    {
        return self::splitQName($qname)[1];
    }

    public static function basename(string $fqcn): string
    {
        return substr(strrchr('\\'.$fqcn, '\\'), 1);
    }

    public static function xsPrimitiveToPhp(string $local): string
    {
        return match ($local) {
            'boolean' => 'bool',
            'int', 'integer', 'short', 'long', 'byte',
            'unsignedInt', 'unsignedShort', 'unsignedLong', 'unsignedByte',
            'positiveInteger', 'nonNegativeInteger', 'negativeInteger', 'nonPositiveInteger' => 'int',
            'decimal', 'float', 'double' => 'float',
            'date', 'dateTime' => '\DateTimeImmutable',
            default => 'string',
        };
    }

    public static function sanitizeIdentifier(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        if ('' === $name || ctype_digit($name[0])) {
            return 'V'.$name;
        }

        return $name;
    }

    public static function toPropName(string $name): string
    {
        return lcfirst(self::sanitizeIdentifier($name));
    }

    public static function toClassName(string $name): string
    {
        $name = ucfirst(self::sanitizeIdentifier($name));
        if (isset(self::PHP_RESERVED_WORDS[strtolower($name)])) {
            $name .= 'Type';
        }

        return $name;
    }
}
