<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opaque access tokens (not JWT): the access token's SHA-256 is stored on the session row so a
 * session revoke invalidates it immediately. Extends V0001's `session` (which stays untouched).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE session
            ADD COLUMN access_token_hash CHAR(64) NULL AFTER refresh_token_hash,
            ADD COLUMN access_expires_at DATETIME(6) NULL AFTER access_token_hash,
            ADD CONSTRAINT uq_session_access_hash UNIQUE (access_token_hash)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE session DROP INDEX uq_session_access_hash, DROP COLUMN access_expires_at, DROP COLUMN access_token_hash');
    }
};
