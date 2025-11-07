<?php

declare(strict_types=1);

namespace Hibla\MySQL\Utilities;

use InvalidArgumentException;

/**
 * Validates database configuration arrays.
 */
final class ConfigValidator
{
    /**
     * Validates the complete database configuration.
     *
     * @param  array<string, mixed>  $config  Database configuration to validate.
     * @return void
     *
     * @throws InvalidArgumentException If configuration is invalid.
     */
    public static function validate(array $config): void
    {
        self::validateNotEmpty($config);
        self::validateRequiredFields($config);
        self::validateFieldTypes($config);
    }

    /**
     * Validates that the configuration is not empty.
     *
     * @param  array<string, mixed>  $config  Database configuration.
     * @return void
     *
     * @throws InvalidArgumentException If configuration is empty.
     */
    private static function validateNotEmpty(array $config): void
    {
        if (count($config) === 0) {
            throw new InvalidArgumentException('Database configuration cannot be empty');
        }
    }

    /**
     * Validates that all required fields are present and non-empty.
     *
     * @param  array<string, mixed>  $config  Database configuration.
     * @return void
     *
     * @throws InvalidArgumentException If required fields are missing or empty.
     */
    private static function validateRequiredFields(array $config): void
    {
        $requiredFields = ['host', 'username', 'database'];

        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $config)) {
                throw new InvalidArgumentException(
                    sprintf("Missing required database configuration field: '%s'", $field)
                );
            }

            if (in_array($field, ['host', 'database'], true)) {
                if ($config[$field] === '' || $config[$field] === null) {
                    throw new InvalidArgumentException(
                        sprintf("Database configuration field '%s' cannot be empty", $field)
                    );
                }
            }
        }
    }

    /**
     * Validates that all configuration fields have the correct types.
     *
     * @param  array<string, mixed>  $config  Database configuration.
     * @return void
     *
     * @throws InvalidArgumentException If field types are invalid.
     */
    private static function validateFieldTypes(array $config): void
    {
        $stringFields = ['host', 'username', 'database', 'password', 'charset', 'socket'];

        foreach ($stringFields as $field) {
            if (isset($config[$field]) && ! is_string($config[$field])) {
                throw new InvalidArgumentException(
                    sprintf('Database %s must be a string', $field)
                );
            }
        }

        if (isset($config['port'])) {
            if (! is_int($config['port'])) {
                throw new InvalidArgumentException('Database port must be an integer');
            }
            if ($config['port'] <= 0) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Database port must be a positive integer, got %d',
                        $config['port']
                    )
                );
            }
        }

        if (isset($config['options']) && ! is_array($config['options'])) {
            throw new InvalidArgumentException('Database options must be an array');
        }
    }
}
