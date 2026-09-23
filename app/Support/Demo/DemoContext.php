<?php

namespace App\Support\Demo;

use Illuminate\Console\Command;

final class DemoContext
{
    /** @var list<array{title: string, rows: list<list<string>>, headers: list<string>}> */
    public array $tables = [];

    public function __construct(public readonly Command $command) {}

    public function info(string $line): void
    {
        $this->command->info($line);
    }

    /** Queue a table for the end-of-run summary (credentials, tokens, ...). */
    public function table(string $title, array $headers, array $rows): void
    {
        $this->tables[] = compact('title', 'headers', 'rows');
    }
}
