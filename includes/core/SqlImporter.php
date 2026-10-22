<?php
declare(strict_types=1);

/**
 * Executes a .sql file statement by statement (used by the installer).
 * Handles quoted strings, comments and semicolons inside text values.
 */
final class SqlImporter
{
    public static function runFile(PDO $pdo, string $file): int
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Cannot read ' . basename($file));
        }
        $count = 0;
        foreach (self::split($sql) as $statement) {
            // Database selection is handled by the installer's connection
            if (preg_match('/^(CREATE\s+DATABASE|USE\s)/i', $statement)) {
                continue;
            }
            $pdo->exec($statement);
            $count++;
        }
        return $count;
    }

    /** @return string[] */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== '`') {
                    $buffer .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    if ($next === $quote) { // doubled quote = escaped quote
                        $buffer .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($char === '-' && $next === '-' && in_array($sql[$i + 2] ?? ' ', [' ', "\t", "\n", "\r"], true)) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }
            if ($char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return $statements;
    }
}
