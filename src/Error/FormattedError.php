<?php

declare(strict_types=1);

namespace GraphQL\Error;

use Countable;
use ErrorException;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Language\AST\Node;
use GraphQL\Language\Source;
use GraphQL\Language\SourceLocation;
use GraphQL\Type\Definition\Type;
use Throwable;

use function addcslashes;
use function array_filter;
use function array_intersect_key;
use function array_map;
use function array_merge;
use function array_shift;
use function count;
use function get_class;
use function gettype;
use function implode;
use function is_array;
use function is_bool;
use function is_object;
use function is_scalar;
use function is_string;
use function mb_strlen;
use function preg_split;
use function str_repeat;
use function strlen;

/**
 * This class is used for [default error formatting](error-handling.md).
 * It converts PHP exceptions to [spec-compliant errors](https://facebook.github.io/graphql/#sec-Errors)
 * and provides tools for error debugging.
 *
 * @phpstan-type FormattedErrorArray array{
 *   message: string,
 *   locations?: array<int, array{line: int, column: int}>,
 *   path?: array<int, int|string>,
 *   extensions?: array<string, mixed>,
 * }
 * @phpstan-import-type ErrorFormatter from ExecutionResult
 */
class FormattedError
{
    private static string $internalErrorMessage = 'Internal server error';

    /**
     * Set default error message for internal errors formatted using createFormattedError().
     * This value can be overridden by passing 3rd argument to `createFormattedError()`.
     *
     * @api
     */
    public static function setInternalErrorMessage(string $msg): void
    {
        self::$internalErrorMessage = $msg;
    }

    /**
     * Prints a GraphQLError to a string, representing useful location information
     * about the error's position in the source.
     */
    public static function printError(Error $error): string
    {
        $printedLocations = [];
        if (count($error->nodes ?? []) !== 0) {
            /** @var Node $node */
            foreach ($error->nodes as $node) {
                if ($node->loc === null) {
                    continue;
                }

                if ($node->loc->source === null) {
                    continue;
                }

                $printedLocations[] = self::highlightSourceAtLocation(
                    $node->loc->source,
                    $node->loc->source->getLocation($node->loc->start)
                );
            }
        } elseif ($error->getSource() !== null && count($error->getLocations()) !== 0) {
            $source = $error->getSource();
            foreach ($error->getLocations() as $location) {
                $printedLocations[] = self::highlightSourceAtLocation($source, $location);
            }
        }

        return count($printedLocations) === 0
            ? $error->getMessage()
            : implode("\n\n", array_merge([$error->getMessage()], $printedLocations)) . "\n";
    }

    /**
     * Render a helpful description of the location of the error in the GraphQL
     * Source document.
     */
    private static function highlightSourceAtLocation(Source $source, SourceLocation $location): string
    {
        $line          = $location->line;
        $lineOffset    = $source->locationOffset->line - 1;
        $columnOffset  = self::getColumnOffset($source, $location);
        $contextLine   = $line + $lineOffset;
        $contextColumn = $location->column + $columnOffset;
        $prevLineNum   = (string) ($contextLine - 1);
        $lineNum       = (string) $contextLine;
        $nextLineNum   = (string) ($contextLine + 1);
        $padLen        = strlen($nextLineNum);
        $lines         = preg_split('/\r\n|[\n\r]/', $source->body);

        $lines[0] = self::whitespace($source->locationOffset->column - 1) . $lines[0];

        $outputLines = [
            "{$source->name} ({$contextLine}:{$contextColumn})",
            $line >= 2 ? (self::lpad($padLen, $prevLineNum) . ': ' . $lines[$line - 2]) : null,
            self::lpad($padLen, $lineNum) . ': ' . $lines[$line - 1],
            self::whitespace(2 + $padLen + $contextColumn - 1) . '^',
            $line < count($lines) ? self::lpad($padLen, $nextLineNum) . ': ' . $lines[$line] : null,
        ];

        return implode("\n", array_filter($outputLines));
    }

    private static function getColumnOffset(Source $source, SourceLocation $location): int
    {
        return $location->line === 1
            ? $source->locationOffset->column - 1
            : 0;
    }

    private static function whitespace(int $len): string
    {
        return str_repeat(' ', $len);
    }

    private static function lpad(int $len, string $str): string
    {
        return self::whitespace($len - mb_strlen($str)) . $str;
    }

    /**
     * Convert any exception to a GraphQL spec compliant array.
     *
     * This method only exposes the exception message when the given exception
     * implements the ClientAware interface, or when debug flags are passed.
     *
     * For a list of available debug flags @see \GraphQL\Error\DebugFlag constants.
     *
     * @return FormattedErrorArray
     *
     * @api
     */
    public static function createFromException(Throwable $exception, int $debugFlag = DebugFlag::NONE, ?string $internalErrorMessage = null): array
    {
        $internalErrorMessage ??= self::$internalErrorMessage;

        $message = $exception instanceof ClientAware && $exception->isClientSafe()
            ? $exception->getMessage()
            : $internalErrorMessage;

        $formattedError = ['message' => $message];

        if ($exception instanceof Error) {
            $locations = array_map(
                static fn (SourceLocation $loc): array => $loc->toSerializableArray(),
                $exception->getLocations()
            );
            if (count($locations) > 0) {
                $formattedError['locations'] = $locations;
            }

            if ($exception->path !== null && count($exception->path) > 0) {
                $formattedError['path'] = $exception->path;
            }
        }

        if ($exception instanceof ProvidesExtensions) {
            $extensions = $exception->getExtensions();
            if (is_array($extensions) && count($extensions) > 0) {
                $formattedError['extensions'] = $extensions;
            }
        }

        if ($debugFlag !== DebugFlag::NONE) {
            $formattedError = self::addDebugEntries($formattedError, $exception, $debugFlag);
        }

        return $formattedError;
    }

