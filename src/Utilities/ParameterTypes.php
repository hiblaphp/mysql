<?php

declare(strict_types=1);

namespace Hibla\MySQL\Utilities;

/**
 * Handles parameter type detection and conversion for prepared statements.
 *
 * This class provides utilities for automatically detecting MySQL parameter types
 * from PHP values and preprocessing parameters for binding.
 */
final class ParameterTypes
{
    /**
     * Detects parameter types from array values.
     *
     * Automatically determines the appropriate type string for mysqli_stmt::bind_param
     * based on the PHP types of the parameter values.
     *
     * @param  array<int, mixed>  $params  Parameter values
     * @return string Type string (i=integer, d=double, s=string, b=blob)
     */
    public static function detect(array $params): string
    {
        $types = '';

        foreach ($params as $param) {
            $types .= match (true) {
                $param === null => 's',
                is_bool($param) => 'i',
                is_int($param) => 'i',
                is_float($param) => 'd',
                is_resource($param) => 'b',
                is_string($param) && str_contains($param, "\0") => 'b',
                is_string($param) => 's',
                is_array($param) => 's',
                is_object($param) => 's',
                default => 's',
            };
        }

        return $types;
    }

    /**
     * Preprocesses parameters for binding to prepared statement.
     *
     * Converts PHP values to appropriate types for MySQL binding,
     * including JSON encoding for arrays and objects.
     *
     * @param  array<int, mixed>  $params  Raw parameter values
     * @return array<int, mixed> Processed parameter values
     */
    public static function preprocess(array $params): array
    {
        $processedParams = [];

        foreach ($params as $param) {
            $processedParams[] = match (true) {
                $param === null => null,
                is_bool($param) => $param ? 1 : 0,
                is_int($param) || is_float($param) => $param,
                is_resource($param) => $param,
                is_string($param) => $param,
                is_array($param) => json_encode($param),
                is_object($param) && method_exists($param, '__toString') => (string) $param,
                is_object($param) => json_encode($param),
                default => $param,
            };
        }

        return $processedParams;
    }
}
