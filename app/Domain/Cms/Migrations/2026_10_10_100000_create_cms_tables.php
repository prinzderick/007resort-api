<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Website CMS (docs/CMS_API.md). Content the public website shows plus newsletter subscribers and contact messages.
 * Content is authoritative on the node where it is edited; every table carries row_version for ETag/If-Match and
 * (later) version-checked sync. No organization_id: a node serves one property (Phase 1 single organization/site).
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
        $st = "VARCHAR(12) NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT','PUBLISHED','ARCHIVED'))";
        $ts = 'created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)';

        DB::unprepared("CREATE TABLE cms_setting (
  id           BINARY(16) NOT NULL PRIMARY KEY,
  grp          VARCHAR(32) NOT NULL,
  value        JSON NOT NULL,
  row_version  INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by   BINARY(16) NULL,
  $ts,
  CONSTRAINT uq_cms_setting_grp UNIQUE (grp)
) $t");

        DB::unprepared("CREATE TABLE cms_media (
  id              BINARY(16) NOT NULL PRIMARY KEY,
  path            VARCHAR(255) NOT NULL,
  disk            VARCHAR(32) NOT NULL DEFAULT 'public',
  original_name   VARCHAR(255) NULL,
  mime_type       VARCHAR(64) NOT NULL,
  size_bytes      INT UNSIGNED NOT NULL,
  width           INT UNSIGNED NOT NULL,
  height          INT UNSIGNED NOT NULL,
  dominant_color  CHAR(7) NULL,
  sha256          CHAR(64) NOT NULL,
  alt             VARCHAR(300) NOT NULL DEFAULT '',
  credit          VARCHAR(300) NULL,
  source_url      VARCHAR(500) NULL,
  tags            JSON NULL,
  variants        JSON NULL,
  row_version     INT UNSIGNED NOT NULL DEFAULT 1,
  created_by      BINARY(16) NULL,
  $ts,
  INDEX ix_cms_media_created (created_at, id),
  INDEX ix_cms_media_sha (sha256)
) $t");

        DB::unprepared("CREATE TABLE cms_home_section (
  id           BINARY(16) NOT NULL PRIMARY KEY,
  type         VARCHAR(16) NOT NULL CHECK (type IN ('HERO_SLIDE','HIGHLIGHT','STAT','TESTIMONIAL','FAQ','PARTNER','CTA_BAND')),
  sort_order   INT NOT NULL DEFAULT 0,
  is_enabled   TINYINT(1) NOT NULL DEFAULT 0,
  payload      JSON NOT NULL,
  row_version  INT UNSIGNED NOT NULL DEFAULT 1,
  created_by   BINARY(16) NULL,
  $ts,
  INDEX ix_cms_home_order (is_enabled, type, sort_order)
) $t");

        DB::unprepared("CREATE TABLE cms_page (
  id              BINARY(16) NOT NULL PRIMARY KEY,
  slug            VARCHAR(120) NOT NULL,
  title           VARCHAR(160) NOT NULL,
  subtitle        VARCHAR(200) NULL,
  hero_media_id   BINARY(16) NULL,
  body_markdown   MEDIUMTEXT NOT NULL,
  body_html       MEDIUMTEXT NOT NULL,
  seo_title       VARCHAR(70) NULL,
  seo_description VARCHAR(200) NULL,
  seo_og_media_id BINARY(16) NULL,
  show_in_footer  TINYINT(1) NOT NULL DEFAULT 0,
  sort_order      INT NOT NULL DEFAULT 0,
  status          $st,
  published_at    DATETIME(6) NULL,
  row_version     INT UNSIGNED NOT NULL DEFAULT 1,
  created_by      BINARY(16) NULL,
  $ts,
  CONSTRAINT uq_cms_page_slug UNIQUE (slug),
  CONSTRAINT fk_cms_page_hero FOREIGN KEY (hero_media_id) REFERENCES cms_media (id),
  CONSTRAINT fk_cms_page_og FOREIGN KEY (seo_og_media_id) REFERENCES cms_media (id),
  INDEX ix_cms_page_status (status, published_at)
) $t");

        DB::unprepared("CREATE TABLE cms_post_category (
  id           BINARY(16) NOT NULL PRIMARY KEY,
  slug         VARCHAR(120) NOT NULL,
  name         VARCHAR(80) NOT NULL,
  description  VARCHAR(300) NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  row_version  INT UNSIGNED NOT NULL DEFAULT 1,
  created_by   BINARY(16) NULL,
  $ts,
  CONSTRAINT uq_cms_postcat_slug UNIQUE (slug)
) $t");

        DB::unprepared("CREATE TABLE cms_post (
  id                    BINARY(16) NOT NULL PRIMARY KEY,
  slug                  VARCHAR(120) NOT NULL,
  title                 VARCHAR(160) NOT NULL,
  excerpt               VARCHAR(400) NULL,
  body_markdown         MEDIUMTEXT NOT NULL,
  body_html             MEDIUMTEXT NOT NULL,
  cover_media_id        BINARY(16) NULL,
  author_name           VARCHAR(80) NULL,
  category_id           BINARY(16) NULL,
  tags                  JSON NULL,
  reading_time_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_featured           TINYINT(1) NOT NULL DEFAULT 0,
  seo_title             VARCHAR(70) NULL,
  seo_description       VARCHAR(200) NULL,
  seo_og_media_id       BINARY(16) NULL,
  status                $st,
  published_at          DATETIME(6) NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_by            BINARY(16) NULL,
  $ts,
  CONSTRAINT uq_cms_post_slug UNIQUE (slug),
  CONSTRAINT fk_cms_post_cover FOREIGN KEY (cover_media_id) REFERENCES cms_media (id),
  CONSTRAINT fk_cms_post_og FOREIGN KEY (seo_og_media_id) REFERENCES cms_media (id),
  CONSTRAINT fk_cms_post_cat FOREIGN KEY (category_id) REFERENCES cms_post_category (id),
  INDEX ix_cms_post_pub (status, published_at, id),
  INDEX ix_cms_post_cat (category_id, status, published_at)
) $t");

        DB::unprepared("CREATE TABLE cms_event (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  slug              VARCHAR(120) NOT NULL,
  title             VARCHAR(160) NOT NULL,
  summary           VARCHAR(300) NULL,
  body_markdown     MEDIUMTEXT NULL,
  body_html         MEDIUMTEXT NULL,
  cover_media_id    BINARY(16) NULL,
  category          VARCHAR(12) NOT NULL CHECK (category IN ('SPORT','MUSIC','PARTY','WELLNESS','FOOD','OTHER')),
  starts_at         DATETIME(6) NOT NULL,
  ends_at           DATETIME(6) NOT NULL,
  venue_label       VARCHAR(120) NULL,
  facility_id       BINARY(16) NULL,
  price_text        VARCHAR(80) NULL,
  capacity          INT UNSIGNED NULL,
  ticket_url        VARCHAR(500) NULL,
  ticket_product_id BINARY(16) NULL,
  recurrence        VARCHAR(8) NOT NULL DEFAULT 'NONE' CHECK (recurrence IN ('NONE','WEEKLY')),
  recurrence_until  DATE NULL,
  is_featured       TINYINT(1) NOT NULL DEFAULT 0,
  seo_title         VARCHAR(70) NULL,
  seo_description   VARCHAR(200) NULL,
  seo_og_media_id   BINARY(16) NULL,
  status            $st,
  published_at      DATETIME(6) NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  created_by        BINARY(16) NULL,
  $ts,
  CONSTRAINT uq_cms_event_slug UNIQUE (slug),
  CONSTRAINT ck_cms_event_span CHECK (ends_at > starts_at),
  CONSTRAINT fk_cms_event_cover FOREIGN KEY (cover_media_id) REFERENCES cms_media (id),
  CONSTRAINT fk_cms_event_og FOREIGN KEY (seo_og_media_id) REFERENCES cms_media (id),
  INDEX ix_cms_event_when (status, starts_at, id),
  INDEX ix_cms_event_cat (category, status, starts_at)
) $t");

        DB::unprepared("CREATE TABLE cms_gallery_album (
  id              BINARY(16) NOT NULL PRIMARY KEY,
  slug            VARCHAR(120) NOT NULL,
  title           VARCHAR(120) NOT NULL,
  description     VARCHAR(1000) NULL,
  cover_media_id  BINARY(16) NULL,
  sort_order      INT NOT NULL DEFAULT 0,
  status          $st,
  published_at    DATETIME(6) NULL,
  row_version     INT UNSIGNED NOT NULL DEFAULT 1,
  created_by      BINARY(16) NULL,
  $ts,
  CONSTRAINT uq_cms_album_slug UNIQUE (slug),
  CONSTRAINT fk_cms_album_cover FOREIGN KEY (cover_media_id) REFERENCES cms_media (id),
  INDEX ix_cms_album_status (status, sort_order)
) $t");

        DB::unprepared("CREATE TABLE cms_gallery_item (
  id           BINARY(16) NOT NULL PRIMARY KEY,
  album_id     BINARY(16) NOT NULL,
  media_id     BINARY(16) NOT NULL,
  caption      VARCHAR(300) NULL,
  alt_text     VARCHAR(200) NULL,
  category     VARCHAR(60) NULL,
  tags         JSON NULL,
  is_featured  TINYINT(1) NOT NULL DEFAULT 0,
  sort_order   INT NOT NULL DEFAULT 0,
  row_version  INT UNSIGNED NOT NULL DEFAULT 1,
  $ts,
  CONSTRAINT uq_cms_item_album_media UNIQUE (album_id, media_id),
  CONSTRAINT fk_cms_item_album FOREIGN KEY (album_id) REFERENCES cms_gallery_album (id) ON DELETE CASCADE,
  CONSTRAINT fk_cms_item_media FOREIGN KEY (media_id) REFERENCES cms_media (id),
  INDEX ix_cms_item_order (album_id, sort_order)
) $t");

        DB::unprepared("CREATE TABLE cms_subscriber (
  id                     BINARY(16) NOT NULL PRIMARY KEY,
  email                  VARCHAR(190) NOT NULL,
  name                   VARCHAR(120) NULL,
  source                 VARCHAR(12) NOT NULL DEFAULT 'footer' CHECK (source IN ('footer','blog','event','popup','checkout')),
  status                 VARCHAR(14) NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING','CONFIRMED','UNSUBSCRIBED')),
  consent_text           VARCHAR(500) NOT NULL,
  consented_at           DATETIME(6) NOT NULL,
  ip_hash                CHAR(64) NULL,
  confirm_token_hash     CHAR(64) NULL,
  confirm_expires_at     DATETIME(6) NULL,
  confirm_sent_at        DATETIME(6) NULL,
  confirmed_at           DATETIME(6) NULL,
  unsubscribed_at        DATETIME(6) NULL,
  row_version            INT UNSIGNED NOT NULL DEFAULT 1,
  $ts,
  CONSTRAINT uq_cms_subscriber_email UNIQUE (email),
  INDEX ix_cms_subscriber_token (confirm_token_hash),
  INDEX ix_cms_subscriber_list (status, created_at, id)
) $t");

        DB::unprepared("CREATE TABLE cms_contact_message (
  id            BINARY(16) NOT NULL PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(40) NULL,
  topic         VARCHAR(12) NOT NULL DEFAULT 'GENERAL' CHECK (topic IN ('GENERAL','BOOKING','EVENTS','MEMBERSHIP','FEEDBACK','PRESS','OTHER')),
  message       TEXT NOT NULL,
  status        VARCHAR(8) NOT NULL DEFAULT 'NEW' CHECK (status IN ('NEW','READ','REPLIED','SPAM')),
  internal_note VARCHAR(1000) NULL,
  ip_hash       CHAR(64) NULL,
  user_agent    VARCHAR(255) NULL,
  handled_by    BINARY(16) NULL,
  handled_at    DATETIME(6) NULL,
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  $ts,
  INDEX ix_cms_msg_list (status, created_at, id)
) $t");
    }

    public function down(): void
    {
        foreach (['cms_contact_message', 'cms_subscriber', 'cms_gallery_item', 'cms_gallery_album', 'cms_event', 'cms_post', 'cms_post_category', 'cms_page', 'cms_home_section', 'cms_media', 'cms_setting'] as $tbl) {
            DB::unprepared("DROP TABLE IF EXISTS {$tbl}");
        }
    }
};
