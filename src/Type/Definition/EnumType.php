<?php

declare(strict_types=1);

namespace GraphQL\Type\Definition;

use GraphQL\Error\Error;
use GraphQL\Error\InvariantViolation;
use GraphQL\Error\SerializationError;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\EnumTypeExtensionNode;
use GraphQL\Language\AST\EnumValueNode;
use GraphQL\Language\AST\Node;
use GraphQL\Utils\MixedStore;
use GraphQL\Utils\Utils;

use function is_array;
use function is_iterable;
use function is_string;

/**
 * @phpstan-type PartialEnumValueConfig array{
 *   name?: string,
 *   value?: mixed,
 *   deprecationReason?: string|null,
 *   description?: string|null,
 *   astNode?: EnumValueDefinitionNode|null,
 * }
 * @phpstan-type EnumTypeConfig array{
 *   name?: string|null,
 *   description?: string|null,
 *   values: iterable<string, PartialEnumValueConfig>|iterable<string, mixed>|iterable<int, string>,
 *   astNode?: EnumTypeDefinitionNode|null,
 *   extensionASTNodes?: array<int, EnumTypeExtensionNode>|null,
 * }
 */
class EnumType extends Type implements InputType, OutputType, LeafType, NullableType, NamedType
{
    use NamedTypeImplementation;

    public ?EnumTypeDefinitionNode $astNode;

    /** @var array<int, EnumTypeExtensionNode> */
    public array $extensionASTNodes;

    /** @phpstan-var EnumTypeConfig */
    public array $config;

    /**
     * Lazily initialized.
     *
     * @var array<int, EnumValueDefinition>
     */
    private array $values;

    /**
     * Lazily initialized.
     *
     * Actually a MixedStore<mixed, EnumValueDefinition>, PHPStan won't let us type it that way.
     */
    private MixedStore $valueLookup;

    /** @var array<string, EnumValueDefinition> */
    private array $nameLookup;

    /**
     * @phpstan-param EnumTypeConfig $config
     */
    public function __construct(array $config)
    {
        $config['name'] ??= $this->tryInferName();
        Utils::invariant(is_string($config['name']), 'Must provide name.');

        $this->name              = $config['name'];
        $this->description       = $config['description'] ?? null;
        $this->astNode           = $config['astNode'] ?? null;
        $this->extensionASTNodes = $config['extensionASTNodes'] ?? [];

        $this->config = $config;
    }

    public function getValue(string $name): ?EnumValueDefinition
    {
        if (! isset($this->nameLookup)) {
            $this->initializeNameLookup();
        }

        return $this->nameLookup[$name] ?? null;
    }

    /**
     * @return array<int, EnumValueDefinition>
     */
    public function getValues(): array
    {
        if (! isset($this->values)) {
            $this->values = [];

            // We are just assuming the config option is set correctly here, validation happens in assertValid()
            foreach ($this->config['values'] as $name => $value) {
                if (is_string($name)) {
                    if (is_array($value)) {
                        $value += ['name' => $name, 'value' => $name];
                    } else {
                        $value = ['name' => $name, 'value' => $value];
                    }
                } elseif (is_string($value)) {
                    $value = ['name' => $value, 'value' => $value];
                } else {
                    throw new InvariantViolation("{$this->name} values must be an array with value names as keys or values.");
                }

                $this->values[] = new EnumValueDefinition($value);
            }
        }

        return $this->values;
    }

    public function serialize($value)
    {
        $lookup = $this->getValueLookup();
        if (isset($lookup[$value])) {
            return $lookup[$value]->name;
        }

        throw new SerializationError('Cannot serialize value as enum: ' . Utils::printSafe($value));
    }

    /**
     * Actually returns a MixedStore<mixed, EnumValueDefinition>, PHPStan won't let us type it that way
     */
    private function getValueLookup(): MixedStore
    {
        if (! isset($this->valueLookup)) {
            $this->valueLookup = new MixedStore();

            foreach ($this->getValues() as $value) {
                $this->valueLookup->offsetSet($value->value, $value);
            }
        }

        return $this->valueLookup;
    }

    public function parseValue($value)
    {
        if (! isset($this->nameLookup)) {
            $this->initializeNameLookup();
        }

        if (isset($this->nameLookup[$value])) {
            return $this->nameLookup[$value]->value;
        }

        throw new Error('Cannot represent value as enum: ' . Utils::printSafe($value));
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null)
    {
        if ($valueNode instanceof EnumValueNode) {
            $name = $valueNode->value;

            if (! isset($this->nameLookup)) {
                $this->initializeNameLookup();
            }

            if (isset($this->nameLookup[$name])) {
                return $this->nameLookup[$name]->value;
            }
        }

        // Intentionally without message, as all information already in wrapped Exception
        throw new Error();
    }

    /**
     * @throws InvariantViolation
     */
    public function assertValid(): void
    {
        Utils::assertValidName($this->name);

        $values = $this->config['values'] ?? null;
        // @phpstan-ignore-next-line should not happen if used correctly
        if (! is_iterable($values)) {
            $notIterable = Utils::printSafe($values);

            throw new InvariantViolation("{$this->name} values must be an iterable, got: {$notIterable}");
        }

        $this->getValues();
    }

    private function initializeNameLookup(): void
    {
        $this->nameLookup = [];
        foreach ($this->getValues() as $value) {
            $this->nameLookup[$value->name] = $value;
        }
    }
}
