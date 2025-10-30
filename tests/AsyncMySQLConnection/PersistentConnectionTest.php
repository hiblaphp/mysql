<?php

use Hibla\Promise\Promise;

describe('Connection ID Persistence', function () {
    it('reuses connection IDs within the same persistent client instance', function () {
        $client = createPersistentConnection();
        
        $connectionIds = [];
        for ($i = 0; $i < 5; $i++) {
            $id = $client->fetchValue("SELECT CONNECTION_ID()")->await();
            $connectionIds[] = $id;
        }
        
        $uniqueIds = array_unique($connectionIds);
        expect($uniqueIds)->toHaveCount(1)
            ->and((int) $connectionIds[0])->toBeInt()->toBeGreaterThan(0);
    });
    
    it('reuses connection IDs within the same regular client instance', function () {
        $client = createRegularConnection();
        
        $connectionIds = [];
        for ($i = 0; $i < 5; $i++) {
            $id = $client->fetchValue("SELECT CONNECTION_ID()")->await();
            $connectionIds[] = $id;
        }
        
        $uniqueIds = array_unique($connectionIds);
        expect($uniqueIds)->toHaveCount(1)
            ->and((int) $connectionIds[0])->toBeInt()->toBeGreaterThan(0);
    });
});

describe('Pool Statistics', function () {
    it('shows correct statistics for persistent connection pool', function () {
        $client = createPersistentConnection(10);
        
        $client->fetchValue("SELECT 1")->await();
        
        $stats = $client->getStats();
        
        expect($stats)
            ->toHaveKey('active_connections')
            ->toHaveKey('pooled_connections')
            ->toHaveKey('waiting_requests')
            ->toHaveKey('max_size')
            ->toHaveKey('config_validated')
            ->toHaveKey('persistent')
            ->and($stats['persistent'])->toBeTrue()
            ->and($stats['max_size'])->toBe(10)
            ->and($stats['config_validated'])->toBeTrue();
    });
    
    it('shows correct statistics for regular connection pool', function () {
        $client = createRegularConnection(10);
        
        $client->fetchValue("SELECT 1")->await();
        
        $stats = $client->getStats();
        
        expect($stats)
            ->toHaveKey('persistent')
            ->and($stats['persistent'])->toBeFalse()
            ->and($stats['max_size'])->toBe(10);
    });
});

describe('Thread ID Analysis', function () {
    it('returns valid thread IDs for persistent connections', function () {
        $client = createPersistentConnection();
        
        $threadId = $client->run(function ($mysqli) {
            return $mysqli->thread_id;
        })->await();
        
        expect($threadId)->toBeInt()->toBeGreaterThan(0);
    });
    
    it('returns valid thread IDs for regular connections', function () {
        $client = createRegularConnection();
        
        $threadId = $client->run(function ($mysqli) {
            return $mysqli->thread_id;
        })->await();
        
        expect($threadId)->toBeInt()->toBeGreaterThan(0);
    });
});

describe('Session Variable Persistence', function () {
    it('persists session variables within persistent client instance', function () {
        $client = createPersistentConnection();
        
        $client->execute("SET @test_var = 'persistent_value'")->await();
        $value1 = $client->fetchValue("SELECT @test_var")->await();
        $value2 = $client->fetchValue("SELECT @test_var")->await();
        
        expect($value1)->toBe('persistent_value')
            ->and($value2)->toBe('persistent_value');
    });
    
    it('persists session variables within regular client instance', function () {
        $client = createRegularConnection();
        
        $client->execute("SET @test_var = 'regular_value'")->await();
        $value1 = $client->fetchValue("SELECT @test_var")->await();
        $value2 = $client->fetchValue("SELECT @test_var")->await();
        
        expect($value1)->toBe('regular_value')
            ->and($value2)->toBe('regular_value');
    });
});

describe('Transaction Handling', function () {
    it('can start and rollback transactions on persistent connections', function () {
        $client = createPersistentConnection();
        
        $result = $client->transaction(function ($mysqli) {
            $result = $mysqli->query("SELECT 1 as test")->fetch_assoc();
            return $result['test'];
        })->await();
        
        expect($result)->toBe('1');
    });
    
    it('rolls back on exceptions', function () {
        $client = createPersistentConnection();
        
        try {
            $client->transaction(function ($mysqli) {
                $mysqli->query("CREATE TEMPORARY TABLE IF NOT EXISTS test_rollback (id INT)");
                $mysqli->query("INSERT INTO test_rollback VALUES (1)");
                
                throw new Exception("Intentional error");
            })->await();
        } catch (Exception $e) {
            expect($e->getMessage())->toContain('Intentional error');
        }
    });
});

