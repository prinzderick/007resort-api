<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/** Runs a raw .sql file statement by statement (used by the verified V0001 schema migration). */
final class SqlFile
{
    public static function run(string $path): void
    {
        foreach (self::statements((string) file_get_contents($path)) as $sql) {
            DB::unprepared($sql);
        }
    }

    /** @return list<string> Splits on `;` outside string literals and `--` comments. */
    public static function statements(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $inStr = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($inStr) {
                $buf .= $c;
                if ($c === "'") {
                    if (($sql[$i + 1] ?? '') === "'") {
                        $buf .= $sql[++$i];
                    } else {
                        $inStr = false;
                    }
                }

                continue;
            }
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                $buf .= "\n";

                continue;
            }
            if ($c === "'") {
                $inStr = true;
                $buf .= $c;

                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';

                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }

        return $out;
    }
}
