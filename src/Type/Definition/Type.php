<?php

declare(strict_types=1);

namespace GraphQL\Type\Definition;

use GraphQL\Error\InvariantViolation;
use GraphQL\Type\Introspection;
use GraphQL\Utils\Utils;
use JsonSerializable;
use ReturnTypeWillChange;

use function array_keys;
use function array_merge;
use function implode;

/**
 * Registry of standard GraphQL types and base class for all other types.
 */
abstract class Type implements JsonSerializable
{
    public const STRING  = 'String';
    public const INT     = 'Int';
    public const BOOLEAN = 'Boolean';
    public const FLOAT   = 'Float';
    public const ID      = 'ID';

    /** @var array<string, ScalarType> */
    protected static array $standardTypes;

    /** @var array<string, Type> */
    private static array $builtInTypes;

    /**
     * @api
     */
    public static function id(): ScalarType
    {
        if (! isset(static::$standardTypes[self::ID])) {
            static::$standardTypes[self::ID] = new IDType();
        }

        return static::$standardTypes[self::ID];
    }

    /**
     * @api
     */
    public static function string(): ScalarType
    {
        if (! isset(static::$standardTypes[self::STRING])) {
            static::$standardTypes[self::STRING] = new StringType();
        }

        return static::$standardTypes[self::STRING];
    }

    /**
     * @api
     */
    public static function boolean(): ScalarType
    {
        if (! isset(static::$standardTypes[self::BOOLEAN])) {
            static::$standardTypes[self::BOOLEAN] = new BooleanType();
        }

        return static::$standardTypes[self::BOOLEAN];
    }

    /**
     * @api
     */
    public static function int(): ScalarType
    {
        if (! isset(static::$standardTypes[self::INT])) {
            static::$standardTypes[self::INT] = new IntType();
        }

        return static::$standardTypes[self::INT];
    }

    /**
     * @api
     */
    public static function float(): ScalarType
    {
        if (! isset(static::$standardTypes[self::FLOAT])) {
            static::$standardTypes[self::FLOAT] = new FloatType();
        }

        return static::$standardTypes[self::FLOAT];
    }

    /**
     * @param Type|callable():Type $type
     *
     * @api
     */
    public static function listOf($type): ListOfType
    {
        return new ListOfType($type);
    }

    /**
     * code sniffer doesn't understand this syntax. Pr with a fix here: waiting on https://github.com/squizlabs/PHP_CodeSniffer/pull/2919
     * phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType
     * @param (NullableType&Type)|callable():(NullableType&Type) $type
     *
     * @api
     */
    public static function nonNull($type): NonNull
    {
        return new NonNull($type);
    }

    /**
     * Returns all builtin in types including base scalar and introspection types.
     *
     * @return array<string, Type>
     */
    public static function getAllBuiltInTypes(): array
    {
        if (! isset(self::$builtInTypes)) {
            self::$builtInTypes = array_merge(
                Introspection::getTypes(),
                self::getStandardTypes()
            );
        }

        return self::$builtInTypes;
    }

    /**
     * Returns all builtin scalar types.
     *
     * @return array<string, ScalarType>
     */
    public static function getStandardTypes(): array
    {
        return [
            self::ID => static::id(),
            self::STRING => static::string(),
            self::FLOAT => static::float(),
            self::INT => static::int(),
            self::BOOLEAN => static::boolean(),
        ];
    }

    /**
     * @param array<ScalarType> $types
     */
    public static function overrideStandardTypes(array $types): void
    {
        $standardTypes = self::getStandardTypes();
        foreach ($types as $type) {
            // @phpstan-ignore-next-line generic type is not enforced by PHP
            if (! $type instanceof Type) {
                $typeClass = self::class;
                $notType   = Utils::printSafe($type);

                throw new InvariantViolation("Expecting instance of {$typeClass}, got {$notType}");
            }

            if (! isset($type->name, $standardTypes[$type->name])) {
                $standardTypeNames   = implode(', ', array_keys($standardTypes));
                $notStandardTypeName = Utils::printSafe($type->name ?? null);

                throw new InvariantViolation("Expecting one of the following names for a standard type: {$standardTypeNames}; got {$notStandardTypeName}");
            }

            static::$standardTypes[$type->name] = $type;
        }
    }

    /**
     * @api
     */
    public static function isInputType($type): bool
    {
        return self::getNamedType($type) instanceof InputType;
    }

    /**
     * @return (Type&NamedType)|null
     *
     * @api
     */
    public static function getNamedType(?Type $type): ?Type
    {
        /** @var (Type&WrappingType)|(Type&NamedType)|null $type */
        return $type instanceof WrappingType
            ? $type->getInnermostType()
            : $type;
    }

    /**
     * @api
     */
    public static function isOutputType($type): bool
    {
        return self::getNamedType($type) instanceof OutputType;
    }

    /**
     * @api
     */
    public static function isLeafType($type): bool
    {
        return $type instanceof LeafType;
    }

    /**
     * @api
     */
    public static function isCompositeType($type): bool
    {
        return $type instanceof CompositeType;
    }

    /**
     * @api
     */
    public static function isAbstractType($type): bool
    {
        return $type instanceof AbstractType;
    }

    /**
     * @return Type&NullableType
     *
     * @api
     */
    public static function getNullableType(Type $type): Type
    {
        /** @var (Type&NullableType)|(Type&NonNull) $type */
        return $type instanceof NonNull
            ? $type->getWrappedType()
            : $type;
    }

    abstract public function toString(): string;

    public function __toString(): string
    {
        return $this->toString();
    }

    #[ReturnTypeWillChange]
    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}