describe('Parallel Execution Performance', function () {
    it('executes queries in parallel for persistent connections', function () {
        $client = createPersistentConnection();
        
        $startTime = microtime(true);
        Promise::all([
            $client->query("SELECT SLEEP(1)"),
            $client->query("SELECT SLEEP(1)"),
        ])->await();
        $executionTime = microtime(true) - $startTime;
        
        expect($executionTime)->toBeLessThan(1.5)
            ->toBeGreaterThan(0.9);
    });
    
    it('executes queries in parallel for regular connections', function () {
        $client = createRegularConnection();
        
        $startTime = microtime(true);
        Promise::all([
            $client->query("SELECT SLEEP(1)"),
            $client->query("SELECT SLEEP(1)"),
        ])->await();
        $executionTime = microtime(true) - $startTime;
        
        expect($executionTime)->toBeLessThan(1.5)
            ->toBeGreaterThan(0.9);
    });
});

describe('Connection Reuse with Small Pool', function () {
    it('reuses the same connection with pool size of 1', function () {
        $client = createPersistentConnection(1);
        
        $threadIds = [];
        for ($i = 0; $i < 5; $i++) {
            $threadId = $client->run(function ($mysqli) {
                return $mysqli->thread_id;
            })->await();
            $threadIds[] = $threadId;
        }
        
        $uniqueThreadIds = array_unique($threadIds);
        
        expect($uniqueThreadIds)->toHaveCount(1)
            ->and($threadIds)->toHaveCount(5);
    });
});

describe('TRUE Persistent Connection Test', function () {
    it('reuses connections across client instance recreation for persistent connections', function () {
        $client1 = createPersistentConnection(1);
        
        $threadId1 = $client1->run(function ($mysqli) {
            return $mysqli->thread_id;
        })->await();
        
        $client1->execute("SET @persistent_test = 'client1'")->await();
        $testVar1 = $client1->fetchValue("SELECT @persistent_test")->await();
        
        expect($testVar1)->toBe('client1')
            ->and($threadId1)->toBeInt()->toBeGreaterThan(0);
        
        $client1->reset();
        unset($client1);
        
        $client2 = createPersistentConnection(1);
        
        $threadId2 = $client2->run(function ($mysqli) {
            return $mysqli->thread_id;
        })->await();
        
        $testVar2 = $client2->fetchValue("SELECT @persistent_test")->await();
        
        expect($threadId2)->toBe($threadId1)
            ->and($testVar2)->toBeNull();
    });
    
    it('does NOT reuse connections across client instance recreation for regular connections', function () {
        $client1 = createRegularConnection(1);
        
        $threadId1 = $client1->run(function ($mysqli) {
            return $mysqli->thread_id;
        })->await();
        
        expect($threadId1)->toBeInt()->toBeGreaterThan(0);
        
        $client1->reset();
        unset($client1);
        
        $client2 = createRegularConnection(1);
        
        $threadId2 = $client2->run(function ($mysqli) {
            return $mysqli->thread_id;
        })->await();
        
        expect($threadId2)->toBeInt()->toBeGreaterThan(0)
            ->not->toBe($threadId1);
    });
});

describe('Server-Side Connection Analysis', function () {
    it('can query the MySQL process list', function () {
        $client = createPersistentConnection();
        
        $result = $client->query("SHOW PROCESSLIST")->await();
        
        expect($result)->toBeArray()
            ->not->toBeEmpty()
            ->and($result[0])->toHaveKeys(['Id', 'User', 'db', 'Command']);
    });
});

describe('Connection Properties', function () {
    it('provides connection metadata for persistent connections', function () {
        $client = createPersistentConnection();
        
        $metadata = $client->run(function ($mysqli) {
            return [
                'thread_id' => $mysqli->thread_id,
                'host_info' => $mysqli->host_info,
                'protocol_version' => $mysqli->protocol_version,
                'server_info' => $mysqli->server_info,
            ];
        })->await();
        
        expect($metadata['thread_id'])->toBeInt()->toBeGreaterThan(0)
            ->and($metadata['host_info'])->toBeString()
            ->and($metadata['protocol_version'])->toBeInt()
            ->and($metadata['server_info'])->toBeString();
    });
});

describe('Edge Cases', function () {
    it('handles multiple parallel operations correctly', function () {
        $client = createPersistentConnection(5);
        
        $promises = [];
        for ($i = 0; $i < 10; $i++) {
            $promises[] = $client->fetchValue("SELECT {$i}");
        }
        
        $results = Promise::all($promises)->await();
        
        expect($results)->toHaveCount(10);
        
        $intResults = array_map('intval', $results);
        
        expect($intResults[0])->toBe(0)
            ->and($intResults[9])->toBe(9);
    });
    
    it('handles connection pool exhaustion gracefully', function () {
        $client = createPersistentConnection(2);
        
        $promises = [];
        for ($i = 0; $i < 5; $i++) {
            $promises[] = $client->query("SELECT SLEEP(0.1)");
        }
        
        $startTime = microtime(true);
        Promise::all($promises)->await();
        $duration = microtime(true) - $startTime;
        
        expect($duration)->toBeGreaterThan(0.2);
    });
});