    /**
     * Decorates spec-compliant $formattedError with debug entries according to $debug flags.
     *
     * phpcs:disable SlevomatCodingStandard.Commenting.DocCommentSpacing.IncorrectAnnotationsGroup
     * @param int                 $debugFlag      For available flags @see \GraphQL\Error\DebugFlag
     *
     * @param FormattedErrorArray $formattedError
     *
     * @return FormattedErrorArray
     */
    public static function addDebugEntries(array $formattedError, Throwable $e, int $debugFlag): array
    {
        if ($debugFlag === DebugFlag::NONE) {
            return $formattedError;
        }

        if (( $debugFlag & DebugFlag::RETHROW_INTERNAL_EXCEPTIONS) !== 0) {
            if (! $e instanceof Error) {
                throw $e;
            }

            if ($e->getPrevious() !== null) {
                throw $e->getPrevious();
            }
        }

        $isUnsafe = ! $e instanceof ClientAware || ! $e->isClientSafe();

        if (($debugFlag & DebugFlag::RETHROW_UNSAFE_EXCEPTIONS) !== 0 && $isUnsafe && $e->getPrevious() !== null) {
            throw $e->getPrevious();
        }

        if (($debugFlag & DebugFlag::INCLUDE_DEBUG_MESSAGE) !== 0 && $isUnsafe) {
            $formattedError['extensions']['debugMessage'] = $e->getMessage();
        }

        if (($debugFlag & DebugFlag::INCLUDE_TRACE) !== 0) {
            if ($e instanceof ErrorException || $e instanceof \Error) {
                $formattedError['extensions']['file'] = $e->getFile();
                $formattedError['extensions']['line'] = $e->getLine();
            }

            $isTrivial = $e instanceof Error && $e->getPrevious() === null;

            if (! $isTrivial) {
                $formattedError['extensions']['trace'] = static::toSafeTrace($e->getPrevious() ?? $e);
            }
        }

        return $formattedError;
    }

    /**
     * Prepares final error formatter taking in account $debug flags.
     *
     * If initial formatter is not set, FormattedError::createFromException is used.
     *
     * @phpstan-param ErrorFormatter|null $formatter
     */
    public static function prepareFormatter(?callable $formatter, int $debug): callable
    {
        $formatter ??= [self::class, 'createFromException'];

        if ($debug !== DebugFlag::NONE) {
            $formatter = static fn (Throwable $e): array => self::addDebugEntries($formatter($e), $e, $debug);
        }

        return $formatter;
    }

    /**
     * Returns error trace as serializable array.
     *
     * @return array<int, array{
     *     file: string,
     *     line: int,
     *     function?: string,
     *     call?: string,
     * }>
     *
     * @api
     */
    public static function toSafeTrace(Throwable $error): array
    {
        $trace = $error->getTrace();

        if (
            isset($trace[0]['function']) && isset($trace[0]['class']) &&
            // Remove invariant entries as they don't provide much value:
            ($trace[0]['class'] . '::' . $trace[0]['function'] === 'GraphQL\Utils\Utils::invariant')
        ) {
            array_shift($trace);
        } elseif (! isset($trace[0]['file'])) {
            // Remove root call as it's likely error handler trace:
            array_shift($trace);
        }

        return array_map(
            static function (array $err): array {
                $safeErr = array_intersect_key($err, ['file' => true, 'line' => true]);

                if (isset($err['function'])) {
                    $func    = $err['function'];
                    $args    = array_map([self::class, 'printVar'], $err['args'] ?? []);
                    $funcStr = $func . '(' . implode(', ', $args) . ')';

                    if (isset($err['class'])) {
                        $safeErr['call'] = $err['class'] . '::' . $funcStr;
                    } else {
                        $safeErr['function'] = $funcStr;
                    }
                }

                return $safeErr;
            },
            $trace
        );
    }

    /**
     * @param mixed $var
     */
    public static function printVar($var): string
    {
        if ($var instanceof Type) {
            return 'GraphQLType: ' . $var->toString();
        }

        if (is_object($var)) {
            return 'instance of ' . get_class($var) . ($var instanceof Countable ? '(' . count($var) . ')' : '');
        }

        if (is_array($var)) {
            return 'array(' . count($var) . ')';
        }

        if ($var === '') {
            return '(empty string)';
        }

        if (is_string($var)) {
            return "'" . addcslashes($var, "'") . "'";
        }

        if (is_bool($var)) {
            return $var ? 'true' : 'false';
        }

        if (is_scalar($var)) {
            return (string) $var;
        }

        if ($var === null) {
            return 'null';
        }

        return gettype($var);
    }
}
