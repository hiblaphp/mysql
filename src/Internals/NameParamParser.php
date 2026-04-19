<?php

declare(strict_types=1);

namespace Hibla\Mysql\Internals;

use InvalidArgumentException;

/**
 * @internal
 */
final class NameParamParser
{
    /**
     * Parses SQL containing named parameters (:name) into positional placeholders (?).
     * Offloads the logic into one method for faster execution and JIT friendliness.
     *
     * @return array{0: string, 1: array<int, string>} Returns [processedSql, paramMap]
     */
    public static function parse(string $sql): array
    {
        // Fast-path: if there are no colons or question marks, return immediately.
        if (! str_contains($sql, ':') && ! str_contains($sql, '?')) {
            return [$sql, []];
        }

        $length = \strlen($sql);
        $result = '';
        $paramMap = [];
        $paramIndex = 0;
        $state = 'NORMAL';
        $hasNamed = false;
        $hasPositional = false;

        for ($position = 0; $position < $length; $position++) {
            $currentChar = $sql[$position];
            $nextChar = $sql[$position + 1] ?? '';

            if ($state === 'NORMAL') {

                // Enter string literal states
                if ($currentChar === "'" || $currentChar === '"' || $currentChar === '`') {
                    $state = $currentChar;
                    $result .= $currentChar;

                    continue;
                }

                // Enter line comment state (--)
                if ($currentChar === '-' && $nextChar === '-') {
                    $state = '--';
                    $result .= $currentChar;

                    continue;
                }

                // Enter hash comment state (#)
                if ($currentChar === '#') {
                    $state = '#';
                    $result .= $currentChar;

                    continue;
                }

                // Enter block comment state (/*)
                if ($currentChar === '/' && $nextChar === '*') {
                    $state = '/*';
                    $result .= $currentChar;

                    continue;
                }

                // Positional placeholder
                if ($currentChar === '?') {
                    $hasPositional = true;
                    if ($hasNamed) {
                        throw new InvalidArgumentException('Cannot mix named and positional parameters in the same query.');
                    }
                    $result .= '?';
                    $paramIndex++;

                    continue;
                }

                // Consume PostgreSQL-style cast operator (::) as a single unit so
                // the second colon is never seen as a named parameter start.
                if ($currentChar === ':' && $nextChar === ':') {
                    $result .= '::';
                    $position++;

                    continue;
                }

                // Skip MySQL assignment operator (:=)
                if ($currentChar === ':' && $nextChar === '=') {
                    $result .= $currentChar;

                    continue;
                }

                // Named parameter — first character must be a letter or underscore
                if ($currentChar === ':') {
                    $nameStartPosition = $position + 1;
                    $paramName = '';

                    if ($nameStartPosition < $length) {
                        $firstCharCode = \ord($sql[$nameStartPosition]);
                        $isValidFirstChar = ($firstCharCode >= 97 && $firstCharCode <= 122)  // a-z
                                         || ($firstCharCode >= 65 && $firstCharCode <= 90)   // A-Z
                                         || $firstCharCode === 95;                            // _

                        if ($isValidFirstChar) {
                            $scanPosition = $nameStartPosition;

                            while ($scanPosition < $length) {
                                $nameChar = $sql[$scanPosition];
                                $nameCharCode = \ord($nameChar);

                                // Subsequent characters: a-z, A-Z, 0-9, _
                                if (
                                    ($nameCharCode >= 97 && $nameCharCode <= 122)
                                    || ($nameCharCode >= 65 && $nameCharCode <= 90)
                                    || ($nameCharCode >= 48 && $nameCharCode <= 57)
                                    || $nameCharCode === 95
                                ) {
                                    $paramName .= $nameChar;
                                    $scanPosition++;
                                } else {
                                    break;
                                }
                            }
                        }
                    }

                    if ($paramName !== '') {
                        $hasNamed = true;
                        if ($hasPositional) {
                            throw new InvalidArgumentException('Cannot mix named and positional parameters in the same query.');
                        }
                        $result .= '?';
                        $paramMap[$paramIndex++] = $paramName; // @phpstan-ignore-next-line no-undefined-variables
                        $position = $scanPosition - 1; 

                        continue;
                    }
                }

                $result .= $currentChar;

            } elseif ($state === "'" || $state === '"' || $state === '`') {
                $result .= $currentChar;
                if ($currentChar === '\\' && $nextChar !== '') {
                    // Consume the escaped character
                    $result .= $nextChar;
                    $position++;
                } elseif ($currentChar === $state) {
                    // Allow doubled-quote escapes (e.g. 'O''Reilly')
                    if ($state !== '`' && $nextChar === $state) {
                        $result .= $nextChar;
                        $position++;
                    } else {
                        $state = 'NORMAL';
                    }
                }
            } elseif ($state === '--' || $state === '#') {
                $result .= $currentChar;
                if ($currentChar === "\n") {
                    $state = 'NORMAL';
                }
            } elseif ($state === '/*') {
                $result .= $currentChar;
                if ($currentChar === '*' && $nextChar === '/') {
                    $result .= $nextChar;
                    $position++;
                    $state = 'NORMAL';
                }
            }
        }

        return [$result, $paramMap];
    }
}
