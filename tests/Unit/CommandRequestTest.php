<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\ValueObjects;

use Hibla\Mysql\ValueObjects\CommandRequest;
use Hibla\Promise\Promise;

describe('CommandRequest', function (): void {

    it('initializes correctly with all provided values', function (): void {
        $promise = new Promise();
        $params = [1, 'test', true];
        $context = new \stdClass();
        $context->foo = 'bar';

        $request = new CommandRequest(
            type: CommandRequest::TYPE_EXECUTE,
            promise: $promise,
            sql: 'SELECT * FROM users WHERE id = ?',
            params: $params,
            statementId: 42,
            context: $context
        );

        expect($request->type)->toBe('execute')
            ->and($request->promise)->toBe($promise)
            ->and($request->sql)->toBe('SELECT * FROM users WHERE id = ?')
            ->and($request->params)->toBe($params)
            ->and($request->statementId)->toBe(42)
            ->and($request->context)->toBe($context);
    });

    it('applies correct default values for optional parameters', function (): void {
        $promise = new Promise();
        $request = new CommandRequest(
            type: CommandRequest::TYPE_PING,
            promise: $promise
        );

        expect($request->sql)->toBe('')
            ->and($request->params)->toBe([])
            ->and($request->statementId)->toBe(0)
            ->and($request->context)->toBeNull();
    });

    it('has all required type constants defined correctly', function (): void {
        expect(CommandRequest::TYPE_QUERY)->toBe('query')
            ->and(CommandRequest::TYPE_EXECUTE)->toBe('execute')
            ->and(CommandRequest::TYPE_PING)->toBe('ping')
            ->and(CommandRequest::TYPE_PREPARE)->toBe('prepare')
            ->and(CommandRequest::TYPE_CLOSE_STMT)->toBe('close_stmt')
            ->and(CommandRequest::TYPE_STREAM_QUERY)->toBe('stream_query')
            ->and(CommandRequest::TYPE_EXECUTE_STREAM)->toBe('execute_stream')
            ->and(CommandRequest::TYPE_RESET)->toBe('reset');
    });

    it('stores context of any type', function (): void {
        $promise = new Promise();
        
        $req1 = new CommandRequest('type', $promise, context: ['a' => 1]);
        expect($req1->context)->toBe(['a' => 1]);

        $req2 = new CommandRequest('type', $promise, context: 'some_string');
        expect($req2->context)->toBe('some_string');
    });
});