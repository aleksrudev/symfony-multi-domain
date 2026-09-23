<?php

declare(strict_types=1);

/** @return Closure(string, bool, array<string, mixed>, string=): string */
return static function (string $environment, bool $confirmed, array $connection, string $token = ''): string {
    $database = $connection['dbname'] ?? null;
    if ('test' !== $environment || !$confirmed || !preg_match('/^[a-zA-Z0-9]*$/D', $token)) {
        throw new InvalidArgumentException('Database preparation requires test environment and --confirm-test-database.');
    }
    if ('pdo_pgsql' !== ($connection['driver'] ?? null) || !is_string($database) || !preg_match('/^[a-zA-Z0-9_]+_test'.preg_quote($token, '/').'$/D', $database)) {
        throw new InvalidArgumentException('Refusing to prepare a database without the dedicated _test suffix.');
    }

    return $database;
};
