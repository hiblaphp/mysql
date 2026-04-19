<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\Internals;

use Hibla\Mysql\Internals\NameParamParser;

describe('NameParamParser', function (): void {
    it('leaves plain queries untouched', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM users');
        expect($sql)->toBe('SELECT * FROM users')
            ->and($map)->toBe([])
        ;
    });

    it('returns empty map for positional-only queries', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM users WHERE id = ?');
        expect($sql)->toBe('SELECT * FROM users WHERE id = ?')
            ->and($map)->toBe([])
        ;
    });

    it('handles an empty string', function (): void {
        [$sql, $map] = NameParamParser::parse('');
        expect($sql)->toBe('')
            ->and($map)->toBe([])
        ;
    });

    it('parses a single named parameter', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM users WHERE id = :id');
        expect($sql)->toBe('SELECT * FROM users WHERE id = ?')
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('parses basic named parameters', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM users WHERE id = :id AND status = :status');
        expect($sql)->toBe('SELECT * FROM users WHERE id = ? AND status = ?')
            ->and($map)->toBe([0 => 'id', 1 => 'status'])
        ;
    });

    it('parses named parameters with underscores', function (): void {
        [$sql, $map] = NameParamParser::parse('INSERT INTO t VALUES (:first_name, :last_name)');
        expect($sql)->toBe('INSERT INTO t VALUES (?, ?)')
            ->and($map)->toBe([0 => 'first_name', 1 => 'last_name'])
        ;
    });

    it('parses named parameters with digits', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE col1 = :val1 AND col2 = :val2');
        expect($sql)->toBe('SELECT * FROM t WHERE col1 = ? AND col2 = ?')
            ->and($map)->toBe([0 => 'val1', 1 => 'val2'])
        ;
    });

    it('parses named parameter immediately followed by a comma', function (): void {
        [$sql, $map] = NameParamParser::parse('INSERT INTO t (a, b) VALUES (:a, :b)');
        expect($sql)->toBe('INSERT INTO t (a, b) VALUES (?, ?)')
            ->and($map)->toBe([0 => 'a', 1 => 'b'])
        ;
    });

    it('parses named parameter at the very end of the string', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :id');
        expect($sql)->toBe('SELECT * FROM t WHERE id = ?')
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('allows multiple identical named parameters', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM users WHERE (first = :name OR last = :name)');
        expect($sql)->toBe('SELECT * FROM users WHERE (first = ? OR last = ?)')
            ->and($map)->toBe([0 => 'name', 1 => 'name'])
        ;
    });

    it('ignores colons inside single quotes', function (): void {
        [$sql, $map] = NameParamParser::parse("SELECT * FROM logs WHERE message = 'Error: :not_param' AND id = :id");
        expect($sql)->toBe("SELECT * FROM logs WHERE message = 'Error: :not_param' AND id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('ignores colons inside double quotes', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM logs WHERE message = "Error: :not_param" AND id = :id');
        expect($sql)->toBe('SELECT * FROM logs WHERE message = "Error: :not_param" AND id = ?')
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('ignores colons inside backticks', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT `column:name` FROM users WHERE id = :id');
        expect($sql)->toBe('SELECT `column:name` FROM users WHERE id = ?')
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles escaped quotes inside strings', function (): void {
        [$sql, $map] = NameParamParser::parse("SELECT * FROM logs WHERE msg = 'It\\'s a :trap' AND id = :id");
        expect($sql)->toBe("SELECT * FROM logs WHERE msg = 'It\\'s a :trap' AND id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles doubled quote escapes in strings', function (): void {
        [$sql, $map] = NameParamParser::parse("SELECT * FROM logs WHERE msg = 'O''Reilly :trap' AND id = :id");
        expect($sql)->toBe("SELECT * FROM logs WHERE msg = 'O''Reilly :trap' AND id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles adjacent string literals', function (): void {
        [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE a = ':trap' AND b = ':trap' AND id = :id");
        expect($sql)->toBe("SELECT * FROM t WHERE a = ':trap' AND b = ':trap' AND id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('ignores colons inside line comments (--)', function (): void {
        $query = "SELECT * FROM users -- comment with :param\n WHERE id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe("SELECT * FROM users -- comment with :param\n WHERE id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('ignores colons inside hash comments (#)', function (): void {
        $query = "SELECT * FROM users # comment :param\n WHERE id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe("SELECT * FROM users # comment :param\n WHERE id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('ignores colons inside block comments', function (): void {
        $query = "SELECT * FROM users /* comment with :param \n multiline :param2 */ WHERE id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe("SELECT * FROM users /* comment with :param \n multiline :param2 */ WHERE id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('resumes parsing correctly after a block comment', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT /* skip :x */ :a, :b FROM t');
        expect($sql)->toBe('SELECT /* skip :x */ ?, ? FROM t')
            ->and($map)->toBe([0 => 'a', 1 => 'b'])
        ;
    });

    it('resumes parsing correctly after a line comment', function (): void {
        [$sql, $map] = NameParamParser::parse("SELECT -- skip :x\n :a FROM t");
        expect($sql)->toBe("SELECT -- skip :x\n ? FROM t")
            ->and($map)->toBe([0 => 'a'])
        ;
    });

    it('treats a line comment at end of string with no newline as consuming remaining input', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT 1 -- :trap');
        expect($sql)->toBe('SELECT 1 -- :trap')
            ->and($map)->toBe([])
        ;
    });

    it('ignores assignment operator :=', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT @row := @row + 1, :param');
        expect($sql)->toBe('SELECT @row := @row + 1, ?')
            ->and($map)->toBe([0 => 'param'])
        ;
    });

    it('ignores PostgreSQL-style cast operator ::', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT col::text FROM t WHERE id = :id');
        expect($sql)->toBe('SELECT col::text FROM t WHERE id = ?')
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles multiple consecutive PostgreSQL casts', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT col::text::varchar FROM t WHERE id = :id');
        expect($sql)->toBe('SELECT col::text::varchar FROM t WHERE id = ?')
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles a PostgreSQL cast on a string literal', function (): void {
        [$sql, $map] = NameParamParser::parse("SELECT 'x'::text, :param");
        expect($sql)->toBe("SELECT 'x'::text, ?")
            ->and($map)->toBe([0 => 'param'])
        ;
    });

    it('handles a lone colon with no valid identifier following it', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT : FROM t WHERE id = :id');
        expect($sql)->toBe('SELECT : FROM t WHERE id = ?')
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles a colon at the very end of the string', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE x =:');
        expect($sql)->toBe('SELECT * FROM t WHERE x =:')
            ->and($map)->toBe([])
        ;
    });

    it('throws when mixing named after positional', function (): void {
        expect(fn () => NameParamParser::parse('SELECT * FROM users WHERE id = ? AND name = :name'))
            ->toThrow(\InvalidArgumentException::class, 'Cannot mix named and positional')
        ;
    });

    it('throws when mixing positional after named', function (): void {
        expect(fn () => NameParamParser::parse('SELECT * FROM users WHERE name = :name AND id = ?'))
            ->toThrow(\InvalidArgumentException::class, 'Cannot mix named and positional')
        ;
    });

    // -------------------------------------------------------------------------
    // Identifier boundary edge cases
    // -------------------------------------------------------------------------

    it('parses a named parameter leading with an underscore', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :_private_id');
        expect($sql)->toBe('SELECT * FROM t WHERE id = ?')
            ->and($map)->toBe([0 => '_private_id'])
        ;
    });

    it('parses a named parameter immediately followed by a closing parenthesis', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE (id = :id)');
        expect($sql)->toBe('SELECT * FROM t WHERE (id = ?)')
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('parses a named parameter immediately followed by a newline', function (): void {
        [$sql, $map] = NameParamParser::parse("SELECT * FROM t\nWHERE id = :id\nAND active = :active");
        expect($sql)->toBe("SELECT * FROM t\nWHERE id = ?\nAND active = ?")
            ->and($map)->toBe([0 => 'id', 1 => 'active'])
        ;
    });

    it('parses two named parameters with no space between them', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT :a:b');
        expect($sql)->toBe('SELECT ??')
            ->and($map)->toBe([0 => 'a', 1 => 'b'])
        ;
    });

    it('does not parse a colon followed by a space as a named parameter', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT : param FROM t WHERE id = :id');
        expect($sql)->toBe('SELECT : param FROM t WHERE id = ?')
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('does not parse a colon followed by a number as a named parameter', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :id LIMIT :1');
        expect($sql)->toBe('SELECT * FROM t WHERE id = ? LIMIT :1')
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles :: at the very end of the string', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT col::');
        expect($sql)->toBe('SELECT col::')
            ->and($map)->toBe([])
        ;
    });

    it('handles a named parameter cast to a type that itself is cast again', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT :val::text::varchar(255) FROM t');
        expect($sql)->toBe('SELECT ?::text::varchar(255) FROM t')
            ->and($map)->toBe([0 => 'val'])
        ;
    });

    it('handles a LIMIT / OFFSET pattern', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE status = :status LIMIT :limit OFFSET :offset');
        expect($sql)->toBe('SELECT * FROM t WHERE status = ? LIMIT ? OFFSET ?')
            ->and($map)->toBe([0 => 'status', 1 => 'limit', 2 => 'offset'])
        ;
    });

    it('handles an UPDATE statement', function (): void {
        [$sql, $map] = NameParamParser::parse('UPDATE users SET name = :name, email = :email WHERE id = :id');
        expect($sql)->toBe('UPDATE users SET name = ?, email = ? WHERE id = ?')
            ->and($map)->toBe([0 => 'name', 1 => 'email', 2 => 'id'])
        ;
    });

    it('handles an INSERT with many columns', function (): void {
        [$sql, $map] = NameParamParser::parse(
            'INSERT INTO users (name, email, age, role) VALUES (:name, :email, :age, :role)'
        );
        expect($sql)->toBe('INSERT INTO users (name, email, age, role) VALUES (?, ?, ?, ?)')
            ->and($map)->toBe([0 => 'name', 1 => 'email', 2 => 'age', 3 => 'role'])
        ;
    });

    it('handles a DELETE statement', function (): void {
        [$sql, $map] = NameParamParser::parse('DELETE FROM t WHERE id = :id AND tenant = :tenant');
        expect($sql)->toBe('DELETE FROM t WHERE id = ? AND tenant = ?')
            ->and($map)->toBe([0 => 'id', 1 => 'tenant'])
        ;
    });

    it('handles a CASE expression with named parameters', function (): void {
        $query = 'SELECT CASE WHEN status = :active THEN 1 WHEN status = :inactive THEN 0 END FROM t';
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe('SELECT CASE WHEN status = ? THEN 1 WHEN status = ? THEN 0 END FROM t')
            ->and($map)->toBe([0 => 'active', 1 => 'inactive'])
        ;
    });

    it('handles multiple positional placeholders', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE a = ? AND b = ? AND c = ?');
        expect($sql)->toBe('SELECT * FROM t WHERE a = ? AND b = ? AND c = ?')
            ->and($map)->toBe([])
        ;
    });

    it('handles IN clause with named parameters', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id IN (:id1, :id2, :id3)');
        expect($sql)->toBe('SELECT * FROM t WHERE id IN (?, ?, ?)')
            ->and($map)->toBe([0 => 'id1', 1 => 'id2', 2 => 'id3'])
        ;
    });

    it('handles a subquery containing named parameters', function (): void {
        $query = 'SELECT * FROM t WHERE id IN (SELECT id FROM u WHERE role = :role) AND active = :active';
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe('SELECT * FROM t WHERE id IN (SELECT id FROM u WHERE role = ?) AND active = ?')
            ->and($map)->toBe([0 => 'role', 1 => 'active'])
        ;
    });

    it('handles named parameters inside a string-heavy query without false positives', function (): void {
        $query = "SELECT * FROM t WHERE label = 'hello:world' AND code = 'foo::bar' AND id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe("SELECT * FROM t WHERE label = 'hello:world' AND code = 'foo::bar' AND id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    // -------------------------------------------------------------------------
    // Security — SQL injection attempts in parameter names
    // -------------------------------------------------------------------------

    it('does not treat SQL keywords after a colon as named parameters', function (): void {
        // :SELECT, :DROP etc. are valid identifier names — they should be replaced
        // safely as positional placeholders, not interpreted as SQL
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :SELECT');
        expect($sql)->toBe('SELECT * FROM t WHERE id = ?')
            ->and($map)->toBe([0 => 'SELECT'])
        ;
    });

    it('does not allow semicolons to break out of a parameter name', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :id; DROP TABLE t--');
        expect($sql)->toBe('SELECT * FROM t WHERE id = ?; DROP TABLE t--')
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('does not allow a parameter name to contain a dash', function (): void {
        // :user-id should parse :user as the param and leave -id as literal SQL
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :user-id');
        expect($sql)->toBe('SELECT * FROM t WHERE id = ?-id')
            ->and($map)->toBe([0 => 'user'])
        ;
    });

    it('does not allow a parameter name to contain a dot', function (): void {
        // :table.column is not a valid named param — stops at the dot
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :table.column');
        expect($sql)->toBe('SELECT * FROM t WHERE id = ?.column')
            ->and($map)->toBe([0 => 'table'])
        ;
    });

    it('does not allow a parameter name to contain a space', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :user id');
        expect($sql)->toBe('SELECT * FROM t WHERE id = ? id')
            ->and($map)->toBe([0 => 'user'])
        ;
    });

    it('does not allow a parameter name to contain a quote', function (): void {
        [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE id = :user'injection");
        expect($sql)->toBe("SELECT * FROM t WHERE id = ?'injection")
            ->and($map)->toBe([0 => 'user'])
        ;
    });

    it('does not allow a parameter name to contain a slash', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :user/name');
        expect($sql)->toBe('SELECT * FROM t WHERE id = ?/name')
            ->and($map)->toBe([0 => 'user'])
        ;
    });

    it('does not allow a parameter name to contain a null byte', function (): void {
        [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE id = :user\x00injection");
        expect($sql)->toBe("SELECT * FROM t WHERE id = ?\x00injection")
            ->and($map)->toBe([0 => 'user'])
        ;
    });

    it('treats an injection attempt inside a string literal as inert', function (): void {
        $query = "SELECT * FROM t WHERE msg = 'x'' OR ''1''=''1' AND id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe("SELECT * FROM t WHERE msg = 'x'' OR ''1''=''1' AND id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('treats a comment-based injection inside a string literal as inert', function (): void {
        $query = "SELECT * FROM t WHERE msg = 'hello -- :trap' AND id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe("SELECT * FROM t WHERE msg = 'hello -- :trap' AND id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('treats a block comment injection inside a string literal as inert', function (): void {
        $query = "SELECT * FROM t WHERE msg = '/* :trap */' AND id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe("SELECT * FROM t WHERE msg = '/* :trap */' AND id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles deeply nested subqueries with named parameters', function (): void {
        $query = 'SELECT * FROM t WHERE id IN (SELECT id FROM u WHERE id IN (SELECT id FROM v WHERE role = :role)) AND active = :active';
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe('SELECT * FROM t WHERE id IN (SELECT id FROM u WHERE id IN (SELECT id FROM v WHERE role = ?)) AND active = ?')
            ->and($map)->toBe([0 => 'role', 1 => 'active'])
        ;
    });

    it('handles a UNION with named parameters on both sides', function (): void {
        $query = 'SELECT id FROM t WHERE role = :role UNION SELECT id FROM u WHERE role = :role';
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe('SELECT id FROM t WHERE role = ? UNION SELECT id FROM u WHERE role = ?')
            ->and($map)->toBe([0 => 'role', 1 => 'role'])
        ;
    });

    it('handles a WITH (CTE) containing named parameters', function (): void {
        $query = 'WITH active AS (SELECT * FROM t WHERE status = :status) SELECT * FROM active WHERE id = :id';
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe('WITH active AS (SELECT * FROM t WHERE status = ?) SELECT * FROM active WHERE id = ?')
            ->and($map)->toBe([0 => 'status', 1 => 'id'])
        ;
    });

    it('handles a query with a very long string literal without false positives', function (): void {
        $longLiteral = str_repeat(':trap ', 1000);
        $query = "SELECT * FROM t WHERE msg = '{$longLiteral}' AND id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe("SELECT * FROM t WHERE msg = '{$longLiteral}' AND id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles a query with a very long block comment without false positives', function (): void {
        $longComment = str_repeat(':trap ', 1000);
        $query = "SELECT * FROM t /* {$longComment} */ WHERE id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe("SELECT * FROM t /* {$longComment} */ WHERE id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles a very long named parameter name', function (): void {
        $longName = str_repeat('a', 500);
        [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE id = :{$longName}");
        expect($sql)->toBe('SELECT * FROM t WHERE id = ?')
            ->and($map)->toBe([0 => $longName])
        ;
    });

    it('handles a large number of named parameters', function (): void {
        $parts = array_map(fn (int $n) => "col{$n} = :param{$n}", range(1, 200));
        $query = 'SELECT * FROM t WHERE ' . implode(' AND ', $parts);
        [$sql, $map] = NameParamParser::parse($query);
        expect(substr_count($sql, '?'))->toBe(200)
            ->and(count($map))->toBe(200)
            ->and($map[0])->toBe('param1')
            ->and($map[199])->toBe('param200')
        ;
    });

    it('returns the same result when called twice on the same input (no side effects)', function (): void {
        $query = 'SELECT * FROM t WHERE id = :id AND status = :status';
        [$sql1, $map1] = NameParamParser::parse($query);
        [$sql2, $map2] = NameParamParser::parse($query);
        expect($sql1)->toBe($sql2)
            ->and($map1)->toBe($map2)
        ;
    });

    it('handles an unterminated single-quoted string gracefully', function (): void {
        [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE msg = 'unterminated :trap");
        expect($sql)->toBe("SELECT * FROM t WHERE msg = 'unterminated :trap")
            ->and($map)->toBe([])
        ;
    });

    it('handles an unterminated double-quoted string gracefully', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE msg = "unterminated :trap');
        expect($sql)->toBe('SELECT * FROM t WHERE msg = "unterminated :trap')
            ->and($map)->toBe([])
        ;
    });

    it('handles an unterminated backtick identifier gracefully', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT `unterminated:trap FROM t');
        expect($sql)->toBe('SELECT `unterminated:trap FROM t')
            ->and($map)->toBe([])
        ;
    });

    it('handles an unterminated block comment gracefully', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t /' . '* unterminated :trap');
        expect($sql)->toBe('SELECT * FROM t /' . '* unterminated :trap')
            ->and($map)->toBe([])
        ;
    });

    it('handles a query that is only an unterminated string', function (): void {
        [$sql, $map] = NameParamParser::parse("'");
        expect($sql)->toBe("'")
            ->and($map)->toBe([])
        ;
    });

    it('handles a query that is only an unterminated block comment', function (): void {
        [$sql, $map] = NameParamParser::parse('/*');
        expect($sql)->toBe('/*')
            ->and($map)->toBe([])
        ;
    });

    it('exits a line comment on Windows-style CRLF line endings', function (): void {
        $query = "SELECT * FROM t -- :trap\r\nWHERE id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe("SELECT * FROM t -- :trap\r\nWHERE id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('exits a hash comment on Windows-style CRLF line endings', function (): void {
        $query = "SELECT * FROM t # :trap\r\nWHERE id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe("SELECT * FROM t # :trap\r\nWHERE id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles a line comment terminated only by CR without LF', function (): void {
        $query = "SELECT * FROM t -- :trap\rWHERE id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($map)->toBe([]);
    });

    it('handles multibyte characters in a string literal without false positives', function (): void {
        $query = "SELECT * FROM t WHERE name = 'こんにちは :trap' AND id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe("SELECT * FROM t WHERE name = 'こんにちは :trap' AND id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles a named parameter immediately after a multibyte character', function (): void {
        $query = 'SELECT * FROM t WHERE id = :id AND emoji = :emoji';
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe('SELECT * FROM t WHERE id = ? AND emoji = ?')
            ->and($map)->toBe([0 => 'id', 1 => 'emoji'])
        ;
    });

    it('does not treat a high-byte character immediately after a colon as a valid identifier start', function (): void {
        $query = "SELECT * FROM t WHERE id = :\xc3\xa9dition AND name = :name";
        [$sql, $map] = NameParamParser::parse($query);

        expect($map)->toBe([0 => 'name']);
    });

    it('handles a query that is only a named parameter', function (): void {
        [$sql, $map] = NameParamParser::parse(':id');
        expect($sql)->toBe('?')
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles a query that is only a positional placeholder', function (): void {
        [$sql, $map] = NameParamParser::parse('?');
        expect($sql)->toBe('?')
            ->and($map)->toBe([])
        ;
    });

    it('handles a query that is only a bare colon', function (): void {
        [$sql, $map] = NameParamParser::parse(':');
        expect($sql)->toBe(':')
            ->and($map)->toBe([])
        ;
    });

    it('handles a parameter name of a single character', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :i');
        expect($sql)->toBe('SELECT * FROM t WHERE id = ?')
            ->and($map)->toBe([0 => 'i'])
        ;
    });

    it('handles a parameter name that is only underscores', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :___');
        expect($sql)->toBe('SELECT * FROM t WHERE id = ?')
            ->and($map)->toBe([0 => '___'])
        ;
    });

    it('handles an escaped backslash at the end of a string literal', function (): void {
        [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE path = '\\\\' AND id = :id");
        expect($sql)->toBe("SELECT * FROM t WHERE path = '\\\\' AND id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles an empty string literal', function (): void {
        [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE name = '' AND id = :id");
        expect($sql)->toBe("SELECT * FROM t WHERE name = '' AND id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles an empty block comment', function (): void {
        [$sql, $map] = NameParamParser::parse('SELECT ' . '/**/' . ' :id FROM t');
        expect($sql)->toBe('SELECT ' . '/**/' . ' ? FROM t')
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles a named parameter immediately after a -- opener with no space', function (): void {
        $query = "SELECT * FROM t --:trap\nWHERE id = :id";
        [$sql, $map] = NameParamParser::parse($query);
        expect($sql)->toBe("SELECT * FROM t --:trap\nWHERE id = ?")
            ->and($map)->toBe([0 => 'id'])
        ;
    });

    it('handles multiple assignment operators alongside named parameters', function (): void {
        [$sql, $map] = NameParamParser::parse('SET @a := :val1, @b := :val2');
        expect($sql)->toBe('SET @a := ?, @b := ?')
            ->and($map)->toBe([0 => 'val1', 1 => 'val2'])
        ;
    });

    it('handles a whitespace-only query', function (): void {
        [$sql, $map] = NameParamParser::parse('   ');
        expect($sql)->toBe('   ')
            ->and($map)->toBe([]);
    });

    it('handles tab characters between tokens', function (): void {
        [$sql, $map] = NameParamParser::parse("SELECT\t*\tFROM\tt\tWHERE\tid\t=\t:id");
        expect($sql)->toBe("SELECT\t*\tFROM\tt\tWHERE\tid\t=\t?")
            ->and($map)->toBe([0 => 'id']);
    });
});
