<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * customer / customer_account (architecture/04 §2 "Identity & devices").
 * Created here because Membership needs a holder; written with IF NOT EXISTS so that another module's
 * identical migration (Booking) never collides. A customer is a person the resort deals with (walk-in or online);
 * customer_account is their optional online login (cloud-authoritative, ADR-0013).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS customer (
  id               BINARY(16) NOT NULL PRIMARY KEY,
  organization_id  BINARY(16) NOT NULL,
  full_name        VARCHAR(200) NOT NULL,
  phone            VARCHAR(32) NULL,
  email            VARCHAR(255) NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at       DATETIME(6) NULL,
  row_version      INT UNSIGNED NOT NULL DEFAULT 1,
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_customer_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT uq_customer_phone UNIQUE (organization_id, phone),
  CONSTRAINT uq_customer_email UNIQUE (organization_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');

        DB::unprepared('CREATE TABLE IF NOT EXISTS customer_account (
  id             BINARY(16) NOT NULL PRIMARY KEY,
  customer_id    BINARY(16) NOT NULL,
  login_email    VARCHAR(255) NOT NULL,
  password_hash  VARCHAR(255) NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at  DATETIME(6) NULL,
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_ca_customer FOREIGN KEY (customer_id) REFERENCES customer (id),
  CONSTRAINT uq_ca_customer UNIQUE (customer_id),
  CONSTRAINT uq_ca_login UNIQUE (login_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
    }

    public function down(): void
    {
        // Intentionally left in place: another module may depend on these tables.
    }
};
