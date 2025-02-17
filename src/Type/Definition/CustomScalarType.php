<?php

declare(strict_types=1);

namespace GraphQL\Type\Definition;

use GraphQL\Error\InvariantViolation;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeExtensionNode;
use GraphQL\Utils\AST;

use function is_callable;

/**
 * @phpstan-type CustomScalarConfig array{
 *   name?: string|null,
 *   description?: string|null,
 *   serialize: callable(mixed): mixed,
 *   parseValue?: callable(mixed): mixed,
 *   parseLiteral?: callable(Node $valueNode, array|null $variables): mixed,
 *   astNode?: ScalarTypeDefinitionNode|null,
 *   extensionASTNodes?: array<ScalarTypeExtensionNode>|null,
 * }
 */
class CustomScalarType extends ScalarType
{
    /** @phpstan-var CustomScalarConfig */
    // @phpstan-ignore-next-line specialize type
    public array $config;

    /**
     * @phpstan-param CustomScalarConfig $config
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
    }

    public function serialize($value)
    {
        return $this->config['serialize']($value);
    }

    public function parseValue($value)
    {
        if (isset($this->config['parseValue'])) {
            return $this->config['parseValue']($value);
        }

        return $value;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null)
    {
        if (isset($this->config['parseLiteral'])) {
            return $this->config['parseLiteral']($valueNode, $variables);
        }

        return AST::valueFromASTUntyped($valueNode, $variables);
    }

    public function assertValid(): void
    {
        parent::assertValid();

        // @phpstan-ignore-next-line should not happen if used correctly
        if (! isset($this->config['serialize']) || ! is_callable($this->config['serialize'])) {
            throw new InvariantViolation(
                "{$this->name} must provide \"serialize\" function. If this custom Scalar " .
                'is also used as an input type, ensure "parseValue" and "parseLiteral" ' .
                'functions are also provided.'
            );
        }

        $parseValue   = $this->config['parseValue'] ?? null;
        $parseLiteral = $this->config['parseLiteral'] ?? null;
        if ($parseValue === null && $parseLiteral === null) {
            return;
        }

        if (! is_callable($parseValue) || ! is_callable($parseLiteral)) {
            throw new InvariantViolation(
                "{$this->name} must provide both \"parseValue\" and \"parseLiteral\" functions."
            );
        }
    }
}
