<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Single-row mutex + tail pointer for the audit hash chain. Writers lock THIS row (FOR UPDATE) instead of
 * the audit_log tail: locking "the last row" of an index takes gap locks and deadlocks under concurrency
 * (found by tests/Feature/ConcurrencyTest). The head also lets verifyChain() detect truncation of the tail.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE audit_chain_head (
  id         TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  tail_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tail_hash  CHAR(64) NOT NULL,
  CONSTRAINT ck_audit_chain_head_single CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
        DB::table('audit_chain_head')->insert(['id' => 1, 'tail_seq' => 0, 'tail_hash' => str_repeat('0', 64)]);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS audit_chain_head');
    }
};
