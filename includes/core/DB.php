<?php
declare(strict_types=1);

/**
 * Thin PDO wrapper. Every query goes through prepared statements.
 * Table/column names passed to insert()/update() come from application code only and are validated.
 */
final class DB
{
    private static ?PDO $pdo = null;

    public static function connect(array $cfg, bool $withDatabase = true): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $cfg['host'] ?? '127.0.0.1',
            (int) ($cfg['port'] ?? 3306),
            $cfg['charset'] ?? 'utf8mb4'
        );
        if ($withDatabase) {
            $dsn .= ';dbname=' . ($cfg['name'] ?? '');
        }
        $pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        // Keep MySQL NOW() aligned with the PHP timezone
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect((array) Config::get('db', []));
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insert(string $table, array $data): int
    {
        self::assertIdentifier($table);
        $columns = array_keys($data);
        array_walk($columns, [self::class, 'assertIdentifier']);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** @param string $where SQL condition using positional placeholders, e.g. "id = ?" */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        self::assertIdentifier($table);
        $sets = [];
        foreach (array_keys($data) as $column) {
            self::assertIdentifier($column);
            $sets[] = "`$column` = ?";
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return self::run($sql, [...array_values($data), ...$whereParams])->rowCount();
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Builds "?, ?, ?" for IN() clauses. */
    public static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }

    private static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }
    }
}
