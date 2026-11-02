-- Billing/Sales + Timesheet + Client portal : schema for LIVE (run once in phpMyAdmin SQL tab)
-- Creates new tables, adds columns to users & projects, and records the 3 migrations.
SET FOREIGN_KEY_CHECKS=0;

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_taxes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `rate` decimal(8,2) NOT NULL DEFAULT 0.00,
  `legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_taxes_legacy_id_index` (`legacy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `contact` varchar(255) DEFAULT NULL,
  `tax_number` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `billing_name` varchar(255) DEFAULT NULL,
  `billing_phone` varchar(255) DEFAULT NULL,
  `billing_address` varchar(255) DEFAULT NULL,
  `billing_city` varchar(255) DEFAULT NULL,
  `billing_state` varchar(255) DEFAULT NULL,
  `billing_country` varchar(255) DEFAULT NULL,
  `billing_zip` varchar(255) DEFAULT NULL,
  `shipping_name` varchar(255) DEFAULT NULL,
  `shipping_phone` varchar(255) DEFAULT NULL,
  `shipping_address` varchar(255) DEFAULT NULL,
  `shipping_city` varchar(255) DEFAULT NULL,
  `shipping_state` varchar(255) DEFAULT NULL,
  `shipping_country` varchar(255) DEFAULT NULL,
  `shipping_zip` varchar(255) DEFAULT NULL,
  `legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_customers_created_by_foreign` (`created_by`),
  KEY `billing_customers_name_index` (`name`),
  KEY `billing_customers_legacy_id_index` (`legacy_id`),
  CONSTRAINT `billing_customers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'service',
  `sale_price` decimal(16,2) NOT NULL DEFAULT 0.00,
  `purchase_price` decimal(16,2) NOT NULL DEFAULT 0.00,
  `tax_id` bigint(20) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `hsn_sac` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_products_tax_id_foreign` (`tax_id`),
  KEY `billing_products_created_by_foreign` (`created_by`),
  KEY `billing_products_name_index` (`name`),
  KEY `billing_products_legacy_id_index` (`legacy_id`),
  CONSTRAINT `billing_products_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_products_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `billing_taxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `number` int(10) unsigned NOT NULL,
  `financial_year` varchar(9) NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `send_date` date DEFAULT NULL,
  `ref_number` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Draft',
  `notes` text DEFAULT NULL,
  `legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `place_of_supply` varchar(60) DEFAULT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billing_invoices_financial_year_number_unique` (`financial_year`,`number`),
  KEY `billing_invoices_created_by_foreign` (`created_by`),
  KEY `billing_invoices_customer_id_status_index` (`customer_id`,`status`),
  KEY `billing_invoices_legacy_id_index` (`legacy_id`),
  KEY `billing_invoices_project_id_foreign` (`project_id`),
  CONSTRAINT `billing_invoices_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_invoices_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `billing_customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `billing_invoices_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_invoice_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `price` decimal(16,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `tax_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `hsn_sac` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_invoice_items_product_id_foreign` (`product_id`),
  KEY `billing_invoice_items_tax_id_foreign` (`tax_id`),
  KEY `billing_invoice_items_invoice_id_index` (`invoice_id`),
  CONSTRAINT `billing_invoice_items_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `billing_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `billing_invoice_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `billing_products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_invoice_items_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `billing_taxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_invoice_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `method` varchar(255) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `recorded_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_invoice_payments_recorded_by_foreign` (`recorded_by`),
  KEY `billing_invoice_payments_invoice_id_index` (`invoice_id`),
  CONSTRAINT `billing_invoice_payments_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `billing_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `billing_invoice_payments_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_credit_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(16,2) NOT NULL,
  `date` date NOT NULL,
  `description` text DEFAULT NULL,
  `legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_credit_notes_invoice_id_foreign` (`invoice_id`),
  KEY `billing_credit_notes_customer_id_foreign` (`customer_id`),
  KEY `billing_credit_notes_created_by_foreign` (`created_by`),
  KEY `billing_credit_notes_legacy_id_index` (`legacy_id`),
  CONSTRAINT `billing_credit_notes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_credit_notes_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `billing_customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_credit_notes_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `billing_invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_revenues` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `payment_method` varchar(255) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_revenues_customer_id_foreign` (`customer_id`),
  KEY `billing_revenues_created_by_foreign` (`created_by`),
  KEY `billing_revenues_legacy_id_index` (`legacy_id`),
  CONSTRAINT `billing_revenues_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_revenues_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `billing_customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_estimates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `number` int(10) unsigned NOT NULL,
  `financial_year` varchar(9) NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `issue_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Draft',
  `converted_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `place_of_supply` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billing_estimates_financial_year_number_unique` (`financial_year`,`number`),
  KEY `billing_estimates_customer_id_foreign` (`customer_id`),
  KEY `billing_estimates_converted_invoice_id_foreign` (`converted_invoice_id`),
  KEY `billing_estimates_created_by_foreign` (`created_by`),
  KEY `billing_estimates_legacy_id_index` (`legacy_id`),
  CONSTRAINT `billing_estimates_converted_invoice_id_foreign` FOREIGN KEY (`converted_invoice_id`) REFERENCES `billing_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_estimates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_estimates_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `billing_customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_estimate_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `estimate_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `price` decimal(16,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `tax_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `hsn_sac` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_estimate_items_product_id_foreign` (`product_id`),
  KEY `billing_estimate_items_tax_id_foreign` (`tax_id`),
  KEY `billing_estimate_items_estimate_id_index` (`estimate_id`),
  CONSTRAINT `billing_estimate_items_estimate_id_foreign` FOREIGN KEY (`estimate_id`) REFERENCES `billing_estimates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `billing_estimate_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `billing_products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_estimate_items_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `billing_taxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `billing_customer_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `clients_billing_customer_id_foreign` (`billing_customer_id`),
  CONSTRAINT `clients_billing_customer_id_foreign` FOREIGN KEY (`billing_customer_id`) REFERENCES `billing_customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `timesheet_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `task_id` bigint(20) unsigned DEFAULT NULL,
  `employee_id` bigint(20) unsigned DEFAULT NULL,
  `developer_name` varchar(255) DEFAULT NULL,
  `work_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `billable_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `description` text NOT NULL,
  `status` varchar(40) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'manual',
  `task_time_entry_id` bigint(20) unsigned DEFAULT NULL,
  `import_key` varchar(64) DEFAULT NULL,
  `visible_to_client` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `timesheet_entries_task_time_entry_id_unique` (`task_time_entry_id`),
  UNIQUE KEY `timesheet_entries_import_key_unique` (`import_key`),
  KEY `timesheet_entries_task_id_foreign` (`task_id`),
  KEY `timesheet_entries_employee_id_foreign` (`employee_id`),
  KEY `timesheet_entries_created_by_foreign` (`created_by`),
  KEY `timesheet_entries_project_id_work_date_index` (`project_id`,`work_date`),
  CONSTRAINT `timesheet_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `timesheet_entries_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `attendance_employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `timesheet_entries_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `timesheet_entries_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `timesheet_entries_task_time_entry_id_foreign` FOREIGN KEY (`task_time_entry_id`) REFERENCES `task_time_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=235 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `timesheet_objections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `timesheet_entry_id` bigint(20) unsigned NOT NULL,
  `raised_by` bigint(20) unsigned DEFAULT NULL,
  `reason` text NOT NULL,
  `proposed_minutes` int(10) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `response` text DEFAULT NULL,
  `responded_by` bigint(20) unsigned DEFAULT NULL,
  `responded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `timesheet_objections_timesheet_entry_id_foreign` (`timesheet_entry_id`),
  KEY `timesheet_objections_raised_by_foreign` (`raised_by`),
  KEY `timesheet_objections_responded_by_foreign` (`responded_by`),
  CONSTRAINT `timesheet_objections_raised_by_foreign` FOREIGN KEY (`raised_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `timesheet_objections_responded_by_foreign` FOREIGN KEY (`responded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `timesheet_objections_timesheet_entry_id_foreign` FOREIGN KEY (`timesheet_entry_id`) REFERENCES `timesheet_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `timesheet_months` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `month` char(7) NOT NULL,
  `opening_remaining_minutes` int(11) NOT NULL DEFAULT 0,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `timesheet_months_project_id_month_unique` (`project_id`,`month`),
  KEY `timesheet_months_approved_by_foreign` (`approved_by`),
  KEY `timesheet_months_invoice_id_foreign` (`invoice_id`),
  CONSTRAINT `timesheet_months_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `timesheet_months_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `billing_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `timesheet_months_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Columns on existing tables
ALTER TABLE `users` ADD COLUMN `client_id` BIGINT UNSIGNED NULL AFTER `id`, ADD CONSTRAINT `users_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL;
ALTER TABLE `projects` ADD COLUMN `client_id` BIGINT UNSIGNED NULL, ADD COLUMN `hourly_rate` DECIMAL(12,2) NULL, ADD COLUMN `client_sees_developer` TINYINT(1) NOT NULL DEFAULT 1, ADD CONSTRAINT `projects_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL;

-- Mark migrations as run (so artisan migrate won't retry them)
SET @b := (SELECT COALESCE(MAX(batch),0)+1 FROM migrations);
INSERT INTO `migrations` (`migration`,`batch`) VALUES
  ('2026_10_30_000001_create_billing_tables', @b),
  ('2026_10_30_000002_add_gst_fields_to_billing', @b),
  ('2026_10_30_000003_create_client_portal_tables', @b);

SET FOREIGN_KEY_CHECKS=1;
