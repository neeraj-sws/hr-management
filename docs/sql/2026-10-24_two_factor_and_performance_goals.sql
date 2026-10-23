-- SWS HR update (24 Oct 2026): 2-step sign-in (OTP), Goals (KRA / OKR), self-review and 360 feedback.
-- Use this ONLY if you cannot run "php artisan migrate --force" on the server.
-- Run it once, in phpMyAdmin (select the HRMS database first) or the mysql command line.
-- Take a database backup (Export) before running it.

SET NAMES utf8mb4;

-- 1. Users: a person can turn on the e-mailed sign-in code for their own account.
ALTER TABLE `users`
  ADD COLUMN `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password`;

-- 2. Goals (KRA / OKR) with weight and progress.
CREATE TABLE `performance_goals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(10) NOT NULL DEFAULT 'kra',
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NULL,
  `target` VARCHAR(200) NULL,
  `weight` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `progress` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `starts_on` DATE NOT NULL,
  `ends_on` DATE NOT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `performance_goals_employee_id_ends_on_index` (`employee_id`, `ends_on`),
  KEY `performance_goals_created_by_foreign` (`created_by`),
  KEY `performance_goals_approved_by_foreign` (`approved_by`),
  CONSTRAINT `performance_goals_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `attendance_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_goals_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `performance_goals_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Progress updates on a goal.
CREATE TABLE `performance_goal_updates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `goal_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `progress` TINYINT UNSIGNED NOT NULL,
  `note` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `performance_goal_updates_goal_id_foreign` (`goal_id`),
  KEY `performance_goal_updates_user_id_foreign` (`user_id`),
  CONSTRAINT `performance_goal_updates_goal_id_foreign` FOREIGN KEY (`goal_id`) REFERENCES `performance_goals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_goal_updates_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Reviews: self-review, per-goal scores; the rating is set when the manager completes the review.
ALTER TABLE `performance_reviews`
  MODIFY `rating` TINYINT UNSIGNED NULL,
  ADD COLUMN `self_rating` TINYINT UNSIGNED NULL AFTER `rating`,
  ADD COLUMN `self_assessment` TEXT NULL AFTER `self_rating`,
  ADD COLUMN `self_submitted_at` TIMESTAMP NULL AFTER `self_assessment`,
  ADD COLUMN `goal_scores` JSON NULL AFTER `metrics`,
  ADD COLUMN `completed_at` TIMESTAMP NULL AFTER `goal_scores`;

-- 5. 360 feedback requests and answers.
CREATE TABLE `performance_feedback` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `review_id` BIGINT UNSIGNED NOT NULL,
  `giver_id` BIGINT UNSIGNED NOT NULL,
  `relationship` VARCHAR(20) NOT NULL DEFAULT 'peer',
  `rating` TINYINT UNSIGNED NULL,
  `strengths` TEXT NULL,
  `improvements` TEXT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'requested',
  `submitted_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `performance_feedback_review_id_giver_id_unique` (`review_id`, `giver_id`),
  KEY `performance_feedback_giver_id_foreign` (`giver_id`),
  CONSTRAINT `performance_feedback_review_id_foreign` FOREIGN KEY (`review_id`) REFERENCES `performance_reviews` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_feedback_giver_id_foreign` FOREIGN KEY (`giver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tell Laravel these two migrations are done, so "php artisan migrate" never runs them again.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_24_000001_add_two_factor_to_users', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_24_000002_create_performance_goals_and_feedback', MAX(`batch`) FROM `migrations`;
