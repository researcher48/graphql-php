<?php

declare(strict_types=1);

namespace GraphQL\Type\Definition;

use GraphQL\Error\InvariantViolation;
use GraphQL\Executor\Executor;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Type\Schema;
use GraphQL\Utils\Utils;

use function is_array;
use function is_callable;
use function is_iterable;
use function is_string;

/**
 * @phpstan-import-type FieldResolver from Executor
 * @phpstan-import-type FieldArgumentConfig from FieldArgument
 * @phpstan-type ComplexityFn callable(int, array<string, mixed>): int|null
 * @phpstan-type FieldDefinitionConfig array{
 *     name: string,
 *     type: (Type&OutputType)|callable(): (Type&OutputType),
 *     resolve?: FieldResolver|null,
 *     args?: array<string, FieldArgumentConfig|Type>|null,
 *     description?: string|null,
 *     deprecationReason?: string|null,
 *     astNode?: FieldDefinitionNode|null,
 *     complexity?: ComplexityFn|null,
 * }
 * @phpstan-type UnnamedFieldDefinitionConfig array{
 *     type: (Type&OutputType)|callable(): (Type&OutputType),
 *     resolve?: FieldResolver|null,
 *     args?: array<string, FieldArgumentConfig|Type>|null,
 *     description?: string|null,
 *     deprecationReason?: string|null,
 *     astNode?: FieldDefinitionNode|null,
 *     complexity?: ComplexityFn|null,
 * }
 * @phpstan-type FieldMapConfig (callable(): iterable<mixed>)|iterable<mixed>
 */
class FieldDefinition
{
    public string $name;

    /** @var array<int, FieldArgument> */
    public array $args;

    /**
     * Callback for resolving field value given parent value.
     *
     * @var callable|null
     * @phpstan-var FieldResolver|null
     */
    public $resolveFn;

    public ?string $description;

    public ?string $deprecationReason;

    public ?FieldDefinitionNode $astNode;

    /** @var ComplexityFn|null */
    public $complexityFn;

    /**
     * Original field definition config.
     *
     * @var FieldDefinitionConfig
     */
    public array $config;

    /** @var Type&OutputType */
    private Type $type;

    /**
     * @param FieldDefinitionConfig $config
     */
    protected function __construct(array $config)
    {
        $this->name              = $config['name'];
        $this->resolveFn         = $config['resolve'] ?? null;
        $this->args              = isset($config['args'])
            ? FieldArgument::createMap($config['args'])
            : [];
        $this->description       = $config['description'] ?? null;
        $this->deprecationReason = $config['deprecationReason'] ?? null;
        $this->astNode           = $config['astNode'] ?? null;
        $this->complexityFn      = $config['complexity'] ?? null;

        $this->config = $config;
    }

    /**
     * @param ObjectType|InterfaceType $parentType
     * @param callable|iterable        $fields
     * @phpstan-param FieldMapConfig $fields
     *
     * @return array<string, self>
     */
    public static function defineFieldMap(Type $parentType, $fields): array
    {
        if (is_callable($fields)) {
            $fields = $fields();
        }

        // @phpstan-ignore-next-line should not happen if used correctly
        if (! is_iterable($fields)) {
            throw new InvariantViolation(
                "{$parentType->name} fields must be an iterable or a callable which returns such an iterable."
            );
        }

        $map = [];
        foreach ($fields as $maybeName => $field) {
            if (is_array($field)) {
                if (! isset($field['name'])) {
                    if (! is_string($maybeName)) {
                        throw new InvariantViolation(
                            "{$parentType->name} fields must be an associative array with field names as keys or a function which returns such an array."
                        );
                    }

                    $field['name'] = $maybeName;
                }

                if (isset($field['args']) && ! is_array($field['args'])) {
                    throw new InvariantViolation(
                        "{$parentType->name}.{$maybeName} args must be an array."
                    );
                }

                $fieldDef = self::create($field);
            } elseif ($field instanceof self) {
                $fieldDef = $field;
            } elseif (is_callable($field)) {
                if (! is_string($maybeName)) {
                    throw new InvariantViolation(
                        "{$parentType->name} lazy fields must be an associative array with field names as keys."
                    );
                }

                $fieldDef = new UnresolvedFieldDefinition($parentType, $maybeName, $field);
            } elseif ($field instanceof Type) {
                $fieldDef = self::create(['name' => $maybeName, 'type' => $field]);
            } else {
                $invalidFieldConfig = Utils::printSafe($field);

                throw new InvariantViolation(
                    "{$parentType->name}.{$maybeName} field config must be an array, but got: {$invalidFieldConfig}"
                );
            }

            $map[$fieldDef->getName()] = $fieldDef;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $field
     */
    public static function create(array $field): FieldDefinition
    {
        return new self($field);
    }

    public function getArg(string $name): ?FieldArgument
    {
        foreach ($this->args as $arg) {
            /** @var FieldArgument $arg */
            if ($arg->name === $name) {
                return $arg;
            }
        }

        return null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return Type&OutputType
     */
    public function getType(): Type
    {
        return $this->type ??= Schema::resolveType($this->config['type']);
    }

    public function isDeprecated(): bool
    {
        return (bool) $this->deprecationReason;
    }

    /**
     * @param Type &NamedType $parentType
     *
     * @throws InvariantViolation
     */
    public function assertValid(Type $parentType): void
    {
        $error = Utils::isValidNameError($this->name);
        if ($error !== null) {
            throw new InvariantViolation("{$parentType->name}.{$this->name}: {$error->getMessage()}");
        }

        $type = Type::getNamedType($this->getType());

        if (! $type instanceof OutputType) {
            $safeType = Utils::printSafe($this->type);

            throw new InvariantViolation("{$parentType->name}.{$this->name} field type must be Output Type but got: {$safeType}");
        }

        if ($this->resolveFn !== null && ! is_callable($this->resolveFn)) {
            $safeResolveFn = Utils::printSafe($this->resolveFn);

            throw new InvariantViolation("{$parentType->name}.{$this->name} field resolver must be a function if provided, but got: {$safeResolveFn}");
        }

        foreach ($this->args as $fieldArgument) {
            $fieldArgument->assertValid($this, $type);
        }
    }
}
