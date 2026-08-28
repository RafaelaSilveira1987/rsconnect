<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class SqlScriptParser
{
    /**
     * @return list<string>
     */
    public static function parse(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
        $delimiter = ';';
        $buffer = '';
        $statements = [];

        $lines = preg_split('/\R/', $sql) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $matches) === 1) {
                if (trim(self::stripComments($buffer)) !== '') {
                    throw new RuntimeException('Diretiva DELIMITER encontrada no meio de uma instrução SQL.');
                }
                $buffer = '';
                $delimiter = $matches[1];
                continue;
            }

            $buffer .= $line . "\n";
            [$complete, $buffer] = self::extract($buffer, $delimiter);
            foreach ($complete as $statement) {
                $statement = trim($statement);
                if ($statement !== '' && trim(self::stripComments($statement)) !== '') {
                    $statements[] = $statement;
                }
            }
        }

        if (trim(self::stripComments($buffer)) !== '') {
            throw new RuntimeException('Arquivo SQL terminou com uma instrução incompleta.');
        }

        return $statements;
    }

    /**
     * @return array{0:list<string>,1:string}
     */
    private static function extract(string $buffer, string $delimiter): array
    {
        $statements = [];
        $start = 0;
        $length = strlen($buffer);
        $delimiterLength = strlen($delimiter);
        $single = false;
        $double = false;
        $backtick = false;
        $lineComment = false;
        $blockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $buffer[$index];
            $next = $index + 1 < $length ? $buffer[$index + 1] : '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                }
                continue;
            }

            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $index++;
                }
                continue;
            }

            if (!$single && !$double && !$backtick) {
                if ($char === '#') {
                    $lineComment = true;
                    continue;
                }
                if ($char === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($buffer[$index + 2]))) {
                    $lineComment = true;
                    $index++;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $blockComment = true;
                    $index++;
                    continue;
                }
            }

            if (!$double && !$backtick && $char === "'") {
                if ($single && $next === "'") {
                    $index++;
                    continue;
                }
                if (!self::isEscaped($buffer, $index)) {
                    $single = !$single;
                }
                continue;
            }

            if (!$single && !$backtick && $char === '"') {
                if ($double && $next === '"') {
                    $index++;
                    continue;
                }
                if (!self::isEscaped($buffer, $index)) {
                    $double = !$double;
                }
                continue;
            }

            if (!$single && !$double && $char === '`') {
                if ($backtick && $next === '`') {
                    $index++;
                    continue;
                }
                $backtick = !$backtick;
                continue;
            }

            if (!$single && !$double && !$backtick && substr($buffer, $index, $delimiterLength) === $delimiter) {
                $statements[] = substr($buffer, $start, $index - $start);
                $index += $delimiterLength - 1;
                $start = $index + 1;
            }
        }

        return [$statements, substr($buffer, $start)];
    }

    private static function isEscaped(string $buffer, int $index): bool
    {
        $slashes = 0;
        for ($cursor = $index - 1; $cursor >= 0 && $buffer[$cursor] === '\\'; $cursor--) {
            $slashes++;
        }
        return $slashes % 2 === 1;
    }

    private static function stripComments(string $sql): string
    {
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*(?:--(?=\s)|#).*$/m', '', $sql) ?? $sql;
        return $sql;
    }
}
