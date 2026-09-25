<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Social sign-in (docs/CUSTOMER_SOCIAL_LOGIN.md).
 *  - customer_identity: one row per linked provider account. `provider` is VARCHAR with NO enum/CHECK on purpose: adding Apple (or any
 *    OIDC provider) needs config only, never a migration. UNIQUE(provider, provider_user_id) = the identity key; UNIQUE(customer_id, provider)
 *    = at most one account per provider per customer. ON DELETE CASCADE: erasing a customer removes the identities.
 *  - customer_account.login_email becomes NULLable: a social customer whose provider gave no (verified) email has NO placeholder address;
 *    UNIQUE(login_email) still holds (NULLs do not collide). + terms/marketing consent timestamps.
 *  - customer_email_token: purposes EMAIL (add/change login email) and LINK (pending social link confirmation), + `email` and `payload`.
 *  - service_token.scope becomes a comma set of {public.read, customer.social}.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

        DB::unprepared("CREATE TABLE customer_identity (
  id                        BINARY(16) NOT NULL PRIMARY KEY,
  customer_id               BINARY(16) NOT NULL,
  provider                  VARCHAR(24) NOT NULL,
  provider_user_id          VARCHAR(191) NOT NULL,
  email_at_link             VARCHAR(255) NULL,
  email_verified_at_link    DATETIME(6) NULL,
  avatar_url                VARCHAR(1024) NULL,
  linked_at                 DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_login_at             DATETIME(6) NULL,
  created_at                DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_cident_customer FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE,
  CONSTRAINT uq_cident_provider_user UNIQUE (provider, provider_user_id),
  CONSTRAINT uq_cident_customer_provider UNIQUE (customer_id, provider),
  INDEX ix_cident_customer (customer_id)
) $t");

        DB::unprepared('ALTER TABLE customer_account
  MODIFY login_email VARCHAR(255) NULL,
  ADD COLUMN terms_accepted_at DATETIME(6) NULL,
  ADD COLUMN marketing_consent_at DATETIME(6) NULL');

        $this->dropChecks('customer_email_token', 'purpose');
        DB::unprepared("ALTER TABLE customer_email_token
  ADD COLUMN email VARCHAR(255) NULL,
  ADD COLUMN payload JSON NULL,
  ADD CONSTRAINT ck_cet_purpose CHECK (purpose IN ('VERIFY','RESET','EMAIL','LINK'))");

        $this->dropChecks('service_token', 'scope');
        DB::unprepared("ALTER TABLE service_token
  MODIFY scope VARCHAR(64) NOT NULL DEFAULT 'public.read',
  ADD CONSTRAINT ck_stok_scope CHECK (scope IN ('public.read','customer.social','public.read,customer.social'))");
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS customer_identity');
        $this->dropChecks('customer_email_token', 'purpose');
        DB::unprepared("DELETE FROM customer_email_token WHERE purpose IN ('EMAIL','LINK')");
        DB::unprepared("ALTER TABLE customer_email_token DROP COLUMN email, DROP COLUMN payload, ADD CONSTRAINT ck_cet_purpose CHECK (purpose IN ('VERIFY','RESET'))");
        $this->dropChecks('service_token', 'scope');
        DB::unprepared("UPDATE service_token SET scope = 'public.read'");
        DB::unprepared("ALTER TABLE service_token MODIFY scope VARCHAR(32) NOT NULL DEFAULT 'public.read', ADD CONSTRAINT ck_stok_scope CHECK (scope IN ('public.read'))");
        DB::unprepared('ALTER TABLE customer_account DROP COLUMN terms_accepted_at, DROP COLUMN marketing_consent_at');
        // login_email stays NULLable on down (rows may hold NULL); a strict revert would need a data decision.
    }

    /** Drop every CHECK on $table whose expression mentions $column (MySQL auto-names inline checks). */
    private function dropChecks(string $table, string $column): void
    {
        $rows = DB::select('SELECT tc.CONSTRAINT_NAME AS n FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc
            ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = \'CHECK\' AND cc.CHECK_CLAUSE LIKE ?', [$table, "%{$column}%"]);
        foreach ($rows as $r) {
            DB::unprepared("ALTER TABLE {$table} DROP CHECK `{$r->n}`");
        }
    }
};
