<?php

declare(strict_types=1);

namespace GraphQL\Language\AST;

class ScalarTypeExtensionNode extends Node implements TypeExtensionNode
{
    public string $kind = NodeKind::SCALAR_TYPE_EXTENSION;

    /** @var NameNode */
    public $name;

    /** @var NodeList<DirectiveNode> */
    public $directives;
}
