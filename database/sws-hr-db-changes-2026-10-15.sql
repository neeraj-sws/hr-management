-- =====================================================================
-- SWS HR — database changes (MySQL / MariaDB), generated from the Laravel
-- migrations with `php artisan migrate --pretend` on a database at the
-- live schema level (everything up to 2026_10_11_000005).
--
-- BEFORE RUNNING
--   1. Take a full database backup.
--   2. See which migrations the live database already has:
--        SELECT migration FROM migrations WHERE migration LIKE '2026_10_1%' ORDER BY migration;
--   3. Run ONLY the sections (1–6) whose migration name is NOT in that list,
--      in order. Running a section twice fails with "Duplicate column" /
--      "Table already exists" — that only means it was already applied.
--
-- Easier alternative (does exactly the same, and only what is missing):
--        php artisan migrate --force
--
-- All changes are additive: no existing column or row is changed or removed
-- (except section 3's clean-up of notifications for deleted announcements).
-- =====================================================================

SET @batch := (SELECT IFNULL(MAX(batch), 0) + 1 FROM migrations);


-- ---------------------------------------------------------------------
-- 1) 2026_10_13_000001_add_leave_duration  (half-day / short leave)
-- ---------------------------------------------------------------------
ALTER TABLE `leave_requests` ADD `duration` VARCHAR(10) NOT NULL DEFAULT 'full' AFTER `ends_on`;

INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_13_000001_add_leave_duration', @batch);


-- ---------------------------------------------------------------------
-- 2) 2026_10_14_000001_create_holiday_rules  (holiday engine tables)
-- ---------------------------------------------------------------------
CREATE TABLE `holiday_rules` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(255) NOT NULL, `type` VARCHAR(20) NOT NULL, `weekday` TINYINT UNSIGNED NULL, `weeks` JSON NULL, `month` TINYINT UNSIGNED NULL, `day` TINYINT UNSIGNED NULL, `scope_type` VARCHAR(16) NOT NULL DEFAULT 'company', `scope_id` BIGINT UNSIGNED NULL, `effective_from` DATE NULL, `effective_until` DATE NULL, `is_active` TINYINT(1) NOT NULL DEFAULT '1', `created_by` BIGINT UNSIGNED NULL, `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `holiday_rules` ADD CONSTRAINT `holiday_rules_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `holiday_rules` ADD INDEX `holiday_rules_scope_type_scope_id_is_active_index`(`scope_type`, `scope_id`, `is_active`);

CREATE TABLE `employee_holidays` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `employee_id` BIGINT UNSIGNED NOT NULL, `date` DATE NOT NULL, `name` VARCHAR(255) NOT NULL DEFAULT 'Holiday', `source` VARCHAR(16) NOT NULL DEFAULT 'rule', `holiday_rule_id` BIGINT UNSIGNED NULL, `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `employee_holidays` ADD CONSTRAINT `employee_holidays_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `attendance_employees` (`id`) ON DELETE CASCADE;
ALTER TABLE `employee_holidays` ADD CONSTRAINT `employee_holidays_holiday_rule_id_foreign` FOREIGN KEY (`holiday_rule_id`) REFERENCES `holiday_rules` (`id`) ON DELETE SET NULL;
ALTER TABLE `employee_holidays` ADD UNIQUE `employee_holidays_employee_id_date_unique`(`employee_id`, `date`);
ALTER TABLE `employee_holidays` ADD INDEX `employee_holidays_date_index`(`date`);

CREATE TABLE `holiday_overrides` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `scope_type` VARCHAR(16) NOT NULL DEFAULT 'company', `scope_id` BIGINT UNSIGNED NULL, `original_date` DATE NOT NULL, `action` VARCHAR(12) NOT NULL, `new_date` DATE NULL, `name` VARCHAR(255) NULL, `reason` VARCHAR(255) NULL, `created_by` BIGINT UNSIGNED NULL, `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `holiday_overrides` ADD CONSTRAINT `holiday_overrides_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `holiday_overrides` ADD INDEX `holiday_overrides_scope_type_scope_id_index`(`scope_type`, `scope_id`);
ALTER TABLE `holiday_overrides` ADD INDEX `holiday_overrides_original_date_index`(`original_date`);

