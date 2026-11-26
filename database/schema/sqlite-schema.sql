BEGIN TRANSACTION;
CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "password_resets"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime
);
CREATE INDEX "password_resets_email_index" on "password_resets"("email");
CREATE TABLE IF NOT EXISTS "oauth_auth_codes"(
  "id" varchar not null,
  "user_id" integer not null,
  "client_id" varchar not null,
  "scopes" text,
  "revoked" tinyint(1) not null,
  "expires_at" datetime,
  primary key("id")
);
CREATE INDEX "oauth_auth_codes_user_id_index" on "oauth_auth_codes"("user_id");
CREATE TABLE IF NOT EXISTS "oauth_access_tokens"(
  "id" varchar not null,
  "user_id" integer,
  "client_id" varchar not null,
  "name" varchar,
  "scopes" text,
  "revoked" tinyint(1) not null,
  "created_at" datetime,
  "updated_at" datetime,
  "expires_at" datetime,
  primary key("id")
);
CREATE INDEX "oauth_access_tokens_user_id_index" on "oauth_access_tokens"(
  "user_id"
);
CREATE TABLE IF NOT EXISTS "oauth_refresh_tokens"(
  "id" varchar not null,
  "access_token_id" varchar not null,
  "revoked" tinyint(1) not null,
  "expires_at" datetime,
  primary key("id")
);
CREATE INDEX "oauth_refresh_tokens_access_token_id_index" on "oauth_refresh_tokens"(
  "access_token_id"
);
CREATE TABLE IF NOT EXISTS "oauth_clients"(
  "id" varchar not null,
  "owner_type" varchar,
  "owner_id" integer,
  "name" varchar not null,
  "secret" varchar,
  "provider" varchar,
  "redirect_uris" text not null,
  "grant_types" text not null,
  "revoked" tinyint(1) not null,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE INDEX "oauth_clients_owner_type_owner_id_index" on "oauth_clients"(
  "owner_type",
  "owner_id"
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" text not null,
  "queue" text not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "personal_access_tokens"(
  "id" integer primary key autoincrement not null,
  "tokenable_type" varchar not null,
  "tokenable_id" integer not null,
  "name" varchar not null,
  "token" varchar not null,
  "abilities" text,
  "last_used_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "expires_at" datetime
);
CREATE INDEX "personal_access_tokens_tokenable_type_tokenable_id_index" on "personal_access_tokens"(
  "tokenable_type",
  "tokenable_id"
);
CREATE UNIQUE INDEX "personal_access_tokens_token_unique" on "personal_access_tokens"(
  "token"
);
CREATE TABLE IF NOT EXISTS "media"(
  "id" integer primary key autoincrement not null,
  "model_type" varchar not null,
  "model_id" integer not null,
  "uuid" varchar,
  "collection_name" varchar not null,
  "name" varchar not null,
  "file_name" varchar not null,
  "mime_type" varchar,
  "disk" varchar not null,
  "conversions_disk" varchar,
  "size" integer not null,
  "manipulations" text not null,
  "custom_properties" text not null,
  "generated_conversions" text not null,
  "responsive_images" text not null,
  "order_column" integer,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "media_model_type_model_id_index" on "media"(
  "model_type",
  "model_id"
);
CREATE UNIQUE INDEX "media_uuid_unique" on "media"("uuid");
CREATE TABLE IF NOT EXISTS "activity_log"(
  "id" integer primary key autoincrement not null,
  "log_name" varchar,
  "description" text not null,
  "subject_type" varchar,
  "subject_id" integer,
  "causer_type" varchar,
  "causer_id" integer,
  "properties" text,
  "created_at" datetime,
  "updated_at" datetime,
  "event" varchar,
  "batch_uuid" varchar
);
CREATE INDEX "subject" on "activity_log"("subject_type", "subject_id");
CREATE INDEX "causer" on "activity_log"("causer_type", "causer_id");
CREATE INDEX "activity_log_log_name_index" on "activity_log"("log_name");
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "template_template_collection"(
  "template_id" integer not null,
  "template_collection_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "temp_temp_coll_id" on "template_template_collection"(
  "template_id",
  "template_collection_id"
);
CREATE TABLE IF NOT EXISTS "snap_imports"(
  "id" integer primary key autoincrement not null,
  "template_id" integer not null,
  "company_id" integer not null,
  "user_id" integer not null,
  "uuid" varchar not null,
  "status" varchar not null default 'pending',
  "total_rows" integer not null default '0',
  "processed_rows" integer not null default '0',
  "batch_id" varchar,
  "original_filename" varchar,
  "stored_path" varchar,
  "error_message" text,
  "completed_at" datetime,
  "canceled_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "title" varchar,
  "webhook_url" varchar
);
CREATE INDEX "snap_imports_template_id_index" on "snap_imports"("template_id");
CREATE INDEX "snap_imports_company_id_index" on "snap_imports"("company_id");
CREATE INDEX "snap_imports_user_id_index" on "snap_imports"("user_id");
CREATE INDEX "snap_imports_uuid_index" on "snap_imports"("uuid");
CREATE INDEX "snap_imports_batch_id_index" on "snap_imports"("batch_id");
CREATE TABLE IF NOT EXISTS "companies"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "first_snap_at" datetime,
  "webhook_secret" text
);
CREATE UNIQUE INDEX "companies_uuid_unique" on "companies"("uuid");
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar,
  "email" varchar not null,
  "password" varchar not null,
  "email_verified_at" datetime,
  "newsletter" tinyint(1) not null default('0'),
  "role" varchar not null default('customer'),
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "company_id" integer,
  "two_factor_secret" text,
  "two_factor_recovery_codes" text,
  "two_factor_confirmed_at" datetime,
  "onboarding_completed_at" datetime,
  "onboarding_categories" text,
  "is_owner" tinyint(1) not null default '0',
  "deactivated_at" datetime,
  "company_role" varchar not null default 'member',
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE IF NOT EXISTS "template_collections"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "uuid" varchar not null,
  "name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "company_id" integer,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "template_collections_uuid_unique" on "template_collections"(
  "uuid"
);
CREATE TABLE IF NOT EXISTS "forms"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "user_id" integer not null,
  "title" varchar not null,
  "available_layers" text not null,
  "signed_url" varchar,
  "submit_signed_url" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "type" varchar not null default('image'),
  "formable_type" varchar,
  "formable_id" integer,
  "company_id" integer,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE INDEX "forms_formable_type_formable_id_index" on "forms"(
  "formable_type",
  "formable_id"
);
CREATE UNIQUE INDEX "forms_signed_url_unique" on "forms"("signed_url");
CREATE UNIQUE INDEX "forms_submit_signed_url_unique" on "forms"(
  "submit_signed_url"
);
CREATE INDEX "forms_type_index" on "forms"("type");
CREATE INDEX "forms_user_id_index" on "forms"("user_id");
CREATE UNIQUE INDEX "forms_uuid_unique" on "forms"("uuid");
CREATE TABLE IF NOT EXISTS "company_invitations"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "invited_by" integer not null,
  "email" varchar not null,
  "token" varchar not null,
  "expires_at" datetime,
  "accepted_at" datetime,
  "accepted_by" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("invited_by") references "users"("id") on delete cascade,
  foreign key("accepted_by") references "users"("id") on delete set null
);
CREATE INDEX "company_invitations_company_id_email_index" on "company_invitations"(
  "company_id",
  "email"
);
CREATE UNIQUE INDEX "company_invitations_token_unique" on "company_invitations"(
  "token"
);
CREATE TABLE IF NOT EXISTS "tags"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "name" varchar not null,
  "slug" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "tags_company_id_slug_unique" on "tags"(
  "company_id",
  "slug"
);
CREATE TABLE IF NOT EXISTS "taggables"(
  "id" integer primary key autoincrement not null,
  "tag_id" integer not null,
  "taggable_type" varchar not null,
  "taggable_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tag_id") references "tags"("id") on delete cascade
);
CREATE INDEX "taggables_taggable_type_taggable_id_index" on "taggables"(
  "taggable_type",
  "taggable_id"
);
CREATE UNIQUE INDEX "taggables_unique" on "taggables"(
  "tag_id",
  "taggable_id",
  "taggable_type"
);
CREATE TABLE IF NOT EXISTS "api_keys"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "token_id" integer not null,
  "type" varchar not null,
  "key" text not null,
  "invalidated_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "company_id" integer,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "screenshots"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "user_id" integer not null,
  "url" varchar not null,
  "width" integer not null,
  "height" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  "full_page" tinyint(1) not null default('0'),
  "company_id" integer not null
);
CREATE INDEX "screenshots_user_id_index" on "screenshots"("user_id");
CREATE INDEX "screenshots_uuid_index" on "screenshots"("uuid");
CREATE TABLE IF NOT EXISTS "user_events"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "company_id" integer,
  "name" varchar not null,
  "payload" text,
  "created_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("company_id") references "companies"("id") on delete set null
);
CREATE INDEX "user_events_company_id_name_index" on "user_events"(
  "company_id",
  "name"
);
CREATE INDEX "user_events_name_index" on "user_events"("name");
CREATE INDEX "user_events_created_at_index" on "user_events"("created_at");
CREATE TABLE IF NOT EXISTS "api_logs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "user_id" integer not null,
  "url" text not null,
  "ip_address" varchar not null,
  "method" varchar not null,
  "request" text not null,
  "response" text,
  "status_code" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "renders" integer not null default('1'),
  "type" varchar,
  "feature" varchar,
  "personal_access_token_id" integer
);
CREATE TABLE IF NOT EXISTS "fonts"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "company_id" integer not null,
  "user_id" integer,
  "name" varchar not null,
  "family" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null
);
CREATE INDEX "fonts_company_id_index" on "fonts"("company_id");
CREATE UNIQUE INDEX "fonts_uuid_unique" on "fonts"("uuid");
CREATE TABLE IF NOT EXISTS "templates"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "uuid" varchar not null,
  "title" varchar not null,
  "width" varchar not null,
  "height" varchar not null,
  "layers" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  "size" varchar,
  "company_id" integer,
  "groups" text,
  "published_version_id" integer,
  "editor_document" text,
  "editor_schema_version" integer,
  "editor_revision" integer,
  foreign key("company_id") references "companies"("id") on delete cascade on update no action,
  foreign key("published_version_id") references "template_versions"("id") on delete set null
);
CREATE INDEX "templates_size_index" on "templates"("size");
CREATE TABLE IF NOT EXISTS "idempotency_keys"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "key" varchar not null,
  "status" varchar not null default 'in_progress',
  "snap_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "snap_import_id" integer,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "idempotency_keys_company_id_key_unique" on "idempotency_keys"(
  "company_id",
  "key"
);
CREATE INDEX "idempotency_keys_created_at_index" on "idempotency_keys"(
  "created_at"
);
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE UNIQUE INDEX "templates_company_id_uuid_unique" on "templates"(
  "company_id",
  "uuid"
);
CREATE TABLE IF NOT EXISTS "editor_assets"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "company_id" integer not null,
  "sha256" varchar not null,
  "mime_type" varchar not null,
  "byte_size" integer not null,
  "width" integer not null,
  "height" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "editor_assets_company_id_sha256_unique" on "editor_assets"(
  "company_id",
  "sha256"
);
CREATE INDEX "editor_assets_company_id_created_at_id_index" on "editor_assets"(
  "company_id",
  "created_at",
  "id"
);
CREATE UNIQUE INDEX "editor_assets_uuid_unique" on "editor_assets"("uuid");
CREATE TABLE IF NOT EXISTS "template_version_assets"(
  "template_version_id" integer not null,
  "editor_asset_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("template_version_id") references "template_versions"("id") on delete restrict,
  foreign key("editor_asset_id") references "editor_assets"("id") on delete restrict,
  primary key("template_version_id", "editor_asset_id")
);
CREATE INDEX "template_version_assets_editor_asset_id_index" on "template_version_assets"(
  "editor_asset_id"
);
CREATE TABLE IF NOT EXISTS "brand_tokens"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "uuid" varchar not null,
  "kind" varchar not null,
  "name" varchar not null,
  "value" text not null,
  "version" integer not null default '1',
  "retired_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "brand_tokens_company_id_kind_name_version_unique" on "brand_tokens"(
  "company_id",
  "kind",
  "name",
  "version"
);
CREATE INDEX "brand_tokens_company_id_kind_retired_at_index" on "brand_tokens"(
  "company_id",
  "kind",
  "retired_at"
);
CREATE UNIQUE INDEX "brand_tokens_uuid_unique" on "brand_tokens"("uuid");
CREATE TABLE IF NOT EXISTS "creative_packages"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "company_id" integer not null,
  "created_by" integer,
  "operation_namespace" varchar not null default 'creative_package_export_v1',
  "idempotency_key" varchar not null,
  "request_fingerprint" varchar not null,
  "status" varchar not null default 'building',
  "total_items" integer not null default '0',
  "pending_items_count" integer not null default '0',
  "rendering_items_count" integer not null default '0',
  "ready_items_count" integer not null default '0',
  "failed_items_count" integer not null default '0',
  "manifest_version" integer not null default '1',
  "failure_code" varchar,
  "failure_details" varchar,
  "lease_expires_at" datetime not null,
  "started_at" datetime not null,
  "completed_at" datetime,
  "replay_expires_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("created_by") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "creative_packages_company_uuid_unique" on "creative_packages"(
  "company_id",
  "uuid"
);
CREATE UNIQUE INDEX "creative_packages_company_id_unique" on "creative_packages"(
  "company_id",
  "id"
);
CREATE UNIQUE INDEX "creative_packages_company_claim_unique" on "creative_packages"(
  "company_id",
  "operation_namespace",
  "idempotency_key"
);
CREATE INDEX "creative_packages_active_limit_index" on "creative_packages"(
  "company_id",
  "status",
  "lease_expires_at"
);
CREATE INDEX "creative_packages_replay_cleanup_index" on "creative_packages"(
  "replay_expires_at",
  "id"
);
CREATE INDEX "api_logs_url_index" on "api_logs"("url");
CREATE INDEX "api_logs_uuid_index" on "api_logs"("uuid");
CREATE TABLE IF NOT EXISTS "template_versions"(
  "id" integer primary key autoincrement not null,
  "template_id" integer,
  "version_number" integer not null,
  "layers" text,
  "groups" text,
  "width" integer,
  "height" integer,
  "published" tinyint(1) not null default('0'),
  "created_by" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "editor_document" text,
  "editor_schema_version" integer,
  "editor_revision" integer,
  foreign key("created_by") references "users"("id") on delete set null on update no action,
  foreign key("template_id") references "templates"("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "template_versions_template_id_id_unique" on "template_versions"(
  "template_id",
  "id"
);
CREATE UNIQUE INDEX "template_versions_template_id_version_number_unique" on "template_versions"(
  "template_id",
  "version_number"
);
CREATE TABLE IF NOT EXISTS "snaps"(
  "id" integer primary key autoincrement not null,
  "template_id" integer,
  "user_id" integer,
  "uuid" varchar not null,
  "metadata" text,
  "created_at" datetime,
  "updated_at" datetime,
  "identifier" varchar,
  "variants" text,
  "type" varchar,
  "form_id" integer,
  "snap_import_id" integer,
  "is_async" tinyint(1) not null default('0'),
  "status" varchar not null default('completed'),
  "webhook_url" varchar,
  "last_error" text,
  "completed_at" datetime,
  "company_id" integer,
  "format" varchar not null default('png'),
  "transparent" tinyint(1) not null default('0'),
  "source_template_version_id" integer,
  foreign key("template_id", "source_template_version_id") references "template_versions"("template_id", "id") on delete restrict on update restrict,
  foreign key("company_id") references "companies"("id") on delete cascade on update no action,
  foreign key("source_template_version_id") references "template_versions"("id") on delete restrict on update restrict
);
CREATE INDEX "snaps_form_id_index" on "snaps"("form_id");
CREATE INDEX "snaps_identifier_index" on "snaps"("identifier");
CREATE INDEX "snaps_snap_import_id_index" on "snaps"("snap_import_id");
CREATE INDEX "snaps_source_template_version_id_index" on "snaps"(
  "source_template_version_id"
);
CREATE INDEX "snaps_template_id_index" on "snaps"("template_id");
CREATE INDEX "snaps_template_id_source_template_version_id_index" on "snaps"(
  "template_id",
  "source_template_version_id"
);
CREATE INDEX "snaps_user_id_index" on "snaps"("user_id");
CREATE INDEX "snaps_uuid_index" on "snaps"("uuid");
CREATE INDEX "creative_packages_reconciliation_index" on "creative_packages"(
  "status",
  "lease_expires_at",
  "id"
);
CREATE UNIQUE INDEX creative_packages_uuid_unique ON creative_packages(uuid);
CREATE TABLE IF NOT EXISTS "creative_package_items"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "company_id" integer not null,
  "creative_package_id" integer not null,
  "position" integer not null,
  "client_creative_id" varchar not null,
  "client_family_id" varchar,
  "label" varchar not null,
  "placement_key" varchar,
  "caption" varchar,
  "alt_text" varchar not null,
  "metadata" text,
  "source_template_uuid" varchar not null,
  "source_template_version" integer not null,
  "source_editor_document_version" integer,
  "source_template_version_id" integer,
  "requested_format" varchar not null,
  "status" varchar not null default('pending'),
  "created_at" datetime,
  "updated_at" datetime,
  "attempts" integer not null default('0'),
  "retryable" tinyint(1) not null default('1'),
  "lease_token" varchar,
  "lease_generation" integer not null default('0'),
  "lease_expires_at" datetime,
  "snap_id" integer,
  "media_id" integer,
  "api_log_id" integer,
  "output_filename" varchar,
  "output_mime_type" varchar,
  "output_bytes" integer,
  "output_sha256" varchar,
  "failure_code" varchar,
  "failure_details" varchar,
  "diagnostic_code" varchar,
  "diagnostic_exception_class" varchar,
  "diagnostic_correlation_id" varchar,
  "diagnostic_message_fingerprint" varchar,
  "diagnostic_expires_at" datetime,
  "started_at" datetime,
  "completed_at" datetime,
  "output_width" integer,
  "output_height" integer,
  foreign key("api_log_id") references "api_logs"("id") on delete set null on update no action,
  foreign key("media_id") references "media"("id") on delete set null on update no action,
  foreign key("snap_id") references "snaps"("id") on delete set null on update no action,
  foreign key("company_id", "creative_package_id") references "creative_packages"("company_id", "id") on delete cascade on update restrict,
  foreign key("source_template_version_id") references "template_versions"("id") on delete set null on update restrict
);
CREATE INDEX "creative_package_items_api_log_index" on "creative_package_items"(
  "api_log_id"
);
CREATE UNIQUE INDEX "creative_package_items_company_uuid_unique" on "creative_package_items"(
  "company_id",
  "uuid"
);
CREATE INDEX "creative_package_items_diagnostic_cleanup_index" on "creative_package_items"(
  "diagnostic_expires_at",
  "id"
);
CREATE UNIQUE INDEX "creative_package_items_lease_token_unique" on "creative_package_items"(
  "lease_token"
);
CREATE INDEX "creative_package_items_media_index" on "creative_package_items"(
  "media_id"
);
CREATE INDEX "creative_package_items_ordered_relation_index" on "creative_package_items"(
  "creative_package_id",
  "position",
  "id"
);
CREATE UNIQUE INDEX "creative_package_items_package_client_unique" on "creative_package_items"(
  "company_id",
  "creative_package_id",
  "client_creative_id"
);
CREATE UNIQUE INDEX "creative_package_items_package_position_unique" on "creative_package_items"(
  "company_id",
  "creative_package_id",
  "position"
);
CREATE INDEX "creative_package_items_reconciliation_index" on "creative_package_items"(
  "status",
  "retryable",
  "lease_expires_at",
  "id"
);
CREATE INDEX "creative_package_items_snap_index" on "creative_package_items"(
  "snap_id"
);
CREATE INDEX "creative_package_items_source_version_index" on "creative_package_items"(
  "source_template_version_id"
);
CREATE TABLE IF NOT EXISTS "instance_settings"(
  "key" varchar not null,
  "value" text,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("key")
);

