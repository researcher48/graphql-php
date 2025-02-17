<?php

declare(strict_types=1);

namespace GraphQL\Language;

use GraphQL\Utils\Utils;

use function json_decode;
use function mb_strlen;
use function mb_substr;
use function preg_match_all;

use const PREG_OFFSET_CAPTURE;

class Source
{
    public string $body;

    public int $length;

    public string $name;

    public SourceLocation $locationOffset;

    /**
     * A representation of source input to GraphQL.
     *
     * `name` and `locationOffset` are optional. They are useful for clients who
     * store GraphQL documents in source files; for example, if the GraphQL input
     * starts at line 40 in a file named Foo.graphql, it might be useful for name to
     * be "Foo.graphql" and location to be `{ line: 40, column: 0 }`.
     * line and column in locationOffset are 1-indexed
     */
    public function __construct(string $body, ?string $name = null, ?SourceLocation $location = null)
    {
        $this->body           = $body;
        $this->length         = mb_strlen($body, 'UTF-8');
        $this->name           = $name === '' || $name === null
            ? 'GraphQL request'
            : $name;
        $this->locationOffset = $location ?? new SourceLocation(1, 1);

        Utils::invariant(
            $this->locationOffset->line > 0,
            'line in locationOffset is 1-indexed and must be positive'
        );
        Utils::invariant(
            $this->locationOffset->column > 0,
            'column in locationOffset is 1-indexed and must be positive'
        );
    }

    public function getLocation(int $position): SourceLocation
    {
        $line   = 1;
        $column = $position + 1;

        $utfChars   = json_decode('"\u2028\u2029"');
        $lineRegexp = '/\r\n|[\n\r' . $utfChars . ']/su';
        $matches    = [];
        preg_match_all($lineRegexp, mb_substr($this->body, 0, $position, 'UTF-8'), $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            $line += 1;

            $column = $position + 1 - ($match[1] + mb_strlen($match[0], 'UTF-8'));
        }

        return new SourceLocation($line, $column);
    }
}