CREATE TABLE `comp_off_credits` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `employee_id` BIGINT UNSIGNED NOT NULL, `earned_on` DATE NOT NULL, `days` DECIMAL(4, 2) NOT NULL DEFAULT '1', `status` VARCHAR(12) NOT NULL DEFAULT 'available', `expires_on` DATE NULL, `used_leave_id` BIGINT UNSIGNED NULL, `note` VARCHAR(255) NULL, `created_by` BIGINT UNSIGNED NULL, `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `comp_off_credits` ADD CONSTRAINT `comp_off_credits_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `attendance_employees` (`id`) ON DELETE CASCADE;
ALTER TABLE `comp_off_credits` ADD CONSTRAINT `comp_off_credits_used_leave_id_foreign` FOREIGN KEY (`used_leave_id`) REFERENCES `leave_requests` (`id`) ON DELETE SET NULL;
ALTER TABLE `comp_off_credits` ADD CONSTRAINT `comp_off_credits_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `comp_off_credits` ADD UNIQUE `comp_off_credits_employee_id_earned_on_unique`(`employee_id`, `earned_on`);
ALTER TABLE `comp_off_credits` ADD INDEX `comp_off_credits_employee_id_status_index`(`employee_id`, `status`);

INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_14_000001_create_holiday_rules', @batch);


-- ---------------------------------------------------------------------
-- 3) 2026_10_15_000001_add_hrms_review_fixes
--    forced check-out, project-wise daily status, asset history,
--    offboarding cancellation, clean-up of deleted-announcement notifications
-- ---------------------------------------------------------------------
ALTER TABLE `attendance_sessions` ADD `forced_checkout_by` BIGINT UNSIGNED NULL;
ALTER TABLE `attendance_sessions` ADD CONSTRAINT `attendance_sessions_forced_checkout_by_foreign` FOREIGN KEY (`forced_checkout_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `attendance_sessions` ADD `forced_checkout_reason` VARCHAR(30) NULL;
ALTER TABLE `attendance_sessions` ADD `forced_checkout_note` VARCHAR(500) NULL;
ALTER TABLE `attendance_sessions` ADD `forced_checkout_at` TIMESTAMP NULL;

ALTER TABLE `daily_work_statuses` ADD `project_updates` JSON NULL;

CREATE TABLE `employee_asset_events` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `employee_asset_id` BIGINT UNSIGNED NOT NULL, `employee_id` BIGINT UNSIGNED NULL, `action` VARCHAR(30) NOT NULL, `happened_on` DATE NOT NULL, `notes` TEXT NULL, `recorded_by` BIGINT UNSIGNED NULL, `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `employee_asset_events` ADD CONSTRAINT `employee_asset_events_employee_asset_id_foreign` FOREIGN KEY (`employee_asset_id`) REFERENCES `employee_assets` (`id`) ON DELETE CASCADE;
ALTER TABLE `employee_asset_events` ADD CONSTRAINT `employee_asset_events_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `attendance_employees` (`id`) ON DELETE SET NULL;
ALTER TABLE `employee_asset_events` ADD CONSTRAINT `employee_asset_events_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `employee_asset_events` ADD INDEX `employee_asset_events_employee_id_action_index`(`employee_id`, `action`);

ALTER TABLE `employee_lifecycle_cases` ADD `cancelled_by` BIGINT UNSIGNED NULL;
ALTER TABLE `employee_lifecycle_cases` ADD CONSTRAINT `employee_lifecycle_cases_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `employee_lifecycle_cases` ADD `cancelled_at` TIMESTAMP NULL;
ALTER TABLE `employee_lifecycle_cases` ADD `cancel_reason` VARCHAR(1000) NULL;

-- Start each existing asset's history from what is already known.
INSERT INTO `employee_asset_events` (`employee_asset_id`, `employee_id`, `action`, `happened_on`, `notes`, `created_at`, `updated_at`)
SELECT `id`, `employee_id`, 'assigned', COALESCE(`assigned_on`, DATE(`created_at`), CURDATE()), NULL, NOW(), NOW() FROM `employee_assets`;
INSERT INTO `employee_asset_events` (`employee_asset_id`, `employee_id`, `action`, `happened_on`, `notes`, `created_at`, `updated_at`)
SELECT `id`, `employee_id`, `status`, COALESCE(`returned_on`, DATE(`updated_at`), CURDATE()), NULL, NOW(), NOW() FROM `employee_assets` WHERE `status` <> 'assigned';

