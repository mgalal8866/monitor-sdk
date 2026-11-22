<?php

use Trk\Monitor\Support\SqlSanitizer;

it('removes inline literals from sql', function () {
    expect(SqlSanitizer::sanitize("select * from users where email = 'a@b.c' and id = 42 and token = 0xDEADBEEF"))
        ->toBe('select * from users where email = ? and id = ? and token = ?');
});

it('keeps placeholders and identifiers intact', function () {
    expect(SqlSanitizer::sanitize('select "users".* from "users" where "id" = ? and "t2"."col1" = ?'))
        ->toBe('select "users".* from "users" where "id" = ? and "t2"."col1" = ?');
});

it('collapses in lists and whitespace', function () {
    expect(SqlSanitizer::sanitize("select *\n from orders where id in (?, ?, ?, ?)"))->toBe('select * from orders where id in (?)')
        ->and(SqlSanitizer::sanitize('select * from t where id in (1, 2, 3)'))->toBe('select * from t where id in (?)');
});

it('truncates long statements', function () {
    expect(mb_strlen(SqlSanitizer::sanitize('select '.str_repeat('a, ', 1000).'b from t', 100)))->toBe(101);
});

it('detects categories and tables', function (string $sql, string $category, array $tables) {
    expect(SqlSanitizer::category($sql))->toBe($category)->and(SqlSanitizer::tables($sql))->toBe($tables);
})->with([
    ['select * from "users" inner join `orders` on 1', 'select', ['users', 'orders']],
    ['insert into jobs (a) values (?)', 'insert', ['jobs']],
    ['update public.sessions set x = ?', 'update', ['sessions']],
    ['delete from cache where key = ?', 'delete', ['cache']],
    ['create table foo (id int)', 'ddl', ['foo']],
]);

it('removes values from database error messages', function () {
    expect(SqlSanitizer::sanitizeMessage("SQLSTATE[23000]: Duplicate entry 'jane@example.com' for key 'users_email_unique'"))
        ->toBe("SQLSTATE[23000]: Duplicate entry '?' for key '?'");
});
