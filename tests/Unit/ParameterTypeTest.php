<?php

declare(strict_types=1);

use Hibla\MySQL\Utilities\ParameterTypes;

describe('ParameterTypes', function () {
    describe('detect()', function () {
        it('detects null as string type', function () {
            $params = [null];
            $types = ParameterTypes::detect($params);

            expect($types)->toBe('s');
        });

        it('detects boolean as integer type', function () {
            $params = [true, false];
            $types = ParameterTypes::detect($params);

            expect($types)->toBe('ii');
        });

        it('detects integer as integer type', function () {
            $params = [42, -100, 0];
            $types = ParameterTypes::detect($params);

            expect($types)->toBe('iii');
        });

        it('detects float as double type', function () {
            $params = [3.14, -2.5, 0.0];
            $types = ParameterTypes::detect($params);

            expect($types)->toBe('ddd');
        });

        it('detects string as string type', function () {
            $params = ['hello', 'world', ''];
            $types = ParameterTypes::detect($params);

            expect($types)->toBe('sss');
        });

        it('detects binary string as blob type', function () {
            $params = ["binary\0data"];
            $types = ParameterTypes::detect($params);

            expect($types)->toBe('b');
        });

        it('detects resource as blob type', function () {
            $resource = fopen('php://memory', 'r');
            $params = [$resource];
            $types = ParameterTypes::detect($params);

            fclose($resource);

            expect($types)->toBe('b');
        });

        it('detects array as string type', function () {
            $params = [['key' => 'value'], [1, 2, 3]];
            $types = ParameterTypes::detect($params);

            expect($types)->toBe('ss');
        });

        it('detects object as string type', function () {
            $obj = new stdClass();
            $params = [$obj];
            $types = ParameterTypes::detect($params);

            expect($types)->toBe('s');
        });

        it('detects mixed parameter types correctly', function () {
            $params = [
                null,           // s
                true,           // i
                42,             // i
                3.14,           // d
                'hello',        // s
                ['array'],      // s
                new stdClass(), // s
            ];
            $types = ParameterTypes::detect($params);

            expect($types)->toBe('siidsss');
        });

        it('returns empty string for empty parameters', function () {
            $params = [];
            $types = ParameterTypes::detect($params);

            expect($types)->toBe('');
        });
    });

    describe('preprocess()', function () {
        it('preprocesses null as null', function () {
            $params = [null];
            $processed = ParameterTypes::preprocess($params);

            expect($processed)->toBe([null]);
        });

        it('preprocesses true as 1', function () {
            $params = [true];
            $processed = ParameterTypes::preprocess($params);

            expect($processed)->toBe([1]);
        });

        it('preprocesses false as 0', function () {
            $params = [false];
            $processed = ParameterTypes::preprocess($params);

            expect($processed)->toBe([0]);
        });

        it('preprocesses integer as integer', function () {
            $params = [42, -100, 0];
            $processed = ParameterTypes::preprocess($params);

            expect($processed)->toBe([42, -100, 0]);
        });

        it('preprocesses float as float', function () {
            $params = [3.14, -2.5, 0.0];
            $processed = ParameterTypes::preprocess($params);

            expect($processed)->toBe([3.14, -2.5, 0.0]);
        });

        it('preprocesses string as string', function () {
            $params = ['hello', 'world', ''];
            $processed = ParameterTypes::preprocess($params);

            expect($processed)->toBe(['hello', 'world', '']);
        });

        it('preprocesses resource as resource', function () {
            $resource = fopen('php://memory', 'r');
            $params = [$resource];
            $processed = ParameterTypes::preprocess($params);

            expect($processed[0])->toBe($resource);

            fclose($resource);
        });

        it('preprocesses array as JSON string', function () {
            $params = [['key' => 'value'], [1, 2, 3]];
            $processed = ParameterTypes::preprocess($params);

            expect($processed)->toBe([
                '{"key":"value"}',
                '[1,2,3]',
            ]);
        });

        it('preprocesses object with __toString as string', function () {
            $obj = new class () {
                public function __toString(): string
                {
                    return 'custom string';
                }
            };
            $params = [$obj];
            $processed = ParameterTypes::preprocess($params);

            expect($processed)->toBe(['custom string']);
        });

        it('preprocesses object without __toString as JSON', function () {
            $obj = new stdClass();
            $obj->name = 'test';
            $obj->value = 123;

            $params = [$obj];
            $processed = ParameterTypes::preprocess($params);

            expect($processed)->toBe(['{"name":"test","value":123}']);
        });

        it('preprocesses mixed parameters correctly', function () {
            $obj = new stdClass();
            $obj->test = 'value';

            $params = [
                null,
                true,
                false,
                42,
                3.14,
                'hello',
                ['key' => 'value'],
                $obj,
            ];

            $processed = ParameterTypes::preprocess($params);

            expect($processed)->toBe([
                null,
                1,
                0,
                42,
                3.14,
                'hello',
                '{"key":"value"}',
                '{"test":"value"}',
            ]);
        });

        it('returns empty array for empty parameters', function () {
            $params = [];
            $processed = ParameterTypes::preprocess($params);

            expect($processed)->toBe([]);
        });

        it('handles complex nested arrays', function () {
            $params = [
                [
                    'users' => [
                        ['id' => 1, 'name' => 'John'],
                        ['id' => 2, 'name' => 'Jane'],
                    ],
                    'count' => 2,
                ],
            ];

            $processed = ParameterTypes::preprocess($params);

            expect($processed[0])->toBe('{"users":[{"id":1,"name":"John"},{"id":2,"name":"Jane"}],"count":2}');
        });
    });

    describe('integration', function () {
        it('detects and preprocesses parameters consistently', function () {
            $params = [
                null,
                true,
                42,
                3.14,
                'test',
                ['array'],
            ];

            $types = ParameterTypes::detect($params);
            $processed = ParameterTypes::preprocess($params);

            expect($types)->toBe('siidss');

            expect($processed)->toBe([
                null,
                1,
                42,
                3.14,
                'test',
                '["array"]',
            ]);
        });

        it('handles edge case with binary string detection and processing', function () {
            $binaryData = "test\0binary\0data";
            $params = [$binaryData];

            $types = ParameterTypes::detect($params);
            $processed = ParameterTypes::preprocess($params);

            expect($types)->toBe('b');
            expect($processed[0])->toBe($binaryData);
        });
    });
});