-- Remove notifications that still point at announcements which were deleted.
DELETE FROM `notifications`
WHERE `data` LIKE '%"kind":"Announcement"%'
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.record_id')) AS UNSIGNED) NOT IN (SELECT `id` FROM `announcements`);

INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_15_000001_add_hrms_review_fixes', @batch);


-- ---------------------------------------------------------------------
-- 4) 2026_10_16_000001_add_attendance_selfies  (check-in / check-out selfie)
--    ** Without these two columns the new code cannot check anyone in. **
-- ---------------------------------------------------------------------
ALTER TABLE `attendance_sessions` ADD `check_in_photo_path` VARCHAR(255) NULL;
ALTER TABLE `attendance_sessions` ADD `check_out_photo_path` VARCHAR(255) NULL;

INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_16_000001_add_attendance_selfies', @batch);


-- ---------------------------------------------------------------------
-- 5) 2026_10_17_000001_add_task_progress_and_status_plans
-- ---------------------------------------------------------------------
ALTER TABLE `tasks` ADD `progress` TINYINT UNSIGNED NULL;
ALTER TABLE `daily_work_statuses` ADD `pending` TEXT NULL;
ALTER TABLE `daily_work_statuses` ADD `tomorrow_plan` TEXT NULL;

INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_17_000001_add_task_progress_and_status_plans', @batch);


-- ---------------------------------------------------------------------
-- 6) 2026_10_18_000001_add_leave_ledger_salary_kinds_and_attendance_ip
--    (leave ledger, recurring salary adjustments, check-in IP)
-- ---------------------------------------------------------------------
CREATE TABLE `leave_ledger_entries` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `employee_id` BIGINT UNSIGNED NOT NULL, `leave_type_id` BIGINT UNSIGNED NOT NULL, `year` SMALLINT UNSIGNED NOT NULL, `entry_type` VARCHAR(20) NOT NULL, `days` DECIMAL(8, 3) NOT NULL, `reason` VARCHAR(255) NULL, `reference_type` VARCHAR(255) NULL, `reference_id` BIGINT UNSIGNED NULL, `created_by` BIGINT UNSIGNED NULL, `created_at` TIMESTAMP NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `leave_ledger_entries` ADD CONSTRAINT `leave_ledger_entries_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `attendance_employees` (`id`) ON DELETE CASCADE;
ALTER TABLE `leave_ledger_entries` ADD CONSTRAINT `leave_ledger_entries_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE;
ALTER TABLE `leave_ledger_entries` ADD INDEX `leave_ledger_entries_reference_type_reference_id_index`(`reference_type`, `reference_id`);
ALTER TABLE `leave_ledger_entries` ADD CONSTRAINT `leave_ledger_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `leave_ledger_entries` ADD INDEX `leave_ledger_entries_employee_id_leave_type_id_year_index`(`employee_id`, `leave_type_id`, `year`);

ALTER TABLE `salary_adjustments` ADD `is_recurring` TINYINT(1) NOT NULL DEFAULT '0' AFTER `kind`;
ALTER TABLE `salary_adjustments` ADD `recurring_until` DATE NULL AFTER `is_recurring`;

ALTER TABLE `attendance_sessions` ADD `check_in_ip` VARCHAR(45) NULL;
ALTER TABLE `attendance_sessions` ADD `check_out_ip` VARCHAR(45) NULL;

-- Leave year starts in January for existing data (only if not set yet).
INSERT INTO `policy_rules` (`rule_key`, `category`, `name`, `configuration`, `is_active`, `effective_from`, `created_at`, `updated_at`)
SELECT 'leave.year', 'leave', 'Leave year', '{"start_month":1}', 1, NULL, NOW(), NOW() FROM DUAL
WHERE EXISTS (SELECT 1 FROM `leave_balances`) AND NOT EXISTS (SELECT 1 FROM `policy_rules` WHERE `rule_key` = 'leave.year');

INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_18_000001_add_leave_ledger_salary_kinds_and_attendance_ip', @batch);


-- After running: on the server run `php artisan optimize:clear`.