INSERT INTO migrations VALUES(1,'2014_10_12_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'2014_10_12_100000_create_password_resets_table',1);
INSERT INTO migrations VALUES(3,'2016_06_01_000001_create_oauth_auth_codes_table',1);
INSERT INTO migrations VALUES(4,'2016_06_01_000002_create_oauth_access_tokens_table',1);
INSERT INTO migrations VALUES(5,'2016_06_01_000003_create_oauth_refresh_tokens_table',1);
INSERT INTO migrations VALUES(6,'2016_06_01_000004_create_oauth_clients_table',1);
INSERT INTO migrations VALUES(7,'2019_05_03_000001_create_customer_columns',1);
INSERT INTO migrations VALUES(8,'2019_05_03_000002_create_subscriptions_table',1);
INSERT INTO migrations VALUES(9,'2019_05_03_000003_create_subscription_items_table',1);
INSERT INTO migrations VALUES(10,'2019_08_19_000000_create_failed_jobs_table',1);
INSERT INTO migrations VALUES(11,'2019_12_14_000001_create_personal_access_tokens_table',1);
INSERT INTO migrations VALUES(12,'2021_08_11_001413_create_templates_table',1);
INSERT INTO migrations VALUES(13,'2021_08_14_153701_create_media_table',1);
INSERT INTO migrations VALUES(14,'2021_08_27_222312_create_snaps_table',1);
INSERT INTO migrations VALUES(15,'2021_09_01_200359_create_activity_log_table',1);
INSERT INTO migrations VALUES(16,'2021_09_01_200400_add_event_column_to_activity_log_table',1);
INSERT INTO migrations VALUES(17,'2021_09_01_200401_add_batch_uuid_column_to_activity_log_table',1);
INSERT INTO migrations VALUES(18,'2022_02_25_203434_create_screenshots_table',1);
INSERT INTO migrations VALUES(19,'2022_08_22_155349_api_logs',1);
INSERT INTO migrations VALUES(20,'2022_08_22_155357_usage_logs',1);
INSERT INTO migrations VALUES(21,'2022_09_21_213154_create_jobs_table',1);
INSERT INTO migrations VALUES(22,'2022_12_21_164823_add_api_keys_table',1);
INSERT INTO migrations VALUES(23,'2022_12_22_162432_add_identifier_column_to_snaps_table',1);
INSERT INTO migrations VALUES(24,'2023_01_23_233400_add_columns_to_snaps_table',1);
INSERT INTO migrations VALUES(25,'2023_07_03_200935_create_forms_table',1);
INSERT INTO migrations VALUES(26,'2023_07_03_205054_add_form_id_column_to_snaps_table',1);
INSERT INTO migrations VALUES(27,'2023_09_22_191541_create_template_collections_table',1);
INSERT INTO migrations VALUES(28,'2023_09_22_191735_create_template_template_collection_table',1);
INSERT INTO migrations VALUES(29,'2023_09_28_234321_add_credits_spent_columnt_to_api_logs_table',1);
INSERT INTO migrations VALUES(30,'2023_11_01_172704_update_linear_gradient_value_in_templates_table',1);
INSERT INTO migrations VALUES(31,'2023_11_10_225159_add_full_page_column_to_screenshots_table',1);
INSERT INTO migrations VALUES(32,'2023_12_13_232820_add_type_column_to_forms_table',1);
INSERT INTO migrations VALUES(33,'2024_01_09_165954_add_size_column_to_templates_table',1);
INSERT INTO migrations VALUES(34,'2024_04_16_000000_create_snap_imports_table',1);
INSERT INTO migrations VALUES(35,'2024_04_16_000100_add_snap_import_id_to_snaps_table',1);
INSERT INTO migrations VALUES(36,'2024_04_25_000000_make_forms_polymorphic',1);
INSERT INTO migrations VALUES(37,'2024_05_21_120000_add_title_and_webhook_to_snap_imports_table',1);
INSERT INTO migrations VALUES(38,'2025_10_29_171915_add_async_fields_to_snaps_table',1);
INSERT INTO migrations VALUES(39,'2025_10_29_180001_create_companies_table',1);
INSERT INTO migrations VALUES(40,'2025_10_29_190000_move_billing_to_companies',1);
INSERT INTO migrations VALUES(41,'2025_10_30_000001_add_personal_access_token_id_to_api_logs_table',1);
INSERT INTO migrations VALUES(42,'2025_10_30_010000_create_company_invitations_table',1);
INSERT INTO migrations VALUES(43,'2025_10_30_020000_create_tags_table',1);
INSERT INTO migrations VALUES(44,'2025_10_30_020100_create_taggables_table',1);
INSERT INTO migrations VALUES(45,'2025_10_31_030000_add_company_id_to_api_keys_table',1);
INSERT INTO migrations VALUES(46,'2025_11_05_000000_make_usage_log_id_nullable_on_api_logs_table',1);
INSERT INTO migrations VALUES(47,'2025_11_06_155100_add_company_id_to_screenshots_table',1);
INSERT INTO migrations VALUES(48,'2025_11_15_000000_add_two_factor_columns_to_users_table',1);
INSERT INTO migrations VALUES(49,'2025_12_01_000000_add_expires_at_to_personal_access_tokens_table',1);
INSERT INTO migrations VALUES(50,'2026_05_15_000000_add_onboarding_completed_at_to_users_table',1);
INSERT INTO migrations VALUES(51,'2026_05_15_000100_add_onboarding_categories_to_users_table',1);
INSERT INTO migrations VALUES(53,'2026_05_15_000300_create_user_events_table',1);
INSERT INTO migrations VALUES(54,'2026_05_15_000400_add_first_snap_at_to_companies_table',1);
INSERT INTO migrations VALUES(55,'2026_06_17_000000_rename_subscriptions_name_to_type',1);
INSERT INTO migrations VALUES(56,'2026_06_19_000000_widen_api_logs_url_to_text',1);
INSERT INTO migrations VALUES(57,'2026_06_23_000000_add_format_and_transparent_to_snaps',1);
INSERT INTO migrations VALUES(58,'2026_06_23_000100_add_groups_to_templates',1);
INSERT INTO migrations VALUES(59,'2026_06_23_000200_create_fonts_table',1);
INSERT INTO migrations VALUES(60,'2026_06_23_000300_create_template_versions_table',1);
INSERT INTO migrations VALUES(63,'2026_09_10_000000_create_idempotency_keys_table',1);
INSERT INTO migrations VALUES(64,'2026_09_11_000000_create_job_batches_table',1);
INSERT INTO migrations VALUES(65,'2026_09_11_000100_add_snap_import_id_to_idempotency_keys_table',1);
INSERT INTO migrations VALUES(66,'2026_09_15_000000_add_editor_document_snapshot_integrity',1);
INSERT INTO migrations VALUES(67,'2026_09_15_000100_create_editor_assets_table',1);
INSERT INTO migrations VALUES(68,'2026_09_15_000200_create_brand_tokens_table',1);
INSERT INTO migrations VALUES(69,'2026_09_16_000000_add_billing_identity_to_api_logs',1);
INSERT INTO migrations VALUES(70,'2026_09_16_000100_create_creative_packages_table',1);
INSERT INTO migrations VALUES(71,'2026_09_16_000200_create_creative_package_items_table',1);
INSERT INTO migrations VALUES(72,'2026_09_17_000000_add_indexes_to_api_logs_table',1);
INSERT INTO migrations VALUES(73,'2026_09_17_000300_add_execution_state_to_creative_package_items_table',1);
INSERT INTO migrations VALUES(74,'2026_09_17_000400_add_company_created_at_index_to_subscriptions_table',1);
INSERT INTO migrations VALUES(75,'2026_09_19_000000_allow_template_less_render_sources',1);
INSERT INTO migrations VALUES(76,'2026_09_20_000000_add_output_dimensions_to_creative_package_items_table',1);
INSERT INTO migrations VALUES(77,'2026_09_20_000500_add_reconciliation_index_to_creative_packages_table',1);
INSERT INTO migrations VALUES(78,'2026_09_22_000100_add_diagnostic_cleanup_index_to_creative_package_items_table',1);
INSERT INTO migrations VALUES(79,'2026_09_22_000200_add_uuid_unique_index_to_creative_packages_table',1);
INSERT INTO migrations VALUES(80,'2026_09_22_000300_drop_unread_creative_package_indexes',1);
INSERT INTO migrations VALUES(81,'2026_11_16_000000_remove_billing',1);
INSERT INTO migrations VALUES(82,'2026_11_16_000100_add_is_owner_to_users_table',1);
INSERT INTO migrations VALUES(83,'2026_11_16_000400_create_instance_settings_table',1);
INSERT INTO migrations VALUES(84,'2026_11_16_000410_add_deactivated_at_to_users_table',1);
INSERT INTO migrations VALUES(85,'2026_11_18_000000_add_webhook_secret_to_companies_table',1);
INSERT INTO migrations VALUES(86,'2026_11_25_000000_add_company_role_to_users_table',1);
COMMIT;
