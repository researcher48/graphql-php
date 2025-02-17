<?php

declare(strict_types=1);

namespace GraphQL\Examples\Blog;

use Closure;
use Exception;
use GraphQL\Examples\Blog\Type\CommentType;
use GraphQL\Examples\Blog\Type\Enum\ContentFormatType;
use GraphQL\Examples\Blog\Type\Enum\ImageSizeType;
use GraphQL\Examples\Blog\Type\Enum\StoryAffordancesType;
use GraphQL\Examples\Blog\Type\ImageType;
use GraphQL\Examples\Blog\Type\NodeType;
use GraphQL\Examples\Blog\Type\Scalar\EmailType;
use GraphQL\Examples\Blog\Type\Scalar\UrlType;
use GraphQL\Examples\Blog\Type\SearchResultType;
use GraphQL\Examples\Blog\Type\StoryType;
use GraphQL\Examples\Blog\Type\UserType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;

use function class_exists;
use function count;
use function explode;
use function lcfirst;
use function method_exists;
use function preg_replace;
use function strtolower;

/**
 * Acts as a registry and factory for your types.
 *
 * As simplistic as possible for the sake of clarity of this example.
 * Your own may be more dynamic (or even code-generated).
 */
final class Types
{
    /** @var array<string, Type> */
    private static array $types = [];

    public static function user(): callable
    {
        return self::get(UserType::class);
    }

    public static function story(): callable
    {
        return self::get(StoryType::class);
    }

    public static function comment(): callable
    {
        return self::get(CommentType::class);
    }

    public static function image(): callable
    {
        return self::get(ImageType::class);
    }

    public static function node(): callable
    {
        return self::get(NodeType::class);
    }

    public static function mention(): callable
    {
        return self::get(SearchResultType::class);
    }

    public static function imageSize(): callable
    {
        return self::get(ImageSizeType::class);
    }

    public static function contentFormat(): callable
    {
        return self::get(ContentFormatType::class);
    }

    public static function storyAffordances(): callable
    {
        return self::get(StoryAffordancesType::class);
    }

    public static function email(): callable
    {
        return self::get(EmailType::class);
    }

    public static function url(): callable
    {
        return self::get(UrlType::class);
    }

    /**
     * @return Closure(): Type
     */
    private static function get(string $classname): Closure
    {
        return static fn () => self::byClassName($classname);
    }

    private static function byClassName(string $classname): Type
    {
        $parts = explode('\\', $classname);

        $cacheName = strtolower(preg_replace('~Type$~', '', $parts[count($parts) - 1]));
        $type      = null;

        if (! isset(self::$types[$cacheName])) {
            if (class_exists($classname)) {
                $type = new $classname();
            }

            self::$types[$cacheName] = $type;
        }

        $type = self::$types[$cacheName];

        if (! $type) {
            throw new Exception('Unknown graphql type: ' . $classname);
        }

        return $type;
    }

    public static function byTypeName(string $shortName): Type
    {
        $cacheName = strtolower($shortName);
        $type      = null;

        if (isset(self::$types[$cacheName])) {
            return self::$types[$cacheName];
        }

        $method = lcfirst($shortName);
        if (method_exists(self::class, $method)) {
            $type = self::{$method}();
        }

        if (! $type) {
            throw new Exception('Unknown graphql type: ' . $shortName);
        }

        return $type;
    }

    public static function boolean(): ScalarType
    {
        return Type::boolean();
    }

    public static function float(): ScalarType
    {
        return Type::float();
    }

    public static function id(): ScalarType
    {
        return Type::id();
    }

    public static function int(): ScalarType
    {
        return Type::int();
    }

    public static function string(): ScalarType
    {
        return Type::string();
    }

    /**
     * @param Type|callable():Type $type
     */
    public static function listOf($type): ListOfType
    {
        return new ListOfType($type);
    }

    /**
     * @param Type|callable():Type $type
     */
    public static function nonNull($type): NonNull
    {
        return new NonNull($type);
    }
}
