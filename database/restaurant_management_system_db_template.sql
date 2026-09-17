
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `log_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `module` varchar(50) NOT NULL,
  `action` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_activitylogs_user_date` (`user_id`,`created_at`),
  KEY `idx_activitylogs_module` (`module`),
  CONSTRAINT `fk_activitylog_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `analytics_ai_insights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `analytics_ai_insights` (
  `insight_id` int(11) NOT NULL AUTO_INCREMENT,
  `tab` enum('sales','reservations','inventory','menu_performance') NOT NULL,
  `period_key` varchar(64) NOT NULL,
  `summary` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `based_on_count` int(11) NOT NULL DEFAULT 0,
  `generated_at` datetime NOT NULL,
  `generated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`insight_id`),
  KEY `idx_analyticsai_tab_period` (`tab`,`period_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `analytics_ai_insights` WRITE;
/*!40000 ALTER TABLE `analytics_ai_insights` DISABLE KEYS */;
/*!40000 ALTER TABLE `analytics_ai_insights` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `attendance_import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_import_batches` (
  `import_batch_id` int(11) NOT NULL AUTO_INCREMENT,
  `file_name` varchar(255) NOT NULL,
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `matched_count` int(11) NOT NULL DEFAULT 0,
  `unmatched_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('processing','completed','completed_with_errors','failed') NOT NULL DEFAULT 'processing',
  `imported_by` int(11) NOT NULL,
  `imported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`import_batch_id`),
  KEY `fk_attimport_user` (`imported_by`),
  CONSTRAINT `fk_attimport_user` FOREIGN KEY (`imported_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `attendance_import_batches` WRITE;
/*!40000 ALTER TABLE `attendance_import_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_import_batches` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `attendance_import_raw`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_import_raw` (
  `raw_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `import_batch_id` int(11) NOT NULL,
  `biometric_user_id` varchar(50) NOT NULL,
  `punch_datetime` datetime NOT NULL,
  `punch_type` enum('time_in','time_out','break_out','break_in','unknown') NOT NULL DEFAULT 'unknown',
  `matched_employee_id` int(11) DEFAULT NULL,
  `is_matched` tinyint(1) NOT NULL DEFAULT 0,
  `processed_into_attendance` tinyint(1) NOT NULL DEFAULT 0,
  `raw_line` varchar(500) DEFAULT NULL COMMENT 'Original CSV row, kept for troubleshooting',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`raw_id`),
  KEY `fk_rawpunch_batch` (`import_batch_id`),
  KEY `idx_rawpunch_employee` (`matched_employee_id`),
  CONSTRAINT `fk_rawpunch_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `attendance_import_batches` (`import_batch_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rawpunch_employee` FOREIGN KEY (`matched_employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `attendance_import_raw` WRITE;
/*!40000 ALTER TABLE `attendance_import_raw` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_import_raw` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `attendance_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_records` (
  `attendance_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `time_in` datetime DEFAULT NULL,
  `time_out` datetime DEFAULT NULL,
  `total_hours_worked` decimal(5,2) NOT NULL DEFAULT 0.00,
  `late_minutes` int(11) NOT NULL DEFAULT 0,
  `undertime_minutes` int(11) NOT NULL DEFAULT 0 COMMENT 'Minutes clocked out early vs scheduled time-out, past the grace period',
  `overtime_minutes` int(11) NOT NULL DEFAULT 0,
  `night_differential_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `status` enum('present','late','absent','on_leave','holiday','rest_day') NOT NULL DEFAULT 'present',
  `source` enum('manual','file_import','biometric_import','auto_absence_sweep') NOT NULL DEFAULT 'manual',
  `source_import_batch_id` int(11) DEFAULT NULL,
  `is_payroll_locked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Set to 1 once pulled into a payroll run, to prevent edits after payout',
  `remarks` varchar(255) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL COMMENT 'Who encoded/imported this row. NULL-ish meaning for biometric rows: the importing user.',
  `approved_by` int(11) DEFAULT NULL COMMENT 'Meaningful mainly for source=biometric_import (OT sign-off). For source=manual this is usually the same person as recorded_by.',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`attendance_id`),
  UNIQUE KEY `uq_employee_attendance_date` (`employee_id`,`attendance_date`),
  KEY `fk_attendance_schedule` (`schedule_id`),
  KEY `fk_attendance_import` (`source_import_batch_id`),
  KEY `fk_attendance_recorder` (`recorded_by`),
  KEY `fk_attendance_approver` (`approved_by`),
  KEY `idx_attendance_date` (`attendance_date`),
  CONSTRAINT `fk_attendance_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_attendance_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_import` FOREIGN KEY (`source_import_batch_id`) REFERENCES `attendance_import_batches` (`import_batch_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_attendance_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_attendance_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `employee_schedules` (`schedule_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `attendance_records` WRITE;
/*!40000 ALTER TABLE `attendance_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_records` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `bir_withholding_tax_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bir_withholding_tax_table` (
  `wtax_bracket_id` int(11) NOT NULL AUTO_INCREMENT,
  `pay_frequency` enum('daily','weekly','semi_monthly','monthly') NOT NULL,
  `taxable_income_from` decimal(12,2) NOT NULL,
  `taxable_income_to` decimal(12,2) DEFAULT NULL,
  `base_tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate_percent` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Applied to the portion of taxable income exceeding excess_over',
  `excess_over` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`wtax_bracket_id`),
  KEY `idx_wtax_lookup` (`pay_frequency`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `bir_withholding_tax_table` WRITE;
/*!40000 ALTER TABLE `bir_withholding_tax_table` DISABLE KEYS */;
INSERT INTO `bir_withholding_tax_table` VALUES (1,'monthly',0.00,20833.00,0.00,0.00,0.00,'2026-07-22 13:38:56'),(2,'monthly',20833.01,33332.00,0.00,15.00,20833.00,'2026-07-22 13:38:56'),(3,'monthly',33333.00,66666.00,1875.00,20.00,33333.00,'2026-07-22 13:38:56'),(4,'monthly',66667.00,166666.00,8541.80,25.00,66667.00,'2026-07-22 13:38:56'),(5,'monthly',166667.00,666666.00,33541.80,30.00,166667.00,'2026-07-22 13:38:56'),(6,'monthly',666667.00,NULL,183541.80,35.00,666667.00,'2026-07-22 13:38:56'),(7,'semi_monthly',0.00,10417.00,0.00,0.00,0.00,'2026-07-22 13:38:56'),(8,'semi_monthly',10417.01,16666.00,0.00,15.00,10417.00,'2026-07-22 13:38:56'),(9,'semi_monthly',16666.01,33332.00,937.50,20.00,16667.00,'2026-07-22 13:38:56'),(10,'semi_monthly',33332.01,83332.00,4270.70,25.00,33333.00,'2026-07-22 13:38:56'),(11,'semi_monthly',83332.01,333332.00,16770.70,30.00,83333.00,'2026-07-22 13:38:56'),(12,'semi_monthly',333333.00,NULL,91770.70,35.00,333333.00,'2026-07-22 13:38:56');
/*!40000 ALTER TABLE `bir_withholding_tax_table` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `cash_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cash_balances` (
  `shift_id` int(11) NOT NULL AUTO_INCREMENT,
  `cashier_id` int(11) NOT NULL,
  `opening_cash` decimal(12,2) NOT NULL DEFAULT 0.00,
  `opened_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_sales` decimal(12,2) DEFAULT NULL,
  `gcash_sales` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'GCash portion of total_sales for this shift, frozen at close time same as total_sales -- never touches the physical drawer, so not part of expected_cash/counted_cash/variance',
  `expected_cash` decimal(12,2) DEFAULT NULL,
  `counted_cash` decimal(12,2) DEFAULT NULL,
  `variance` decimal(12,2) DEFAULT NULL,
  `closing_notes` varchar(255) DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  PRIMARY KEY (`shift_id`),
  KEY `fk_cashiershift_cashier` (`cashier_id`),
  KEY `idx_cashiershift_status` (`status`),
  CONSTRAINT `fk_cashiershift_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `cash_balances` WRITE;
/*!40000 ALTER TABLE `cash_balances` DISABLE KEYS */;
/*!40000 ALTER TABLE `cash_balances` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `data_export_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `data_export_logs` (
  `export_id` int(11) NOT NULL AUTO_INCREMENT,
  `module` varchar(50) NOT NULL,
  `export_format` enum('csv','xlsx','pdf','json') NOT NULL,
  `filters_applied` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`filters_applied`)),
  `row_count` int(11) DEFAULT NULL,
  `exported_by` int(11) NOT NULL,
  `status` enum('completed','failed') NOT NULL DEFAULT 'completed',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`export_id`),
  KEY `fk_export_user` (`exported_by`),
  KEY `idx_exportlogs_module_date` (`module`,`created_at`),
  CONSTRAINT `fk_export_user` FOREIGN KEY (`exported_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `data_export_logs` WRITE;
/*!40000 ALTER TABLE `data_export_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `data_export_logs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `demand_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `demand_adjustments` (
  `adjustment_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `menu_item_id` int(11) DEFAULT NULL COMMENT 'Scope: one dish. NULL means the adjustment applies at ingredient level.',
  `inventory_item_id` int(11) DEFAULT NULL,
  `delta_pct` decimal(6,2) NOT NULL COMMENT 'Signed percentage applied to the FORECAST, never to the committed floor. Capped by trend_min_delta / trend_max_delta.',
  `reason` varchar(255) NOT NULL,
  `source` enum('trend_setter','manual') NOT NULL DEFAULT 'trend_setter',
  `effective_from` date NOT NULL,
  `effective_to` date NOT NULL COMMENT 'Hard expiry (trend_hold_days) unless a later forecast run supersedes it first.',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`adjustment_id`),
  KEY `idx_da_active` (`is_active`,`effective_from`,`effective_to`),
  KEY `idx_da_menu` (`menu_item_id`),
  KEY `idx_da_inv` (`inventory_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stage 5 reconciliation: signed deltas layered on the forecast. Empty until Trend Setter runs.';
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `demand_adjustments` WRITE;
/*!40000 ALTER TABLE `demand_adjustments` DISABLE KEYS */;
/*!40000 ALTER TABLE `demand_adjustments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL AUTO_INCREMENT,
  `department_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`department_id`),
  UNIQUE KEY `uq_department_name` (`department_name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (5,'Front of the house',1,'2026-07-23 07:07:09'),(6,'Back of the house',1,'2026-07-23 07:18:04'),(10,'Management',1,'2026-09-14 04:53:47');
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `discount_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `discount_types` (
  `discount_type_id` int(11) NOT NULL AUTO_INCREMENT,
  `discount_name` varchar(50) NOT NULL,
  `discount_kind` enum('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_vat_exempt` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`discount_type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `discount_types` WRITE;
/*!40000 ALTER TABLE `discount_types` DISABLE KEYS */;
INSERT INTO `discount_types` VALUES (1,'PWD','percentage',20.00,1,1,'2026-07-19 16:05:18','2026-07-19 16:05:18'),(2,'Senior Citizen','percentage',20.00,1,1,'2026-07-20 16:01:11','2026-07-20 16:01:11');
/*!40000 ALTER TABLE `discount_types` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `employee_biometric_ids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_biometric_ids` (
  `biometric_link_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `biometric_user_id` varchar(50) NOT NULL COMMENT 'The ID/card number as it appears in the device export/CSV',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`biometric_link_id`),
  UNIQUE KEY `uq_biometric_user` (`biometric_user_id`),
  KEY `fk_biolink_employee` (`employee_id`),
  CONSTRAINT `fk_biolink_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `employee_biometric_ids` WRITE;
/*!40000 ALTER TABLE `employee_biometric_ids` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_biometric_ids` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `employee_leave_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_leave_balances` (
  `leave_balance_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `year` year(4) NOT NULL,
  `days_entitled` decimal(5,2) NOT NULL DEFAULT 0.00,
  `days_used` decimal(5,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`leave_balance_id`),
  UNIQUE KEY `uq_employee_leavetype_year` (`employee_id`,`leave_type_id`,`year`),
  KEY `fk_balance_leavetype` (`leave_type_id`),
  CONSTRAINT `fk_balance_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_balance_leavetype` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`leave_type_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `employee_leave_balances` WRITE;
/*!40000 ALTER TABLE `employee_leave_balances` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_leave_balances` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `employee_leave_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_leave_records` (
  `leave_record_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_days` decimal(5,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `cancelled_reason` varchar(255) DEFAULT NULL,
  `status` enum('approved','cancelled') NOT NULL DEFAULT 'approved' COMMENT 'Approval is implicit on insert -- only a manager can encode this row',
  `recorded_by` int(11) DEFAULT NULL COMMENT 'Manager/admin who approved and encoded this leave',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`leave_record_id`),
  KEY `fk_leave_type` (`leave_type_id`),
  KEY `fk_leave_recorder` (`recorded_by`),
  KEY `idx_leave_employee` (`employee_id`),
  KEY `idx_leave_dates` (`start_date`,`end_date`),
  CONSTRAINT `fk_leave_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leave_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leave_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`leave_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `employee_leave_records` WRITE;
/*!40000 ALTER TABLE `employee_leave_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_leave_records` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `employee_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_schedules` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `schedule_date` date NOT NULL,
  `scheduled_time_in` time NOT NULL,
  `scheduled_time_out` time NOT NULL,
  `is_rest_day` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('scheduled','completed','cancelled','on_leave') NOT NULL DEFAULT 'scheduled',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`schedule_id`),
  UNIQUE KEY `uq_employee_schedule_date` (`employee_id`,`schedule_date`),
  KEY `fk_schedule_shift` (`shift_id`),
  KEY `idx_schedule_date` (`schedule_date`),
  KEY `fk_schedule_creator` (`created_by`),
  CONSTRAINT `fk_schedule_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_schedule_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_schedule_shift` FOREIGN KEY (`shift_id`) REFERENCES `shift_templates` (`shift_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `employee_schedules` WRITE;
/*!40000 ALTER TABLE `employee_schedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_schedules` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employees` (
  `employee_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_number` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `civil_status` enum('single','married','widowed','separated','other') DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `position_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `employment_type` enum('regular','probationary') NOT NULL DEFAULT 'probationary',
  `date_hired` date NOT NULL,
  `date_regularized` date DEFAULT NULL,
  `employment_status` enum('active','suspended','resigned','terminated') NOT NULL DEFAULT 'active',
  `date_separated` date DEFAULT NULL,
  `separation_reason` varchar(255) DEFAULT NULL,
  `shift_id` int(11) DEFAULT NULL COMMENT 'Shift worked on every work day, drives automatic employee_schedules generation',
  `work_days` set('monday','tuesday','wednesday','thursday','friday','saturday','sunday') DEFAULT NULL COMMENT 'Days this employee works, every other day is a rest day',
  `salary_type` enum('monthly','daily') NOT NULL DEFAULT 'monthly',
  `basic_rate` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Hourly rate if salary_type=hourly, monthly rate if salary_type=monthly',
  `pay_frequency` enum('semi_monthly','monthly') NOT NULL DEFAULT 'semi_monthly',
  `is_minimum_wage_earner` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'RA 9504: statutory minimum wage earner, exempt from income tax on basic wage, holiday pay, overtime, night differential and hazard pay',
  `sss_number` varchar(20) DEFAULT NULL,
  `philhealth_number` varchar(20) DEFAULT NULL,
  `pagibig_number` varchar(20) DEFAULT NULL,
  `tin_number` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `uq_employee_number` (`employee_number`),
  KEY `fk_employee_position` (`position_id`),
  KEY `fk_employee_department` (`department_id`),
  KEY `idx_employee_status` (`employment_status`),
  KEY `fk_employee_shift` (`shift_id`),
  CONSTRAINT `fk_employee_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_employee_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`position_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_employee_shift` FOREIGN KEY (`shift_id`) REFERENCES `shift_templates` (`shift_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feedback` (
  `feedback_id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  `moderation_status` enum('pending','approved','masked','flagged','blocked') NOT NULL DEFAULT 'pending',
  `moderation_reason` text DEFAULT NULL,
  `flagged_terms` text DEFAULT NULL,
  `moderation_scores` text DEFAULT NULL,
  `toxicity_severity` enum('none','mild','moderate','severe','crisis') DEFAULT NULL,
  `moderation_confidence` decimal(3,2) DEFAULT NULL,
  `moderated_at` datetime DEFAULT NULL,
  `ai_provider` varchar(20) DEFAULT NULL,
  `ai_model` varchar(50) DEFAULT NULL,
  `sentiment` enum('positive','neutral','negative') DEFAULT NULL,
  `sentiment_confidence` decimal(3,2) DEFAULT NULL,
  `urgency` enum('routine','elevated','urgent') DEFAULT NULL,
  `sentiment_summary` text DEFAULT NULL,
  `recommendation` varchar(500) DEFAULT NULL,
  `topics` text DEFAULT NULL,
  `sentiment_analyzed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`feedback_id`),
  KEY `idx_feedback_customer` (`customer_id`),
  CONSTRAINT `fk_feedback_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `feedback` WRITE;
/*!40000 ALTER TABLE `feedback` DISABLE KEYS */;
/*!40000 ALTER TABLE `feedback` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `feedback_ai_insights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feedback_ai_insights` (
  `insight_id` int(11) NOT NULL AUTO_INCREMENT,
  `period_month` varchar(7) DEFAULT NULL COMMENT 'NULL = all-time snapshot; YYYY-MM = scoped to that month',
  `overall_mood` enum('positive','neutral','negative') DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `sentiment_insights` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `recommended_actions` text DEFAULT NULL,
  `based_on_count` int(11) NOT NULL DEFAULT 0,
  `generated_at` datetime NOT NULL,
  `generated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`insight_id`),
  KEY `idx_insights_period` (`period_month`,`insight_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `feedback_ai_insights` WRITE;
/*!40000 ALTER TABLE `feedback_ai_insights` DISABLE KEYS */;
/*!40000 ALTER TABLE `feedback_ai_insights` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `forecast_accuracy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `forecast_accuracy` (
  `accuracy_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) DEFAULT NULL,
  `forecast_domain` enum('menu_item_sales','ingredient_demand') NOT NULL,
  `entity_id` int(11) DEFAULT NULL COMMENT 'NULL = the whole restaurant, pooled. The forecast is one series of daily order counts, so a pooled score has no single entity.',
  `horizon_days` int(11) NOT NULL,
  `lag_days` int(11) DEFAULT NULL COMMENT 'NULL for a rolling-origin backtest, which scores a whole horizon rather than one lag.',
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `wape_pct` decimal(6,2) DEFAULT NULL COMMENT 'ingredient_demand rows: actuals are BOM-exploded from realized order_items, same basis as the forecast -- never inventory_transactions',
  `baseline_wape_pct` decimal(6,2) DEFAULT NULL,
  `beats_baseline` tinyint(1) DEFAULT NULL,
  `coverage_pct` decimal(5,2) DEFAULT NULL,
  `bias_pct` decimal(6,2) DEFAULT NULL,
  `reconciled_count` int(11) NOT NULL DEFAULT 0,
  `fold_count` int(11) NOT NULL DEFAULT 1 COMMENT 'rolling-origin backtest folds -- always shown alongside the pct',
  `computed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`accuracy_id`),
  UNIQUE KEY `uq_forecastaccuracy_scope` (`forecast_domain`,`entity_id`,`horizon_days`,`lag_days`),
  KEY `fk_forecastaccuracy_run` (`run_id`),
  CONSTRAINT `fk_forecastaccuracy_run` FOREIGN KEY (`run_id`) REFERENCES `forecast_runs` (`run_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `forecast_accuracy` WRITE;
/*!40000 ALTER TABLE `forecast_accuracy` DISABLE KEYS */;
/*!40000 ALTER TABLE `forecast_accuracy` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `forecast_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `forecast_runs` (
  `run_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `run_type` enum('sales_forecast','ingredient_policy_sweep') NOT NULL,
  `status` enum('running','completed','partial','failed') NOT NULL DEFAULT 'running',
  `idempotency_key` varchar(150) NOT NULL,
  `params_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`params_json`)),
  `engine_version` varchar(100) DEFAULT NULL COMMENT 'from Python service-reported package versions, not a hardcoded default',
  `error_message` text DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `finished_at` timestamp NULL DEFAULT NULL,
  `started_by` int(11) DEFAULT NULL COMMENT 'NULL = automatic sweep; set = a manual Generate/Retry click',
  PRIMARY KEY (`run_id`),
  UNIQUE KEY `uq_forecastruns_idempotency` (`idempotency_key`),
  KEY `idx_forecastruns_type_status` (`run_type`,`status`),
  KEY `fk_forecastruns_startedby` (`started_by`),
  CONSTRAINT `fk_forecastruns_startedby` FOREIGN KEY (`started_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `forecast_runs` WRITE;
/*!40000 ALTER TABLE `forecast_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `forecast_runs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `holidays` (
  `holiday_id` int(11) NOT NULL AUTO_INCREMENT,
  `holiday_date` date NOT NULL,
  `holiday_name` varchar(100) NOT NULL,
  `holiday_type` enum('regular','special') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`holiday_id`),
  UNIQUE KEY `uq_holiday_date` (`holiday_date`)
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `holidays` WRITE;
/*!40000 ALTER TABLE `holidays` DISABLE KEYS */;
INSERT INTO `holidays` VALUES (14,'2026-01-01','New Year\'s Day','regular',1,'2026-08-21 08:29:22'),(15,'2026-03-20','Eid\'l Fitr (Feast of Ramadhan)','regular',1,'2026-08-21 08:29:22'),(16,'2026-04-02','Maundy Thursday','regular',1,'2026-08-21 08:29:22'),(17,'2026-04-03','Good Friday','regular',1,'2026-08-21 08:29:22'),(18,'2026-04-09','Araw ng Kagitingan','regular',1,'2026-08-21 08:29:22'),(19,'2026-05-01','Labor Day','regular',1,'2026-08-21 08:29:22'),(20,'2026-05-27','Eid\'l Adha (Feast of Sacrifice)','regular',1,'2026-08-21 08:29:22'),(21,'2026-06-12','Independence Day','regular',1,'2026-08-21 08:29:22'),(22,'2026-08-31','National Heroes Day','regular',1,'2026-08-21 08:29:22'),(23,'2026-11-30','Bonifacio Day','regular',1,'2026-08-21 08:29:22'),(24,'2026-12-25','Christmas Day','regular',1,'2026-08-21 08:29:22'),(25,'2026-12-30','Rizal Day','regular',1,'2026-08-21 08:29:22'),(26,'2026-02-17','Chinese New Year','special',1,'2026-08-21 08:29:22'),(27,'2026-04-04','Black Saturday','special',1,'2026-08-21 08:29:22'),(28,'2026-08-21','Ninoy Aquino Day','special',1,'2026-08-21 08:29:22'),(29,'2026-11-01','All Saints\' Day','special',1,'2026-08-21 08:29:22'),(30,'2026-11-02','All Souls\' Day','special',0,'2026-08-21 08:29:22'),(31,'2026-12-08','Feast of the Immaculate Conception of Mary','special',1,'2026-08-21 08:29:22'),(32,'2026-12-24','Christmas Eve','special',1,'2026-08-21 08:29:22'),(33,'2026-12-31','Last Day of the Year','special',1,'2026-08-21 08:29:22'),(35,'2024-01-01','New Year\'s Day','regular',1,'2026-09-03 06:28:22'),(36,'2024-02-09','Additional special (non-working) day','special',1,'2026-09-03 06:28:22'),(37,'2024-02-10','Chinese New Year','special',1,'2026-09-03 06:28:22'),(38,'2024-03-28','Maundy Thursday','regular',1,'2026-09-03 06:28:22'),(39,'2024-03-29','Good Friday','regular',1,'2026-09-03 06:28:22'),(40,'2024-03-30','Black Saturday','special',1,'2026-09-03 06:28:22'),(41,'2024-04-09','Araw ng Kagitingan','regular',1,'2026-09-03 06:28:22'),(42,'2024-04-10','Eid\'l Fitr','regular',1,'2026-09-03 06:28:22'),(43,'2024-05-01','Labor Day','regular',1,'2026-09-03 06:28:22'),(44,'2024-06-12','Independence Day','regular',1,'2026-09-03 06:28:22'),(45,'2024-06-17','Eid\'l Adha','regular',1,'2026-09-03 06:28:22'),(46,'2024-08-23','Ninoy Aquino Day','special',1,'2026-09-03 06:28:22'),(47,'2024-08-26','National Heroes Day','regular',1,'2026-09-03 06:28:22'),(48,'2024-11-01','All Saints\' Day','special',1,'2026-09-03 06:28:22'),(49,'2024-11-02','Additional special (non-working) day','special',1,'2026-09-03 06:28:22'),(50,'2024-11-30','Bonifacio Day','regular',1,'2026-09-03 06:28:22'),(51,'2024-12-08','Feast of the Immaculate Conception of Mary','special',1,'2026-09-03 06:28:22'),(52,'2024-12-24','Additional special (non-working) day','special',1,'2026-09-03 06:28:22'),(53,'2024-12-25','Christmas Day','regular',1,'2026-09-03 06:28:22'),(54,'2024-12-30','Rizal Day','regular',1,'2026-09-03 06:28:22'),(55,'2024-12-31','Last Day of the Year','special',1,'2026-09-03 06:28:22'),(56,'2025-01-01','New Year\'s Day','regular',1,'2026-09-03 06:28:22'),(57,'2025-01-29','Chinese New Year','special',1,'2026-09-03 06:28:22'),(58,'2025-04-01','Eid\'l Fitr','regular',1,'2026-09-03 06:28:22'),(59,'2025-04-09','Araw ng Kagitingan','regular',1,'2026-09-03 06:28:22'),(60,'2025-04-17','Maundy Thursday','regular',1,'2026-09-03 06:28:22'),(61,'2025-04-18','Good Friday','regular',1,'2026-09-03 06:28:22'),(62,'2025-04-19','Black Saturday','special',1,'2026-09-03 06:28:22'),(63,'2025-05-01','Labor Day','regular',1,'2026-09-03 06:28:22'),(64,'2025-05-12','Elections special (non-working) day','special',1,'2026-09-03 06:28:22'),(65,'2025-06-06','Eid\'l Adha','regular',1,'2026-09-03 06:28:22'),(66,'2025-06-12','Independence Day','regular',1,'2026-09-03 06:28:22'),(67,'2025-07-27','Additional special (non-working) day','special',1,'2026-09-03 06:28:22'),(68,'2025-08-21','Ninoy Aquino Day','special',1,'2026-09-03 06:28:22'),(69,'2025-08-25','National Heroes Day','regular',1,'2026-09-03 06:28:22'),(70,'2025-10-31','All Saints\' Day Eve','special',1,'2026-09-03 06:28:22'),(71,'2025-11-01','All Saints\' Day','special',1,'2026-09-03 06:28:22'),(72,'2025-11-30','Bonifacio Day','regular',1,'2026-09-03 06:28:22'),(73,'2025-12-08','Feast of the Immaculate Conception of Mary','special',1,'2026-09-03 06:28:22'),(74,'2025-12-24','Christmas Eve','special',1,'2026-09-03 06:28:22'),(75,'2025-12-25','Christmas Day','regular',1,'2026-09-03 06:28:22'),(76,'2025-12-30','Rizal Day','regular',1,'2026-09-03 06:28:22'),(77,'2025-12-31','Last Day of the Year','special',1,'2026-09-03 06:28:22');
/*!40000 ALTER TABLE `holidays` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `ingredient_demand_contributions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ingredient_demand_contributions` (
  `contribution_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) NOT NULL,
  `item_id` int(11) NOT NULL,
  `consumption_date` date NOT NULL,
  `menu_item_id` int(11) NOT NULL,
  `source_type` enum('recipe','packaging') NOT NULL,
  `quantity_sold` decimal(10,3) NOT NULL,
  `per_unit_required` decimal(12,6) NOT NULL,
  `contributed_qty` decimal(10,3) NOT NULL,
  PRIMARY KEY (`contribution_id`),
  UNIQUE KEY `uq_ingcontrib_run_item_date_menuitem` (`run_id`,`item_id`,`consumption_date`,`menu_item_id`,`source_type`),
  KEY `idx_ingcontrib_menuitem` (`menu_item_id`),
  KEY `fk_ingcontrib_item` (`item_id`),
  CONSTRAINT `fk_ingcontrib_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  CONSTRAINT `fk_ingcontrib_menuitem` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`item_id`),
  CONSTRAINT `fk_ingcontrib_run` FOREIGN KEY (`run_id`) REFERENCES `forecast_runs` (`run_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `ingredient_demand_contributions` WRITE;
/*!40000 ALTER TABLE `ingredient_demand_contributions` DISABLE KEYS */;
/*!40000 ALTER TABLE `ingredient_demand_contributions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `ingredient_demand_forecast`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ingredient_demand_forecast` (
  `forecast_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) NOT NULL,
  `item_id` int(11) NOT NULL,
  `forecast_date` date NOT NULL,
  `predicted_qty` decimal(10,3) NOT NULL,
  `yhat_lower` decimal(10,3) DEFAULT NULL,
  `yhat_upper` decimal(10,3) DEFAULT NULL,
  `committed_floor_qty` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'BOM-exploded reservation_advance_orders -- a genuine floor',
  `adjustment_delta_qty` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'signed; additive, never blended via max(); precedence-resolved (scoped beats blanket)',
  `overlaid_qty` decimal(10,3) NOT NULL COMMENT 'GREATEST(0, max(predicted_qty, committed_floor_qty) + adjustment_delta_qty)',
  `model_used` enum('prophet','trailing_average') NOT NULL COMMENT 'Prophet is the only model ever selected by the sweep itself; when the service is unreachable the item is gated (skipped_gated), never given a substitute model',
  `demand_pattern` enum('fast','medium','slow') NOT NULL,
  PRIMARY KEY (`forecast_id`),
  UNIQUE KEY `uq_ingdemandforecast_run_item_date` (`run_id`,`item_id`,`forecast_date`),
  KEY `idx_ingdemandforecast_item_date` (`item_id`,`forecast_date`),
  CONSTRAINT `fk_ingdemandforecast_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  CONSTRAINT `fk_ingdemandforecast_run` FOREIGN KEY (`run_id`) REFERENCES `forecast_runs` (`run_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `ingredient_demand_forecast` WRITE;
/*!40000 ALTER TABLE `ingredient_demand_forecast` DISABLE KEYS */;
/*!40000 ALTER TABLE `ingredient_demand_forecast` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `inventory_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_batches` (
  `batch_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `po_item_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `batch_number` varchar(50) DEFAULT NULL,
  `quantity_received` decimal(10,3) NOT NULL,
  `quantity_remaining` decimal(10,3) NOT NULL,
  `unit_cost` decimal(10,2) NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `received_date` date NOT NULL,
  `received_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`batch_id`),
  KEY `fk_batch_poitem` (`po_item_id`),
  KEY `fk_batch_supplier` (`supplier_id`),
  KEY `fk_batch_receivedby` (`received_by`),
  KEY `idx_batches_fifo` (`item_id`,`received_date`,`batch_id`),
  CONSTRAINT `fk_batch_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  CONSTRAINT `fk_batch_poitem` FOREIGN KEY (`po_item_id`) REFERENCES `purchase_order_items` (`po_item_id`),
  CONSTRAINT `fk_batch_receivedby` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_batch_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `inventory_batches` WRITE;
/*!40000 ALTER TABLE `inventory_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_batches` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=CURRENT_USER*/ /*!50003 TRIGGER trg_update_last_purchase_cost
AFTER INSERT ON inventory_batches
FOR EACH ROW
BEGIN
    UPDATE inventory_items
    SET last_purchase_cost = NEW.unit_cost
    WHERE item_id = NEW.item_id;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `inventory_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `inventory_categories` WRITE;
/*!40000 ALTER TABLE `inventory_categories` DISABLE KEYS */;
INSERT INTO `inventory_categories` VALUES (3,'Meat'),(6,'Packaging'),(15,'Poultry'),(16,'Seafood'),(17,'Produce'),(18,'Condiments & Sauces'),(19,'Dairy & Eggs'),(20,'Grains & Starches'),(21,'Bakery'),(22,'Beverages');
/*!40000 ALTER TABLE `inventory_categories` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `inventory_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `base_unit_id` int(11) NOT NULL,
  `preferred_supplier_id` int(11) DEFAULT NULL,
  `reorder_level` decimal(10,3) NOT NULL DEFAULT 0.000,
  `critical_level` decimal(12,3) DEFAULT NULL COMMENT 'Urgent restock threshold, lower than reorder_level',
  `last_purchase_cost` decimal(10,2) DEFAULT NULL,
  `is_vat_exempt` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `lead_time_days` int(11) DEFAULT NULL COMMENT 'Days between placing a PO and receiving it for this item',
  `lead_time_demand_qty` decimal(10,3) DEFAULT NULL COMMENT 'sum(yhat) over lead time from the last forecast run, stored for audit',
  `safety_stock_qty` decimal(10,3) DEFAULT NULL COMMENT 'Derived: reorder_level - lead_time_demand_qty, stored for audit only',
  `review_period_demand_qty` decimal(10,3) DEFAULT NULL COMMENT 'sum(yhat) over the review period from the last forecast run, stored for audit',
  `demand_pattern` enum('fast','medium','slow') DEFAULT NULL COMMENT 'Velocity classification from the last forecast run',
  PRIMARY KEY (`item_id`),
  KEY `fk_invitem_baseunit` (`base_unit_id`),
  KEY `idx_invitems_category` (`category_id`),
  KEY `fk_invitem_preferredsupplier` (`preferred_supplier_id`),
  CONSTRAINT `fk_invitem_baseunit` FOREIGN KEY (`base_unit_id`) REFERENCES `unit_of_measures` (`unit_id`),
  CONSTRAINT `fk_invitem_category` FOREIGN KEY (`category_id`) REFERENCES `inventory_categories` (`category_id`),
  CONSTRAINT `fk_invitem_preferredsupplier` FOREIGN KEY (`preferred_supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_critical_le_reorder` CHECK (`critical_level` is null or `critical_level` <= `reorder_level`)
) ENGINE=InnoDB AUTO_INCREMENT=111 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `inventory_items` WRITE;
/*!40000 ALTER TABLE `inventory_items` DISABLE KEYS */;
INSERT INTO `inventory_items` VALUES (3,3,'Pork',1,NULL,10.700,4.500,280.00,0,1,'2026-07-17 09:50:39','2026-09-17 17:48:40',1,2.250,4.500,72.196,'fast'),(6,6,'Tupperware',8,NULL,10.000,10.000,10.00,1,1,'2026-07-17 15:32:37','2026-09-17 17:48:40',3,0.000,10.000,12.600,'slow'),(59,3,'Beef',1,NULL,5.300,2.000,380.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,1.750,2.000,34.368,'fast'),(60,3,'Pork Ribs',1,NULL,1.750,1.000,260.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.500,1.000,9.144,'fast'),(61,3,'Pork Leg (Pata)',1,NULL,2.800,1.000,220.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.600,1.000,14.869,'fast'),(62,3,'Longganisa',1,NULL,1.300,1.000,200.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.100,1.000,3.016,'medium'),(63,15,'Chicken',1,NULL,4.400,1.500,190.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,1.400,1.500,27.418,'fast'),(64,16,'Squid',1,NULL,2.300,1.000,280.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,1.100,1.000,20.963,'fast'),(65,16,'Mussels (Tahong)',1,NULL,2.000,1.000,90.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,1.500,1.000,14.284,'fast'),(66,16,'Tanigue',1,NULL,1.600,1.000,380.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.600,1.000,9.662,'fast'),(67,16,'Pampano',1,NULL,1.900,1.000,320.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.600,1.000,17.068,'fast'),(68,16,'Oysters (Talaba)',1,NULL,1.500,0.500,150.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.500,0.500,10.854,'fast'),(69,16,'Milkfish (Bangus)',1,NULL,2.000,1.000,160.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.750,1.000,12.389,'fast'),(70,16,'Shrimp',1,NULL,1.700,1.000,350.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.400,1.000,8.265,'fast'),(71,17,'Garlic',1,NULL,0.710,0.500,140.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.130,0.500,2.647,'fast'),(72,17,'Onion',1,NULL,2.050,1.000,90.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.510,1.000,11.908,'fast'),(73,17,'Tomato',1,NULL,1.200,1.000,70.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.200,1.000,2.818,'medium'),(74,17,'Ginger',1,NULL,1.030,1.000,110.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.015,1.000,0.432,'medium'),(75,17,'Chili (Sili)',1,NULL,0.555,0.500,150.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.015,0.500,0.538,'fast'),(76,17,'Eggplant',1,NULL,1.060,0.500,60.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.530,0.500,7.967,'fast'),(77,17,'Bottle Gourd (Upo)',1,NULL,0.609,0.409,50.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.200,0.409,4.511,'medium'),(78,17,'Corn',1,NULL,0.700,0.500,50.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,0.100,0.500,1.967,'medium'),(79,17,'Calamansi',8,NULL,82.000,33.000,2.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,21.000,33.000,464.368,'fast'),(80,17,'Banana',8,NULL,2.000,1.000,3.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,1.000,1.000,11.358,'medium'),(81,18,'Soy Sauce',6,NULL,1.580,0.500,70.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.680,0.500,3.471,'fast'),(82,18,'Vinegar',6,NULL,1.980,1.000,60.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.655,1.000,3.018,'fast'),(83,18,'Cooking Oil',6,NULL,1.355,1.000,90.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.225,1.000,1.328,'fast'),(84,18,'Oyster Sauce',6,NULL,3.000,1.000,150.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.000,NULL,NULL,NULL),(85,18,'Annatto Oil',6,NULL,0.785,0.500,120.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.205,0.500,0.851,'fast'),(86,18,'Peanut Sauce Mix',1,NULL,1.400,1.000,180.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.250,1.000,1.130,'medium'),(87,18,'Tamarind Mix',1,NULL,0.800,0.500,150.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.180,0.500,0.747,'fast'),(88,18,'Mayonnaise',1,NULL,1.460,1.000,180.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.300,1.000,1.849,'fast'),(89,18,'Liver Spread',1,NULL,1.270,1.000,220.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.165,1.000,1.186,'fast'),(90,18,'Sugar',1,NULL,1.550,1.000,65.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.360,1.000,1.394,'fast'),(91,18,'Salt',1,NULL,0.605,0.500,25.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.055,0.500,0.354,'fast'),(92,18,'Ground Black Pepper',1,NULL,0.067,0.011,450.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.032,0.011,0.109,'fast'),(93,18,'Cornstarch',1,NULL,1.130,0.500,70.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.340,0.500,1.617,'fast'),(94,19,'Egg',8,NULL,75.000,28.000,8.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',1,20.000,28.000,476.519,'fast'),(95,20,'Rice',1,NULL,32.000,10.000,50.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,13.400,10.000,62.027,'fast'),(96,21,'Biscoff Biscuits',8,NULL,18.000,3.000,15.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,9.000,3.000,28.790,'medium'),(97,22,'Sago Pearls',1,NULL,0.610,0.400,90.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.120,0.400,0.834,'medium'),(98,22,'Gulaman Bars',1,NULL,1.140,1.000,120.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.080,1.000,0.556,'medium'),(99,22,'Brown Sugar Syrup',6,NULL,0.288,0.078,110.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.120,0.078,0.834,'medium'),(100,22,'Iced Tea Mix',1,NULL,1.035,1.000,180.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.020,1.000,0.099,'medium'),(101,22,'Lemon-Calamansi Concentrate',6,NULL,1.120,1.000,130.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.090,1.000,0.224,'medium'),(102,22,'Lychee Syrup',6,NULL,1.280,1.000,140.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.160,1.000,0.609,'medium'),(103,22,'Mixed Fruit Juice Concentrate',6,NULL,1.840,1.000,130.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,0.600,1.000,2.351,'medium'),(104,22,'Coke',8,NULL,14.000,4.000,25.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,5.000,4.000,29.448,'fast'),(105,22,'Sprite (Can)',8,NULL,13.000,3.000,25.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,6.000,3.000,33.263,'fast'),(106,22,'Royal',8,NULL,14.000,3.000,25.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,6.000,3.000,41.188,'fast'),(107,22,'Mountain Dew',8,NULL,13.000,3.000,25.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,6.000,3.000,36.069,'fast'),(108,22,'Beer',8,NULL,6.000,1.000,65.00,0,1,'2026-08-04 01:53:11','2026-09-17 17:48:40',3,3.000,1.000,12.080,'medium');
/*!40000 ALTER TABLE `inventory_items` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `inventory_stock_status`;
/*!50001 DROP VIEW IF EXISTS `inventory_stock_status`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `inventory_stock_status` AS SELECT
 1 AS `item_id`,
  1 AS `current_stock`,
  1 AS `stock_status` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `inventory_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_transactions` (
  `transaction_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `transaction_type` enum('stock_in','stock_out','adjustment','waste','transfer') NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `reference_type` enum('purchase_order','order_item','advance_order','adjustment','waste','initial_stock','packaging_deduction','void_return') DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`transaction_id`),
  KEY `fk_invtxn_batch` (`batch_id`),
  KEY `fk_invtxn_user` (`performed_by`),
  KEY `idx_invtxn_item_date` (`item_id`,`created_at`),
  CONSTRAINT `fk_invtxn_batch` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batches` (`batch_id`),
  CONSTRAINT `fk_invtxn_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  CONSTRAINT `fk_invtxn_user` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `inventory_transactions` WRITE;
/*!40000 ALTER TABLE `inventory_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_transactions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `leave_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_types` (
  `leave_type_id` int(11) NOT NULL AUTO_INCREMENT,
  `leave_name` varchar(50) NOT NULL,
  `is_paid` tinyint(1) NOT NULL DEFAULT 1,
  `default_days_per_year` decimal(5,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`leave_type_id`),
  UNIQUE KEY `uq_leave_name` (`leave_name`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `leave_types` WRITE;
/*!40000 ALTER TABLE `leave_types` DISABLE KEYS */;
INSERT INTO `leave_types` VALUES (1,'Service Incentive Leave',1,5.00),(2,'Sick Leave',1,0.00),(3,'Vacation Leave',1,0.00),(4,'Maternity Leave',1,105.00),(5,'Paternity Leave',1,7.00),(6,'Solo Parent Leave',1,7.00),(7,'Unpaid Leave',0,0.00);
/*!40000 ALTER TABLE `leave_types` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `attempt_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempt_type` enum('login','reset') NOT NULL DEFAULT 'login',
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_la_pair` (`email`,`ip_address`,`attempt_type`,`attempted_at`),
  KEY `idx_la_ip` (`ip_address`,`attempt_type`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `menu_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=226 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `menu_categories` WRITE;
/*!40000 ALTER TABLE `menu_categories` DISABLE KEYS */;
INSERT INTO `menu_categories` VALUES (202,'Alab'),(216,'Lagablab'),(217,'Extra'),(218,'Sabaw'),(219,'Palamig'),(220,'Soda'),(221,'Dessert'),(222,'Alak'),(223,'Silog'),(224,'Kamayan'),(225,'Espesyal');
/*!40000 ALTER TABLE `menu_categories` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `menu_item_costing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_item_costing` (
  `costing_id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_item_id` int(11) NOT NULL,
  `total_ingredient_cost` decimal(10,2) NOT NULL,
  `packaging_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `dine_in_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `takeout_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(10,2) NOT NULL,
  `selling_price` decimal(10,2) NOT NULL,
  `suggested_selling_price` decimal(10,2) DEFAULT NULL,
  `vat_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `markup_percentage` decimal(6,2) DEFAULT NULL,
  `gross_profit` decimal(10,2) DEFAULT NULL,
  `food_cost_percentage_dine_in` decimal(6,2) NOT NULL DEFAULT 0.00,
  `food_cost_percentage_takeout` decimal(6,2) NOT NULL DEFAULT 0.00,
  `gross_margin_percentage` decimal(6,2) NOT NULL DEFAULT 0.00,
  `computed_by` int(11) DEFAULT NULL,
  `computed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`costing_id`),
  UNIQUE KEY `uq_costing_menuitem` (`menu_item_id`),
  KEY `fk_costing_user` (`computed_by`),
  KEY `idx_costing_menuitem_date` (`menu_item_id`,`computed_at`),
  CONSTRAINT `fk_costing_menuitem` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`item_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_costing_user` FOREIGN KEY (`computed_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `menu_item_costing` WRITE;
/*!40000 ALTER TABLE `menu_item_costing` DISABLE KEYS */;
INSERT INTO `menu_item_costing` VALUES (2,202,106.35,10.00,106.35,116.35,116.35,60.00,NULL,6.43,-49.63,-52.78,198.53,217.19,-98.53,NULL,'2026-09-09 11:38:21'),(3,254,44.40,10.00,44.40,54.40,54.40,160.00,NULL,17.14,221.76,98.46,31.08,38.08,68.92,NULL,'2026-09-09 11:26:04'),(4,255,58.35,10.00,58.35,68.35,68.35,160.00,NULL,17.14,144.83,84.51,40.84,47.84,59.16,NULL,'2026-09-09 11:26:04'),(5,256,57.30,10.00,57.30,67.30,67.30,160.00,NULL,17.14,149.32,85.56,40.11,47.11,59.89,NULL,'2026-09-09 11:26:04'),(6,257,24.10,10.00,24.10,34.10,34.10,160.00,NULL,17.14,492.78,118.76,16.87,23.87,83.13,NULL,'2026-09-09 11:26:04'),(7,258,80.70,10.00,80.70,90.70,90.70,250.00,NULL,26.79,176.59,142.51,36.15,40.63,63.85,NULL,'2026-09-09 11:26:04'),(8,259,97.03,10.00,97.03,107.03,107.03,350.00,NULL,37.50,222.07,215.47,31.05,34.25,68.95,NULL,'2026-09-09 11:26:04'),(9,260,38.20,10.00,38.20,48.20,48.20,160.00,NULL,17.14,273.98,104.66,26.74,33.74,73.26,NULL,'2026-09-09 11:26:04'),(10,261,67.35,10.00,67.35,77.35,77.35,180.00,NULL,19.29,138.62,93.36,41.91,48.13,58.09,NULL,'2026-09-09 11:26:04'),(11,262,100.50,10.00,100.50,110.50,110.50,350.00,NULL,37.50,210.95,212.00,32.16,35.36,67.84,NULL,'2026-09-09 11:26:04'),(12,263,82.00,10.00,82.00,92.00,92.00,185.00,NULL,19.82,101.44,83.18,49.64,55.70,50.36,NULL,'2026-09-09 11:26:04'),(13,264,66.50,10.00,66.50,76.50,76.50,160.00,NULL,17.14,114.83,76.36,46.55,53.55,53.45,NULL,'2026-09-09 11:26:04'),(14,265,57.75,10.00,57.75,67.75,67.75,150.00,NULL,16.07,131.91,76.18,43.12,50.59,56.88,NULL,'2026-09-09 11:26:04'),(15,266,86.80,10.00,86.80,96.80,96.80,180.00,NULL,19.29,85.15,73.91,54.01,60.23,45.99,NULL,'2026-09-09 11:26:04'),(16,267,7.50,0.00,7.50,7.50,7.50,30.00,NULL,3.21,257.20,19.29,28.00,28.00,72.00,NULL,'2026-09-09 11:26:04'),(17,268,37.50,0.00,37.50,37.50,37.50,150.00,NULL,16.07,257.15,96.43,28.00,28.00,72.00,NULL,'2026-09-09 11:26:04'),(18,269,12.60,0.00,12.60,12.60,12.60,170.00,NULL,18.21,1104.68,139.19,8.30,8.30,91.70,NULL,'2026-09-09 11:26:04'),(19,270,51.85,10.00,51.85,61.85,61.85,250.00,NULL,26.79,330.49,171.36,23.23,27.71,76.77,NULL,'2026-09-09 11:26:04'),(20,271,68.83,10.00,68.83,78.83,78.83,160.00,NULL,17.14,107.55,74.03,48.18,55.18,51.82,NULL,'2026-09-09 11:26:04'),(21,272,71.70,10.00,71.70,81.70,81.70,160.00,NULL,17.14,99.25,71.16,50.19,57.19,49.81,NULL,'2026-09-09 11:26:04'),(22,273,80.75,10.00,80.75,90.75,90.75,160.00,NULL,17.14,76.92,62.11,56.52,63.52,43.48,NULL,'2026-09-09 11:26:04'),(23,274,102.70,10.00,102.70,112.70,112.70,185.00,NULL,19.82,60.84,62.48,62.17,68.23,37.83,NULL,'2026-09-09 11:26:04'),(24,275,45.70,10.00,45.70,55.70,55.70,160.00,NULL,17.14,212.60,97.16,31.99,38.99,68.01,NULL,'2026-09-09 11:26:04'),(25,276,8.40,0.00,8.40,8.40,8.40,60.00,NULL,6.43,537.74,45.17,15.68,15.68,84.32,NULL,'2026-09-09 11:26:04'),(26,277,8.20,0.00,8.20,8.20,8.20,60.00,NULL,6.43,553.29,45.37,15.31,15.31,84.69,NULL,'2026-09-09 11:26:04'),(27,278,5.20,0.00,5.20,5.20,5.20,60.00,NULL,6.43,930.19,48.37,9.71,9.71,90.29,NULL,'2026-09-09 11:26:04'),(28,279,6.25,0.00,6.25,6.25,6.25,60.00,NULL,6.43,757.12,47.32,11.67,11.67,88.33,NULL,'2026-09-09 11:26:04'),(29,280,17.55,0.00,17.55,17.55,17.55,150.00,NULL,16.07,663.13,116.38,13.10,13.10,86.90,NULL,'2026-09-09 11:26:04'),(30,281,25.00,0.00,25.00,25.00,25.00,60.00,NULL,6.43,114.28,28.57,46.67,46.67,53.33,NULL,'2026-09-09 11:26:04'),(31,282,25.00,0.00,25.00,25.00,25.00,60.00,NULL,6.43,114.28,28.57,46.67,46.67,53.33,NULL,'2026-09-09 11:26:04'),(32,283,25.00,0.00,25.00,25.00,25.00,60.00,NULL,6.43,114.28,28.57,46.67,46.67,53.33,NULL,'2026-09-09 11:26:04'),(33,284,25.00,0.00,25.00,25.00,25.00,60.00,NULL,6.43,114.28,28.57,46.67,46.67,53.33,NULL,'2026-09-09 11:26:04'),(34,285,48.65,10.00,48.65,58.65,58.65,60.00,NULL,6.43,10.11,4.92,90.82,109.48,9.18,NULL,'2026-09-09 11:26:04'),(35,286,65.00,0.00,65.00,65.00,65.00,80.00,NULL,8.57,9.89,6.43,91.00,91.00,9.00,NULL,'2026-09-09 11:26:04'),(36,287,56.00,10.00,56.00,66.00,66.00,90.00,NULL,9.64,43.50,24.36,69.69,82.13,30.31,NULL,'2026-09-09 11:26:04'),(37,288,38.00,10.00,38.00,48.00,48.00,90.00,NULL,9.64,111.47,42.36,47.29,59.73,52.71,NULL,'2026-09-09 11:26:04'),(38,289,46.50,10.00,46.50,56.50,56.50,90.00,NULL,9.64,72.82,33.86,57.86,70.31,42.14,NULL,'2026-09-09 11:26:04'),(39,290,60.00,0.00,60.00,60.00,60.00,90.00,NULL,9.64,33.93,20.36,74.66,74.66,25.34,NULL,'2026-09-09 11:26:04'),(40,291,81.00,10.00,81.00,91.00,91.00,165.00,NULL,17.68,81.88,66.32,54.98,61.77,45.02,NULL,'2026-09-09 11:26:04'),(41,292,49.30,10.00,49.30,59.30,59.30,165.00,NULL,17.68,198.82,98.02,33.46,40.25,66.54,NULL,'2026-09-09 11:26:04'),(42,293,106.00,10.00,106.00,116.00,116.00,185.00,NULL,19.82,55.83,59.18,64.17,70.23,35.83,NULL,'2026-09-09 11:26:04'),(43,294,48.35,10.00,48.35,58.35,58.35,140.00,NULL,15.00,158.53,76.65,38.68,46.68,61.32,NULL,'2026-09-09 11:26:04'),(44,295,119.85,10.00,119.85,129.85,129.85,350.00,NULL,37.50,160.74,192.65,38.35,41.55,61.65,NULL,'2026-09-09 11:26:04'),(45,296,55.10,10.00,55.10,65.10,65.10,200.00,NULL,21.43,224.08,123.47,30.86,36.46,69.14,NULL,'2026-09-09 11:26:04'),(46,297,42.50,10.00,42.50,52.50,52.50,160.00,NULL,17.14,236.14,100.36,29.75,36.75,70.25,NULL,'2026-09-09 11:26:04'),(47,298,116.50,10.00,116.50,126.50,126.50,350.00,NULL,37.50,168.24,196.00,37.28,40.48,62.72,NULL,'2026-09-09 11:26:04'),(48,299,82.25,10.00,82.25,92.25,92.25,300.00,NULL,32.14,225.67,185.61,30.71,34.44,69.29,NULL,'2026-09-09 11:26:04');
/*!40000 ALTER TABLE `menu_item_costing` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `menu_item_demand_forecast`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_item_demand_forecast` (
  `forecast_id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_item_id` int(11) NOT NULL,
  `forecast_date` date NOT NULL,
  `period_type` enum('daily','weekly','monthly','yearly') NOT NULL DEFAULT 'daily',
  `model_name` varchar(50) NOT NULL DEFAULT 'prophet',
  `run_id` bigint(20) DEFAULT NULL COMMENT 'provenance only -- uq_itemforecast_key unchanged, table stays latest-snapshot-only by design',
  `predicted_quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `yhat_lower` decimal(10,2) DEFAULT NULL,
  `yhat_upper` decimal(10,2) DEFAULT NULL,
  `actual_quantity` int(11) DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`forecast_id`),
  UNIQUE KEY `uq_itemforecast_key` (`menu_item_id`,`forecast_date`,`period_type`,`model_name`),
  KEY `fk_itemforecast_menuitem` (`menu_item_id`),
  KEY `idx_itemforecast_date` (`forecast_date`),
  KEY `fk_itemforecast_run` (`run_id`),
  CONSTRAINT `fk_itemforecast_menuitem` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`item_id`),
  CONSTRAINT `fk_itemforecast_run` FOREIGN KEY (`run_id`) REFERENCES `forecast_runs` (`run_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `menu_item_demand_forecast` WRITE;
/*!40000 ALTER TABLE `menu_item_demand_forecast` DISABLE KEYS */;
/*!40000 ALTER TABLE `menu_item_demand_forecast` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `menu_item_ingredients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_item_ingredients` (
  `recipe_id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_item_id` int(11) NOT NULL,
  `inventory_item_id` int(11) NOT NULL,
  `quantity_required` decimal(10,3) NOT NULL,
  `recipe_unit_id` int(11) NOT NULL,
  PRIMARY KEY (`recipe_id`),
  KEY `fk_recipe_invitem` (`inventory_item_id`),
  KEY `fk_recipe_unit` (`recipe_unit_id`),
  KEY `idx_recipe_menuitem` (`menu_item_id`),
  CONSTRAINT `fk_recipe_invitem` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`item_id`),
  CONSTRAINT `fk_recipe_menuitem` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`item_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_recipe_unit` FOREIGN KEY (`recipe_unit_id`) REFERENCES `unit_of_measures` (`unit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=307 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `menu_item_ingredients` WRITE;
/*!40000 ALTER TABLE `menu_item_ingredients` DISABLE KEYS */;
INSERT INTO `menu_item_ingredients` VALUES (13,202,3,300.000,2),(159,254,63,200.000,2),(160,254,85,15.000,7),(161,254,82,10.000,7),(162,254,79,2.000,8),(163,255,3,200.000,2),(164,255,81,15.000,7),(165,255,82,10.000,7),(166,255,71,5.000,2),(167,256,64,200.000,2),(168,256,82,10.000,7),(169,256,71,5.000,2),(170,257,65,250.000,2),(171,257,71,5.000,2),(172,257,83,10.000,7),(173,258,66,200.000,2),(174,258,79,2.000,8),(175,258,81,10.000,7),(176,259,67,300.000,2),(177,259,91,5.000,2),(178,259,83,10.000,7),(179,260,68,250.000,2),(180,260,71,5.000,2),(181,261,60,250.000,2),(182,261,81,15.000,7),(183,261,82,10.000,7),(184,261,71,5.000,2),(185,262,64,250.000,2),(186,262,3,100.000,2),(187,262,72,20.000,2),(188,262,71,5.000,2),(189,263,59,200.000,2),(190,263,92,5.000,2),(191,263,81,15.000,7),(192,263,72,30.000,2),(193,264,3,200.000,2),(194,264,93,10.000,2),(195,264,94,1.000,8),(196,264,72,20.000,2),(197,265,3,200.000,2),(198,265,81,15.000,7),(199,265,71,5.000,2),(200,266,59,200.000,2),(201,266,86,50.000,2),(202,266,76,30.000,2),(203,267,95,150.000,2),(204,268,95,750.000,2),(205,269,95,200.000,2),(206,269,71,10.000,2),(207,269,85,10.000,7),(208,270,63,250.000,2),(209,270,74,15.000,2),(210,270,72,30.000,2),(211,271,61,300.000,2),(212,271,72,30.000,2),(213,271,91,5.000,2),(214,272,61,300.000,2),(215,272,87,20.000,2),(216,272,72,30.000,2),(217,273,59,200.000,2),(218,273,71,5.000,2),(219,273,72,30.000,2),(220,273,92,3.000,2),(221,274,59,250.000,2),(222,274,78,100.000,2),(223,274,72,30.000,2),(224,275,69,250.000,2),(225,275,87,20.000,2),(226,275,72,30.000,2),(227,276,97,30.000,2),(228,276,98,20.000,2),(229,276,99,30.000,7),(230,277,100,5.000,2),(231,277,79,3.000,8),(232,277,90,20.000,2),(233,278,101,30.000,7),(234,278,90,20.000,2),(235,279,102,40.000,7),(236,279,90,10.000,2),(237,280,103,120.000,7),(238,280,90,30.000,2),(239,281,104,1.000,8),(240,282,105,1.000,8),(241,283,106,1.000,8),(242,284,107,1.000,8),(246,286,108,1.000,8),(247,287,59,100.000,2),(248,287,95,200.000,2),(249,287,94,1.000,8),(250,288,62,100.000,2),(251,288,95,200.000,2),(252,288,94,1.000,8),(253,289,63,150.000,2),(254,289,95,200.000,2),(255,289,94,1.000,8),(256,290,3,150.000,2),(257,290,95,200.000,2),(258,290,94,1.000,8),(259,291,3,250.000,2),(260,291,81,20.000,7),(261,291,82,15.000,7),(262,291,71,5.000,2),(263,291,94,1.000,8),(264,292,69,250.000,2),(265,292,82,10.000,7),(266,292,71,5.000,2),(267,292,94,1.000,8),(268,293,59,250.000,2),(269,293,81,20.000,7),(270,293,82,15.000,7),(271,293,71,5.000,2),(272,293,94,1.000,8),(273,294,63,200.000,2),(274,294,81,15.000,7),(275,294,82,10.000,7),(276,294,71,5.000,2),(277,294,94,1.000,8),(278,295,59,300.000,2),(279,295,72,50.000,2),(280,295,83,15.000,7),(281,296,77,200.000,2),(282,296,70,100.000,2),(283,296,93,30.000,2),(284,296,94,1.000,8),(285,297,76,250.000,2),(286,297,73,100.000,2),(287,297,72,50.000,2),(288,297,94,2.000,8),(289,298,70,300.000,2),(290,298,93,50.000,2),(291,298,94,1.000,8),(292,299,3,250.000,2),(293,299,72,30.000,2),(294,299,82,20.000,7),(295,299,75,5.000,2),(296,299,79,2.000,8),(297,299,88,20.000,2),(298,202,72,30.000,2),(299,202,75,5.000,2),(300,202,79,2.000,8),(301,202,88,20.000,2),(302,202,94,1.000,8),(303,202,89,15.000,2),(304,285,80,1.000,8),(305,285,96,3.000,8),(306,285,90,10.000,2);
/*!40000 ALTER TABLE `menu_item_ingredients` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `menu_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_code` varchar(30) NOT NULL,
  `category_id` int(11) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `selling_price` decimal(10,2) NOT NULL,
  `is_vat_exempt` tinyint(1) NOT NULL DEFAULT 0,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `available_dine_in` tinyint(1) NOT NULL DEFAULT 1,
  `available_takeout` tinyint(1) NOT NULL DEFAULT 1,
  `packaging_not_required` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Owner has explicitly confirmed this item needs no takeout packaging (e.g. canned drinks) -- suppresses the packaging-gap warning on Menu Items for this item permanently',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`item_id`),
  UNIQUE KEY `uq_menu_code` (`menu_code`),
  KEY `idx_menuitems_category` (`category_id`),
  CONSTRAINT `fk_menuitem_category` FOREIGN KEY (`category_id`) REFERENCES `menu_categories` (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=305 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `menu_items` WRITE;
/*!40000 ALTER TABLE `menu_items` DISABLE KEYS */;
INSERT INTO `menu_items` VALUES (202,'MENU-0001',202,'Sisig','Crispy, savory chopped pork seasoned with onions, chili, and a tangy calamansi kick.','assets/uploads/menu/menu_6a9ed99ae6f328.86735184.jpg',238.00,0,1,1,1,0,1,'2026-07-19 11:56:14','2026-09-17 11:32:50'),(254,'MENU-0002',202,'Boneless Inasal','Marinated chicken grilled over charcoal, served without bones.','assets/uploads/menu/menu_6a9ed9f2de8fd5.71708572.jpg',160.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:36:18'),(255,'MENU-0003',202,'Inihaw na Liempo','Charcoal-grilled pork belly with a smoky flavor.','assets/uploads/menu/menu_6a9ec6ed70c082.72979191.jpg',160.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 14:15:09'),(256,'MENU-0004',202,'Baby Pusit','Small squid marinated and grilled.','assets/uploads/menu/menu_6a9ec24aa71b03.62956056.jpg',160.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 13:55:22'),(257,'MENU-0005',202,'Grilled Tahong','Charcoal-grilled tahong served plain or with sauce.','assets/uploads/menu/menu_6a9ec5ff9c6609.46291439.jpg',160.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 14:11:11'),(258,'MENU-0006',202,'Grilled Tanigue','Grilled Spanish mackerel steak with a smoky taste.','assets/uploads/menu/menu_6a9ec641e0a345.07803267.jpg',250.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 14:12:17'),(259,'MENU-0007',202,'Pampano','A type of fish grilled whole over charcoal.','assets/uploads/menu/menu_6a9eda7154c2b5.45481790.jpg',350.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:38:25'),(260,'MENU-0008',202,'Inihaw na Talaba','Fresh oysters grilled or served steamed.','assets/uploads/menu/menu_6a9ec764470aa8.05984614.jpg',160.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 14:17:08'),(261,'MENU-0009',202,'Inihaw na Ribs','Pork ribs marinated and grilled until tender.','assets/uploads/menu/menu_6a9ec717332ce9.29039696.jpg',180.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 14:15:51'),(262,'MENU-0010',202,'Stuffed Squid','Squid filled with seasoned vegetables or meat, then grilled.','assets/uploads/menu/menu_6a9eda1655e350.53046979.jpg',350.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:36:54'),(263,'MENU-0011',216,'Pepper Beef','Sliced beef cooked with black pepper sauce, served on a hot plate.','assets/uploads/menu/menu_6a9ed721030402.63978069.jpg',185.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:24:17'),(264,'MENU-0012',216,'Meatballs','Seasoned ground meat shaped into balls and cooked in savory sauce.','assets/uploads/menu/menu_6a9ed85ab7aaf1.69343357.jpg',160.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:29:30'),(265,'MENU-0013',216,'Porkstrip','Marinated pork strips grilled or sauteed and served sizzling.','assets/uploads/menu/menu_6a9ed7dec44f60.72970555.jpg',150.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:27:26'),(266,'MENU-0014',216,'Sizzling Kare Kare','Tender beef or oxtail, vegetables, and rich peanut sauce, served on a hot sizzling plate.','assets/uploads/menu/menu_6a9ed7856bc092.81317409.jpg',180.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:25:57'),(267,'MENU-0015',217,'Rice (1 Cup)','Steamed white rice, one cup serving.','assets/uploads/menu/menu_6a9ed88bdc9250.56501071.jpg',30.00,0,1,1,0,0,1,'2026-08-04 01:53:11','2026-09-10 08:09:31'),(268,'MENU-0016',217,'Rice (Platter)','Steamed white rice, platter serving.','assets/uploads/menu/menu_6a9ed903dd11c0.24139659.jpg',150.00,0,1,1,0,0,1,'2026-08-04 01:53:11','2026-09-10 08:09:45'),(269,'MENU-0017',217,'Garlic Anato Rice','Fried rice with garlic and annatto oil.','assets/uploads/menu/menu_6a9ec5911c2c66.38705956.jpg',170.00,0,1,1,0,0,1,'2026-08-04 01:53:11','2026-09-10 08:09:25'),(270,'MENU-0018',218,'Classic Tinola','A traditional Filipino ginger-based chicken soup with green papaya and chili leaves.','assets/uploads/menu/menu_6a9ec528a59fb9.75723527.jpg',250.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 14:07:36'),(271,'MENU-0019',218,'Pata Nilaga','Boiled pork leg soup with vegetables, served in a light, savory broth.','assets/uploads/menu/menu_6a9ed49f01d215.80652090.jpg',160.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:13:35'),(272,'MENU-0020',218,'Pata Sinigang','Pork leg cooked in a sour tamarind broth with vegetables.','assets/uploads/menu/menu_6a9ece2ae6ae67.28807010.jpg',160.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 14:46:02'),(273,'MENU-0021',218,'Tadtarin (Baka)','A native-style chopped meat dish sauteed with spices and seasonings.','assets/uploads/menu/menu_6a9ed540127df4.65599794.jpg',160.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:16:16'),(274,'MENU-0022',218,'Nilagang Baka','Beef shank soup boiled until tender with corn and vegetables.','assets/uploads/menu/menu_6a9ecdb3bf3b70.94875249.jpg',185.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 14:44:03'),(275,'MENU-0023',218,'Sinigang na Inihaw na Bangus','Milkfish cooked in a sour broth with fresh vegetables.','assets/uploads/menu/menu_6a9ed5cab4dd65.34521755.jpg',160.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:18:34'),(276,'MENU-0024',219,'Sago\'t Gulaman','Iced sago and gulaman drink with brown sugar syrup.','assets/uploads/menu/menu_6a9edfd29fe089.06040878.jpg',60.00,0,1,1,0,0,1,'2026-08-04 01:53:11','2026-09-10 07:52:27'),(277,'MENU-0025',219,'Calamantea','Iced tea with calamansi.','assets/uploads/menu/menu_6a9edd28c781c7.01894817.jpg',60.00,0,1,1,0,0,1,'2026-08-04 01:53:11','2026-09-10 07:52:03'),(278,'MENU-0026',219,'Lemonada','Fresh lemon-calamansi juice.','assets/uploads/menu/menu_6a9edef01d9fc6.94783533.jpg',60.00,0,1,1,0,0,1,'2026-08-04 01:53:11','2026-09-10 07:52:09'),(279,'MENU-0027',219,'Lychee','Iced lychee juice.','assets/uploads/menu/menu_6a9edfeb9384c3.53800162.jpg',60.00,0,1,1,0,0,1,'2026-08-04 01:53:11','2026-09-10 07:52:16'),(280,'MENU-0028',219,'Pitcher Juice','Mixed fruit juice, pitcher serving.','assets/uploads/menu/menu_6a9edf338207c8.18995748.jpg',150.00,0,1,1,0,0,1,'2026-08-04 01:53:11','2026-09-10 07:52:21'),(281,'MENU-0029',220,'Coke','Bottled/canned Coca-Cola.','assets/uploads/menu/menu_6a9f5d65844d57.71877970.jpg',60.00,0,1,1,1,1,1,'2026-08-04 01:53:11','2026-09-10 08:10:01'),(282,'MENU-0030',220,'Sprite','Bottled/canned Sprite.','assets/uploads/menu/menu_6a9f5d7a574262.13579226.jpg',60.00,0,1,1,1,1,1,'2026-08-04 01:53:11','2026-09-10 08:10:29'),(283,'MENU-0031',220,'Royal','Bottled/canned Royal.','assets/uploads/menu/menu_6a9f5d73eef2a0.42826194.jpg',60.00,0,1,1,1,1,1,'2026-08-04 01:53:11','2026-09-10 08:10:15'),(284,'MENU-0032',220,'Mountain Dew','Bottled/canned Mountain Dew.','assets/uploads/menu/menu_6a9f5d6c1287a2.34748289.jpg',60.00,0,1,1,1,1,1,'2026-08-04 01:53:11','2026-09-10 08:10:07'),(285,'MENU-0033',221,'Banana Biscoff','Banana with Biscoff biscuit crumble.','assets/uploads/menu/menu_6a9edc30d072f8.40653187.jpg',60.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:45:52'),(286,'MENU-0034',222,'Beer','Bottled beer.','assets/uploads/menu/menu_6a71a9f66e58d2.65210351.jpg',80.00,0,1,1,1,1,1,'2026-08-04 01:53:11','2026-09-10 08:11:28'),(287,'MENU-0035',223,'Tapa Taal Silog','Sweet and savory cured pork from Taal, served with garlic rice and egg.','assets/uploads/menu/menu_6a9ecd2c475c42.46818309.jpg',90.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 14:41:48'),(288,'MENU-0036',223,'Longsilog','Sweet Filipino sausage served with garlic rice and egg.','assets/uploads/menu/menu_6a9ecc85e70316.61593037.jpg',90.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 14:39:01'),(289,'MENU-0037',223,'Chiksilog','Fried chicken served with garlic rice and egg.','assets/uploads/menu/menu_6a9ec4aaaf1270.03820882.jpg',90.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 14:05:30'),(290,'MENU-0038',223,'Lechonsilog','Fried pork served with garlic rice and egg.','assets/uploads/menu/menu_6a9ec7f084d284.42439263.jpg',90.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 14:19:28'),(291,'MENU-0039',224,'Adobong Baboy','Twice cook pork braised in vinegar, soy sauce, garlic, and spices.','assets/uploads/menu/menu_6a9ec1d8916819.77080760.jpg',181.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-09 11:43:43'),(292,'MENU-0040',224,'Inasal Bangus','Grilled milkfish marinated in vinegar, garlic, and spices.','assets/uploads/menu/menu_6a9ec6abddfc92.44723347.jpg',165.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 14:14:03'),(293,'MENU-0041',224,'Adobong Baka','Beef slowly cooked in vinegar, soy sauce, and garlic.','assets/uploads/menu/menu_6a9ec21518c701.00009874.jpg',185.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 13:54:29'),(294,'MENU-0042',224,'Adobo Flakes','Shredded chicken adobo, sauteed until slightly crisp.','assets/uploads/menu/menu_6a9ec0d37aa713.98121213.jpg',180.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-17 13:51:43'),(295,'MENU-0043',225,'Pigar Pigar','Thinly sliced beef quickly fried with onions, a famous Dagupan dish.','assets/uploads/menu/menu_6a9edb25e965c3.11304830.jpg',350.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:41:25'),(296,'MENU-0044',225,'Opo Okoy Po','Stir-fried bottle gourd cooked with vegetables and seafood.','assets/uploads/menu/menu_6a9edbdc1d98a1.66126338.jpg',200.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:44:28'),(297,'MENU-0045',225,'Poqui Poqui','Grilled eggplant mixed with tomatoes and onions, similar to ensalada.','assets/uploads/menu/menu_6a9edb0401e790.40643549.jpg',160.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:40:52'),(298,'MENU-0046',225,'Camaron Reposado','Deep-fried battered shrimp served crispy and golden. Good for 2-3.','assets/uploads/menu/menu_6a9edb70bc4e82.82716804.jpg',350.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:42:40'),(299,'MENU-0047',225,'Sisig Kilaw','A mix of grilled meat and fresh ingredients seasoned with vinegar and spices.','assets/uploads/menu/menu_6a9edaab7548f5.81461710.jpg',300.00,0,1,1,1,0,1,'2026-08-04 01:53:11','2026-09-07 15:39:23');
/*!40000 ALTER TABLE `menu_items` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('reservation','payment','inventory','order','procurement','system','payroll','cashier','feedback','attendance','users') NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_notifications_user_read` (`user_id`,`is_read`),
  CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_items` (
  `order_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `menu_item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  `status` enum('pending','preparing','served','cancelled') NOT NULL DEFAULT 'pending',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`order_item_id`),
  KEY `fk_orderitem_menuitem` (`menu_item_id`),
  KEY `idx_orderitems_order` (`order_id`),
  CONSTRAINT `fk_orderitem_menuitem` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`item_id`),
  CONSTRAINT `fk_orderitem_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `order_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `payment_method` enum('cash','paymongo_gcash') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `amount_tendered` decimal(12,2) DEFAULT NULL COMMENT 'Cash physically handed over, NULL for non-cash methods with no change',
  `payment_status` enum('pending','paid','failed','voided') NOT NULL DEFAULT 'pending',
  `paymongo_payment_intent_id` varchar(100) DEFAULT NULL,
  `paymongo_reference_number` varchar(100) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`payment_id`),
  KEY `fk_orderpayment_order` (`order_id`),
  KEY `idx_orderpayments_status` (`payment_status`),
  CONSTRAINT `fk_orderpayment_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `order_payments` WRITE;
/*!40000 ALTER TABLE `order_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_payments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `order_void_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_void_requests` (
  `void_request_id` int(11) NOT NULL AUTO_INCREMENT,
  `void_number` varchar(30) NOT NULL,
  `order_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `reason_code` enum('wrong_order','customer_cancelled','duplicate_entry','pricing_error','quality_issue','other') NOT NULL,
  `reason_notes` varchar(500) DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` varchar(500) DEFAULT NULL,
  `voided_amount` decimal(12,2) DEFAULT NULL,
  `restored_txn_count` int(11) NOT NULL DEFAULT 0,
  `shift_was_closed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `pending_order_id` int(11) GENERATED ALWAYS AS (case when `status` = 'pending' then `order_id` else NULL end) STORED,
  PRIMARY KEY (`void_request_id`),
  UNIQUE KEY `uq_voidreq_number` (`void_number`),
  UNIQUE KEY `uq_voidreq_one_pending_per_order` (`pending_order_id`),
  KEY `idx_voidreq_status_created` (`status`,`created_at`),
  KEY `fk_voidreq_order` (`order_id`),
  KEY `fk_voidreq_requester` (`requested_by`),
  KEY `fk_voidreq_reviewer` (`reviewed_by`),
  KEY `fk_voidreq_shift` (`shift_id`),
  CONSTRAINT `fk_voidreq_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`),
  CONSTRAINT `fk_voidreq_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_voidreq_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_voidreq_shift` FOREIGN KEY (`shift_id`) REFERENCES `cash_balances` (`shift_id`),
  CONSTRAINT `chk_voidreq_reviewed` CHECK (`status` in ('pending','cancelled') or `reviewed_by` is not null and `reviewed_at` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `order_void_requests` WRITE;
/*!40000 ALTER TABLE `order_void_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_void_requests` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `order_id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(30) NOT NULL,
  `order_type` enum('dine_in','takeout') NOT NULL DEFAULT 'dine_in',
  `reservation_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `cashier_id` int(11) NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `order_status` enum('open','preparing','ready','served','completed','cancelled','voided') NOT NULL DEFAULT 'open',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_type_id` int(11) DEFAULT NULL,
  `discount_name` varchar(50) DEFAULT NULL,
  `vat_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `packaging_fee_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`order_id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `fk_order_customer` (`customer_id`),
  KEY `fk_order_cashier` (`cashier_id`),
  KEY `idx_orders_status` (`order_status`),
  KEY `idx_orders_created` (`created_at`),
  KEY `fk_order_reservation` (`reservation_id`),
  KEY `fk_order_discount_type` (`discount_type_id`),
  KEY `fk_order_shift` (`shift_id`),
  CONSTRAINT `fk_order_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_order_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_order_discount_type` FOREIGN KEY (`discount_type_id`) REFERENCES `discount_types` (`discount_type_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_order_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`),
  CONSTRAINT `fk_order_shift` FOREIGN KEY (`shift_id`) REFERENCES `cash_balances` (`shift_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `overtime_authorizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `overtime_authorizations` (
  `overtime_authorization_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `authorized_minutes` int(11) NOT NULL,
  `status` enum('approved','cancelled') NOT NULL DEFAULT 'approved' COMMENT 'Approval is implicit on insert -- only a manager can encode this row, same convention as employee_leave_records',
  `recorded_by` int(11) DEFAULT NULL COMMENT 'Manager who authorized/encoded this',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`overtime_authorization_id`),
  KEY `idx_ot_employee_date` (`employee_id`,`work_date`),
  KEY `fk_ot_recorder` (`recorded_by`),
  CONSTRAINT `fk_ot_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ot_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `overtime_authorizations` WRITE;
/*!40000 ALTER TABLE `overtime_authorizations` DISABLE KEYS */;
/*!40000 ALTER TABLE `overtime_authorizations` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `packaging_rule_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `packaging_rule_items` (
  `rule_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `rule_id` int(11) NOT NULL,
  `inventory_item_id` int(11) NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `unit_id` int(11) NOT NULL,
  PRIMARY KEY (`rule_item_id`),
  KEY `fk_packitem_rule` (`rule_id`),
  KEY `fk_packitem_invitem` (`inventory_item_id`),
  KEY `fk_packitem_unit` (`unit_id`),
  CONSTRAINT `fk_packitem_invitem` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`item_id`),
  CONSTRAINT `fk_packitem_rule` FOREIGN KEY (`rule_id`) REFERENCES `packaging_rules` (`rule_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_packitem_unit` FOREIGN KEY (`unit_id`) REFERENCES `unit_of_measures` (`unit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `packaging_rule_items` WRITE;
/*!40000 ALTER TABLE `packaging_rule_items` DISABLE KEYS */;
INSERT INTO `packaging_rule_items` VALUES (5,3,6,1.000,8),(8,6,6,1.000,8),(10,8,6,1.000,8),(11,7,6,1.000,8),(12,9,6,1.000,8),(13,10,6,1.000,8),(15,11,6,1.000,8),(16,12,6,1.000,8),(17,13,6,1.000,8),(18,14,6,1.000,8),(19,15,6,1.000,8),(20,16,6,1.000,8),(22,17,6,1.000,8),(23,18,6,1.000,8),(24,19,6,1.000,8),(26,20,6,1.000,8),(28,21,6,1.000,8),(29,22,6,1.000,8),(30,23,6,1.000,8),(31,24,6,1.000,8),(32,25,6,1.000,8),(33,26,6,1.000,8),(34,27,6,1.000,8),(35,28,6,1.000,8),(36,29,6,1.000,8),(37,30,6,1.000,8),(38,31,6,1.000,8),(39,32,6,1.000,8),(40,33,6,1.000,8),(41,34,6,1.000,8),(42,35,6,1.000,8),(43,36,6,1.000,8),(44,37,6,1.000,8);
/*!40000 ALTER TABLE `packaging_rule_items` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `packaging_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `packaging_rules` (
  `rule_id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_item_id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`rule_id`),
  UNIQUE KEY `uq_packrule_menuitem` (`menu_item_id`),
  CONSTRAINT `fk_packrule_menuitem` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`item_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `packaging_rules` WRITE;
/*!40000 ALTER TABLE `packaging_rules` DISABLE KEYS */;
INSERT INTO `packaging_rules` VALUES (3,202,1,'2026-07-19 11:57:14','2026-07-19 11:57:14'),(6,294,1,'2026-08-17 08:34:08','2026-08-17 08:34:08'),(7,291,1,'2026-09-04 12:09:56','2026-09-04 12:09:56'),(8,293,1,'2026-09-04 12:11:47','2026-09-04 12:11:47'),(9,256,1,'2026-09-04 12:12:51','2026-09-04 12:12:51'),(10,285,1,'2026-09-04 12:13:26','2026-09-04 12:13:26'),(11,254,1,'2026-09-04 12:13:46','2026-09-04 12:13:46'),(12,298,1,'2026-09-04 12:14:21','2026-09-04 12:14:21'),(13,289,1,'2026-09-04 12:14:37','2026-09-04 12:14:37'),(14,270,1,'2026-09-04 12:15:06','2026-09-04 12:15:06'),(15,257,1,'2026-09-04 12:15:48','2026-09-04 12:15:48'),(16,258,1,'2026-09-04 12:16:07','2026-09-04 12:16:07'),(17,292,1,'2026-09-04 12:17:19','2026-09-04 12:17:19'),(18,255,1,'2026-09-04 12:17:46','2026-09-04 12:17:46'),(19,261,1,'2026-09-04 12:18:13','2026-09-04 12:18:13'),(20,260,1,'2026-09-04 12:18:44','2026-09-04 12:18:44'),(21,287,1,'2026-09-04 12:33:19','2026-09-04 12:33:19'),(22,288,1,'2026-09-04 12:33:47','2026-09-04 12:33:47'),(23,272,1,'2026-09-04 12:34:58','2026-09-04 12:34:58'),(24,271,1,'2026-09-04 12:35:17','2026-09-04 12:35:17'),(25,299,1,'2026-09-04 12:35:43','2026-09-04 12:35:43'),(26,262,1,'2026-09-04 12:36:05','2026-09-04 12:36:05'),(27,273,1,'2026-09-04 12:36:22','2026-09-04 12:36:22'),(28,266,1,'2026-09-04 12:37:01','2026-09-04 12:37:01'),(29,263,1,'2026-09-04 12:37:42','2026-09-04 12:37:42'),(30,295,1,'2026-09-04 12:38:04','2026-09-04 12:38:04'),(31,297,1,'2026-09-04 12:39:05','2026-09-04 12:39:05'),(32,265,1,'2026-09-04 12:39:58','2026-09-04 12:39:58'),(33,275,1,'2026-09-04 12:40:22','2026-09-04 12:40:22'),(34,264,1,'2026-09-04 12:41:16','2026-09-04 12:41:16'),(35,274,1,'2026-09-04 12:41:38','2026-09-04 12:41:38'),(36,296,1,'2026-09-04 12:42:12','2026-09-04 12:42:12'),(37,259,1,'2026-09-04 12:42:38','2026-09-04 12:42:38');
/*!40000 ALTER TABLE `packaging_rules` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `pagibig_contribution_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pagibig_contribution_table` (
  `pagibig_bracket_id` int(11) NOT NULL AUTO_INCREMENT,
  `compensation_from` decimal(12,2) NOT NULL,
  `compensation_to` decimal(12,2) DEFAULT NULL,
  `employee_rate_percent` decimal(5,3) NOT NULL,
  `employer_rate_percent` decimal(5,3) NOT NULL,
  `max_fund_salary` decimal(12,2) NOT NULL DEFAULT 10000.00 COMMENT 'Contribution is computed on compensation capped at this amount',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`pagibig_bracket_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `pagibig_contribution_table` WRITE;
/*!40000 ALTER TABLE `pagibig_contribution_table` DISABLE KEYS */;
INSERT INTO `pagibig_contribution_table` VALUES (1,0.00,1500.00,1.000,2.000,10000.00,'2026-07-22 13:38:56'),(2,1500.01,NULL,2.000,2.000,10000.00,'2026-07-22 13:38:56');
/*!40000 ALTER TABLE `pagibig_contribution_table` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `requested_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uq_password_reset_token_hash` (`token_hash`),
  KEY `idx_pwreset_user_expiry` (`user_id`,`expires_at`),
  CONSTRAINT `fk_pwreset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=CURRENT_USER*/ /*!50003 TRIGGER trg_password_reset_customer_only
BEFORE INSERT ON password_reset_tokens
FOR EACH ROW
BEGIN
    DECLARE v_role_name VARCHAR(50);

    SELECT r.role_name INTO v_role_name
    FROM users u
    JOIN roles r ON r.role_id = u.role_id
    WHERE u.user_id = NEW.user_id;

    IF v_role_name <> 'customer' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Self-service password reset via email is only available to customer accounts.';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `payment_gateway_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_gateway_settings` (
  `gateway_id` int(11) NOT NULL AUTO_INCREMENT,
  `public_key` varchar(255) DEFAULT NULL,
  `secret_key` varchar(255) DEFAULT NULL,
  `webhook_secret` varchar(255) DEFAULT NULL,
  `is_test_mode` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`gateway_id`),
  KEY `fk_gateway_user` (`updated_by`),
  CONSTRAINT `fk_gateway_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `payment_gateway_settings` WRITE;
/*!40000 ALTER TABLE `payment_gateway_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_gateway_settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `payroll_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_adjustments` (
  `adjustment_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `payroll_run_id` int(11) DEFAULT NULL COMMENT 'NULL until this adjustment is picked up by a payroll run',
  `adjustment_category` enum('earning','deduction') NOT NULL,
  `adjustment_type` varchar(50) NOT NULL COMMENT 'e.g. bonus, allowance, cash_advance, sss_loan, uniform, other',
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` enum('pending','applied') NOT NULL DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_taxable` tinyint(1) NOT NULL DEFAULT 1,
  `target_period_start` date DEFAULT NULL,
  `target_period_end` date DEFAULT NULL,
  PRIMARY KEY (`adjustment_id`),
  KEY `fk_adjustment_run` (`payroll_run_id`),
  KEY `fk_adjustment_creator` (`created_by`),
  KEY `idx_adjustment_employee` (`employee_id`),
  CONSTRAINT `fk_adjustment_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_adjustment_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_adjustment_run` FOREIGN KEY (`payroll_run_id`) REFERENCES `payroll_runs` (`payroll_run_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `payroll_adjustments` WRITE;
/*!40000 ALTER TABLE `payroll_adjustments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payroll_adjustments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `payroll_deduction_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_deduction_types` (
  `deduction_type_id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL COMMENT 'SSS, PHILHEALTH, PAGIBIG, WTAX, or a custom code for loans/other deductions',
  `name` varchar(100) NOT NULL,
  `category` enum('government_mandatory','loan','other_deduction') NOT NULL DEFAULT 'other_deduction',
  `computation_method` enum('bracket_table','fixed_amount','percentage_of_basic','manual') NOT NULL DEFAULT 'manual',
  `has_employer_share` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'True for SSS/PhilHealth/Pag-IBIG (employer counterpart tracked for remittance reports)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'THE PANEL SWITCH: set to 0 to stop this deduction from being applied to any payroll run, no code change needed',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`deduction_type_id`),
  UNIQUE KEY `uq_deduction_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `payroll_deduction_types` WRITE;
/*!40000 ALTER TABLE `payroll_deduction_types` DISABLE KEYS */;
INSERT INTO `payroll_deduction_types` VALUES (1,'SSS','SSS Contribution','government_mandatory','bracket_table',1,1,1,'Social Security System monthly contribution','2026-07-22 13:38:56','2026-07-22 13:38:56'),(2,'PHILHEALTH','PhilHealth Premium','government_mandatory','bracket_table',1,1,2,'PhilHealth monthly premium','2026-07-22 13:38:56','2026-07-22 13:38:56'),(3,'PAGIBIG','Pag-IBIG (HDMF) Contribution','government_mandatory','bracket_table',1,1,3,'Home Development Mutual Fund monthly contribution','2026-07-22 13:38:56','2026-07-22 13:38:56'),(4,'WTAX','Withholding Tax','government_mandatory','bracket_table',0,1,4,'BIR withholding tax on compensation','2026-07-22 13:38:56','2026-07-22 13:38:56'),(5,'CASH_ADVANCE','Cash Advance','loan','manual',0,1,10,'Employee cash advance repayment','2026-07-22 13:38:56','2026-07-22 13:38:56'),(10,'OTHER_DEDUCTION','Other Deduction','other_deduction','manual',0,1,30,'Other authorized payroll deduction','2026-09-04 09:45:43','2026-09-07 04:56:18'),(11,'LOAN_REPAYMENT','Loan Repayment','loan','manual',0,1,11,'Employee loan repayment (SSS, Pag-IBIG, or company loan)','2026-09-07 04:56:18','2026-09-07 04:56:18');
/*!40000 ALTER TABLE `payroll_deduction_types` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `payroll_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_runs` (
  `payroll_run_id` int(11) NOT NULL AUTO_INCREMENT,
  `run_number` varchar(30) NOT NULL COMMENT 'e.g. PR-2026-07-B for the 2nd cutoff of July 2026',
  `pay_frequency` enum('semi_monthly','monthly') NOT NULL,
  `cutoff_period_start` date NOT NULL,
  `cutoff_period_end` date NOT NULL,
  `payout_date` date NOT NULL,
  `status` enum('draft','processing','pending_approval','approved','released','cancelled') NOT NULL DEFAULT 'draft',
  `total_employees` int(11) NOT NULL DEFAULT 0,
  `total_gross_pay` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_deductions` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_net_pay` decimal(14,2) NOT NULL DEFAULT 0.00,
  `generated_by` int(11) DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `released_by` int(11) DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`payroll_run_id`),
  UNIQUE KEY `uq_run_number` (`run_number`),
  KEY `fk_payrollrun_generator` (`generated_by`),
  KEY `fk_payrollrun_approver` (`approved_by`),
  KEY `fk_payrollrun_processor` (`processed_by`),
  KEY `fk_payrollrun_completer` (`released_by`),
  CONSTRAINT `fk_payrollrun_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payrollrun_completer` FOREIGN KEY (`released_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payrollrun_generator` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payrollrun_processor` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `payroll_runs` WRITE;
/*!40000 ALTER TABLE `payroll_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `payroll_runs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `payroll_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_settings` (
  `payroll_setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `semi_monthly_cutoff1_start_day` tinyint(4) NOT NULL DEFAULT 1,
  `semi_monthly_cutoff1_end_day` tinyint(4) NOT NULL DEFAULT 15,
  `semi_monthly_cutoff1_payout_day` tinyint(4) NOT NULL DEFAULT 20,
  `semi_monthly_cutoff2_start_day` tinyint(4) NOT NULL DEFAULT 16,
  `semi_monthly_cutoff2_end_day` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0 = last calendar day of the month',
  `semi_monthly_cutoff2_payout_day` tinyint(4) NOT NULL DEFAULT 5,
  `semi_monthly_cutoff2_payout_next_month` tinyint(1) NOT NULL DEFAULT 1,
  `monthly_cutoff_start_day` tinyint(4) NOT NULL DEFAULT 1,
  `monthly_cutoff_end_day` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0 = last calendar day of the month',
  `monthly_payout_day` tinyint(4) NOT NULL DEFAULT 5,
  `monthly_payout_next_month` tinyint(1) NOT NULL DEFAULT 1,
  `late_grace_period_minutes` int(11) NOT NULL DEFAULT 10 COMMENT 'Minutes after scheduled time-in before an employee is marked late',
  `undertime_grace_period_minutes` int(11) NOT NULL DEFAULT 10 COMMENT 'Minutes before scheduled time-out an employee can leave without being marked undertime',
  `attendance_import_cutoff_hours` smallint(6) NOT NULL DEFAULT 24 COMMENT 'Hours after a scheduled shift ends, with no attendance record, before the employee is auto-marked Absent',
  `standard_work_hours_per_day` decimal(4,2) NOT NULL DEFAULT 8.00,
  `default_break_minutes` int(11) NOT NULL DEFAULT 60,
  `annual_working_days_divisor` decimal(6,2) NOT NULL DEFAULT 261.00 COMMENT 'DOLE factor used to derive the daily/hourly-equivalent rate of monthly-paid employees',
  `overtime_multiplier` decimal(4,2) NOT NULL DEFAULT 1.25,
  `rest_day_multiplier` decimal(4,2) NOT NULL DEFAULT 1.30,
  `rest_day_ot_multiplier` decimal(4,2) NOT NULL DEFAULT 1.69,
  `regular_holiday_multiplier` decimal(4,2) NOT NULL DEFAULT 2.00,
  `regular_holiday_ot_multiplier` decimal(4,2) NOT NULL DEFAULT 2.60,
  `regular_holiday_restday_multiplier` decimal(4,2) NOT NULL DEFAULT 2.60,
  `regular_holiday_restday_ot_multiplier` decimal(4,2) NOT NULL DEFAULT 3.38,
  `special_holiday_multiplier` decimal(4,2) NOT NULL DEFAULT 1.30,
  `special_holiday_ot_multiplier` decimal(4,2) NOT NULL DEFAULT 1.69,
  `special_holiday_restday_multiplier` decimal(4,2) NOT NULL DEFAULT 1.50,
  `special_holiday_restday_ot_multiplier` decimal(4,2) NOT NULL DEFAULT 1.95,
  `night_differential_rate` decimal(4,3) NOT NULL DEFAULT 0.100,
  `night_differential_start` time NOT NULL DEFAULT '22:00:00',
  `night_differential_end` time NOT NULL DEFAULT '06:00:00',
  `enable_government_contributions` tinyint(1) NOT NULL DEFAULT 1,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`payroll_setting_id`),
  KEY `fk_payrollsettings_user` (`updated_by`),
  CONSTRAINT `fk_payrollsettings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `payroll_settings` WRITE;
/*!40000 ALTER TABLE `payroll_settings` DISABLE KEYS */;
INSERT INTO `payroll_settings` VALUES (1,1,15,16,16,0,1,1,1,0,1,1,10,10,24,8.00,60,313.00,1.25,1.30,1.69,2.00,2.60,2.60,3.38,1.30,1.69,1.50,1.95,0.100,'22:00:00','06:00:00',1,NULL,'2026-09-17 17:48:40');
/*!40000 ALTER TABLE `payroll_settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `payslip_deductions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payslip_deductions` (
  `payslip_deduction_id` int(11) NOT NULL AUTO_INCREMENT,
  `payslip_id` int(11) NOT NULL,
  `deduction_type_id` int(11) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `employee_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `employer_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Employer counterpart, tracked for government remittance reports (not deducted from the employee)',
  `adjustment_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`payslip_deduction_id`),
  KEY `fk_payslipdeduction_payslip` (`payslip_id`),
  KEY `fk_payslipdeduction_type` (`deduction_type_id`),
  KEY `fk_payslipdeduction_adjustment` (`adjustment_id`),
  CONSTRAINT `fk_payslipdeduction_adjustment` FOREIGN KEY (`adjustment_id`) REFERENCES `payroll_adjustments` (`adjustment_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payslipdeduction_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips` (`payslip_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payslipdeduction_type` FOREIGN KEY (`deduction_type_id`) REFERENCES `payroll_deduction_types` (`deduction_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `payslip_deductions` WRITE;
/*!40000 ALTER TABLE `payslip_deductions` DISABLE KEYS */;
/*!40000 ALTER TABLE `payslip_deductions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `payslip_earnings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payslip_earnings` (
  `payslip_earning_id` int(11) NOT NULL AUTO_INCREMENT,
  `payslip_id` int(11) NOT NULL,
  `earning_type` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `adjustment_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`payslip_earning_id`),
  KEY `fk_payslipearning_payslip` (`payslip_id`),
  KEY `fk_payslipearning_adjustment` (`adjustment_id`),
  CONSTRAINT `fk_payslipearning_adjustment` FOREIGN KEY (`adjustment_id`) REFERENCES `payroll_adjustments` (`adjustment_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payslipearning_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips` (`payslip_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `payslip_earnings` WRITE;
/*!40000 ALTER TABLE `payslip_earnings` DISABLE KEYS */;
/*!40000 ALTER TABLE `payslip_earnings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `payslips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payslips` (
  `payslip_id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_run_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `days_worked` decimal(5,2) NOT NULL DEFAULT 0.00,
  `hours_worked` decimal(7,2) NOT NULL DEFAULT 0.00,
  `basic_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `overtime_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `holiday_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rest_day_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `night_differential_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `leave_pay` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Paid leave days sourced from employee_leave_records where status = approved',
  `paid_leave_days` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Approved paid-leave days in this cutoff period, from employee_leave_records',
  `late_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `undertime_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `absence_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gross_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','finalized','released') NOT NULL DEFAULT 'draft',
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`payslip_id`),
  UNIQUE KEY `uq_run_employee` (`payroll_run_id`,`employee_id`),
  KEY `idx_payslip_employee` (`employee_id`),
  CONSTRAINT `fk_payslip_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  CONSTRAINT `fk_payslip_run` FOREIGN KEY (`payroll_run_id`) REFERENCES `payroll_runs` (`payroll_run_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `payslips` WRITE;
/*!40000 ALTER TABLE `payslips` DISABLE KEYS */;
/*!40000 ALTER TABLE `payslips` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `philhealth_contribution_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `philhealth_contribution_table` (
  `philhealth_bracket_id` int(11) NOT NULL AUTO_INCREMENT,
  `salary_floor` decimal(12,2) NOT NULL DEFAULT 10000.00,
  `salary_ceiling` decimal(12,2) NOT NULL DEFAULT 100000.00,
  `premium_rate_percent` decimal(5,3) NOT NULL DEFAULT 5.000,
  `employee_share_percent` decimal(5,3) NOT NULL DEFAULT 2.500,
  `employer_share_percent` decimal(5,3) NOT NULL DEFAULT 2.500,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`philhealth_bracket_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `philhealth_contribution_table` WRITE;
/*!40000 ALTER TABLE `philhealth_contribution_table` DISABLE KEYS */;
INSERT INTO `philhealth_contribution_table` VALUES (1,10000.00,100000.00,5.000,2.500,2.500,'2026-07-22 13:38:56');
/*!40000 ALTER TABLE `philhealth_contribution_table` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `positions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `positions` (
  `position_id` int(11) NOT NULL AUTO_INCREMENT,
  `department_id` int(11) DEFAULT NULL,
  `position_title` varchar(100) NOT NULL,
  `salary_type` enum('monthly','daily') NOT NULL DEFAULT 'monthly',
  `pay_frequency` enum('semi_monthly','monthly') NOT NULL DEFAULT 'semi_monthly',
  `regular_rate` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Rate for a Regular employee in this position',
  `probationary_rate` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Rate for a Probationary employee in this position',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`position_id`),
  KEY `fk_position_department` (`department_id`),
  CONSTRAINT `fk_position_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `positions` WRITE;
/*!40000 ALTER TABLE `positions` DISABLE KEYS */;
INSERT INTO `positions` VALUES (7,5,'Cashier','daily','semi_monthly',760.00,760.00,1,'2026-07-23 07:08:08'),(8,10,'Manager','monthly','monthly',30000.00,30000.00,1,'2026-07-23 07:18:31'),(12,6,'Head chef','daily','semi_monthly',750.00,750.00,1,'2026-09-04 10:46:08'),(13,5,'Server','daily','semi_monthly',600.00,600.00,1,'2026-09-04 10:48:53');
/*!40000 ALTER TABLE `positions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `price_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `price_history` (
  `history_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `menu_item_id` int(11) NOT NULL,
  `old_price` decimal(10,2) NOT NULL,
  `new_price` decimal(10,2) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `fk_pricehist_menuitem` (`menu_item_id`),
  KEY `fk_pricehist_user` (`changed_by`),
  CONSTRAINT `fk_pricehist_menuitem` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`item_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pricehist_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `price_history` WRITE;
/*!40000 ALTER TABLE `price_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `price_history` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `pricing_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pricing_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `pricing_method` enum('markup','margin') NOT NULL DEFAULT 'markup',
  `default_markup_percentage` decimal(6,2) NOT NULL DEFAULT 100.00,
  `default_margin_percentage` decimal(5,2) NOT NULL DEFAULT 50.00,
  `target_food_cost_percentage` decimal(5,2) NOT NULL DEFAULT 30.00,
  `rounding_increment` decimal(4,2) NOT NULL DEFAULT 1.00,
  `packaging_fee_policy` enum('included','separate','none') NOT NULL DEFAULT 'separate',
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`),
  KEY `fk_pricingsettings_user` (`updated_by`),
  CONSTRAINT `fk_pricingsettings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `pricing_settings` WRITE;
/*!40000 ALTER TABLE `pricing_settings` DISABLE KEYS */;
INSERT INTO `pricing_settings` VALUES (1,'markup',233.00,70.00,30.00,1.00,'separate',NULL,'2026-09-17 17:48:40');
/*!40000 ALTER TABLE `pricing_settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `purchase_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_items` (
  `po_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `po_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity_ordered` decimal(10,3) NOT NULL,
  `quantity_received` decimal(10,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(10,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  `vat_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'VAT for this line. 0 if the item is VAT-exempt at order time.',
  PRIMARY KEY (`po_item_id`),
  KEY `fk_poitem_item` (`item_id`),
  KEY `idx_poitems_po` (`po_id`),
  CONSTRAINT `fk_poitem_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  CONSTRAINT `fk_poitem_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `purchase_order_items` WRITE;
/*!40000 ALTER TABLE `purchase_order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_order_items` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_orders` (
  `po_id` int(11) NOT NULL AUTO_INCREMENT,
  `po_number` varchar(30) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `status` enum('draft','pending_approval','approved','ordered','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
  `order_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `subtotal_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of purchase_order_items.subtotal before VAT',
  `vat_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of purchase_order_items.vat_amount',
  `is_vat_inclusive` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Whether unit_cost figures already include VAT, per tax_settings.tax_type at order time',
  `tax_setting_id` int(11) DEFAULT NULL COMMENT 'Snapshot of which tax_settings row/rate applied to this PO',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Grand total payable to supplier, VAT included either way. See is_vat_inclusive for how it relates to subtotal_amount + vat_amount.',
  `created_by` int(11) NOT NULL,
  `is_auto_generated` tinyint(1) NOT NULL DEFAULT 0,
  `approved_by` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`po_id`),
  UNIQUE KEY `po_number` (`po_number`),
  KEY `fk_po_supplier` (`supplier_id`),
  KEY `fk_po_created_by` (`created_by`),
  KEY `fk_po_approved_by` (`approved_by`),
  KEY `idx_po_status` (`status`),
  KEY `fk_po_taxsetting` (`tax_setting_id`),
  CONSTRAINT `fk_po_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_po_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`),
  CONSTRAINT `fk_po_taxsetting` FOREIGN KEY (`tax_setting_id`) REFERENCES `tax_settings` (`tax_setting_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `purchase_orders` WRITE;
/*!40000 ALTER TABLE `purchase_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_orders` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `recipe_cost_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recipe_cost_history` (
  `history_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `menu_item_id` int(11) NOT NULL,
  `recipe_cost` decimal(10,2) NOT NULL,
  `packaging_cost` decimal(10,2) NOT NULL,
  `dine_in_cost` decimal(10,2) NOT NULL,
  `takeout_cost` decimal(10,2) NOT NULL,
  `reason` enum('fifo_change','recipe_edit','packaging_edit','manual_recalc','initial') NOT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `fk_costhist_menuitem` (`menu_item_id`),
  CONSTRAINT `fk_costhist_menuitem` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`item_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `recipe_cost_history` WRITE;
/*!40000 ALTER TABLE `recipe_cost_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `recipe_cost_history` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `recipes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recipes` (
  `recipe_id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_item_id` int(11) NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`recipe_id`),
  UNIQUE KEY `uq_recipe_menuitem` (`menu_item_id`),
  CONSTRAINT `fk_recipe_menuitem2` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`item_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `recipes` WRITE;
/*!40000 ALTER TABLE `recipes` DISABLE KEYS */;
INSERT INTO `recipes` VALUES (3,202,1,1,'2026-07-19 11:56:54','2026-07-19 11:56:54'),(54,254,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(55,255,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(56,256,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(57,257,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(58,258,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(59,259,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(60,260,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(61,261,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(62,262,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(63,263,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(64,264,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(65,265,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(66,266,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(67,267,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(68,268,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(69,269,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(70,270,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(71,271,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(72,272,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(73,273,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(74,274,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(75,275,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(76,276,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(77,277,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(78,278,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(79,279,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(80,280,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(81,281,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(82,282,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(83,283,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(84,284,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(85,285,2,1,'2026-08-04 01:53:11','2026-08-29 14:21:19'),(86,286,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(87,287,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(88,288,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(89,289,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(90,290,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(91,291,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(92,292,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(93,293,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(94,294,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(95,295,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(96,296,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(97,297,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(98,298,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11'),(99,299,1,1,'2026-08-04 01:53:11','2026-08-04 01:53:11');
/*!40000 ALTER TABLE `recipes` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `reorder_suggestions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reorder_suggestions` (
  `suggestion_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) NOT NULL,
  `item_id` int(11) NOT NULL,
  `on_hand_qty` decimal(10,3) NOT NULL,
  `on_order_qty` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'open POs (any non-received/cancelled status)',
  `available_stock` decimal(10,3) NOT NULL COMMENT 'on_hand_qty + on_order_qty',
  `forecast_demand_qty` decimal(10,3) DEFAULT NULL COMMENT 'Forecast demand over the planning horizon',
  `projected_stock_qty` decimal(10,3) DEFAULT NULL COMMENT 'Available stock minus forecast demand',
  `lead_time_demand_qty` decimal(10,3) DEFAULT NULL,
  `safety_stock_qty` decimal(10,3) DEFAULT NULL COMMENT 'z * sigma * sqrt(L+R), computed from the service-level band and backtest residuals. An OUTPUT of the sweep, never an input.',
  `reorder_level` decimal(10,3) DEFAULT NULL,
  `restock_target` decimal(10,3) DEFAULT NULL,
  `coverage_days` int(11) DEFAULT NULL,
  `raw_suggested_qty` decimal(10,3) NOT NULL DEFAULT 0.000,
  `rounded_order_qty` decimal(10,3) DEFAULT NULL COMMENT 'raw order qty rounded UP to the step for its unit type (0.25 kg / 0.25 l / 1 pc). There are no pack sizes in this design.',
  `final_suggested_qty` decimal(10,3) NOT NULL DEFAULT 0.000,
  `final_purchase_qty` decimal(10,3) DEFAULT NULL,
  `has_conversion_gap` tinyint(1) NOT NULL DEFAULT 0,
  `suppressed_by_open_commitment` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'on-hand alone would trigger, but an open PO covers the gap',
  `decision` enum('no_action','flagged_no_supplier','drafted','skipped_gated','skipped_conversion_gap') NOT NULL,
  `urgency` enum('none','normal','critical') NOT NULL DEFAULT 'none',
  `gated_reason` varchar(255) DEFAULT NULL COMMENT 'also names the specific covering PO when suppressed_by_open_commitment=1',
  `po_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`suggestion_id`),
  UNIQUE KEY `uq_reordersugg_run_item` (`run_id`,`item_id`),
  KEY `idx_reordersugg_item` (`item_id`),
  KEY `idx_reordersugg_decision` (`decision`),
  KEY `fk_reordersugg_po` (`po_id`),
  CONSTRAINT `fk_reordersugg_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  CONSTRAINT `fk_reordersugg_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reordersugg_run` FOREIGN KEY (`run_id`) REFERENCES `forecast_runs` (`run_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `reorder_suggestions` WRITE;
/*!40000 ALTER TABLE `reorder_suggestions` DISABLE KEYS */;
/*!40000 ALTER TABLE `reorder_suggestions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `reservation_advance_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservation_advance_orders` (
  `advance_order_id` int(11) NOT NULL AUTO_INCREMENT,
  `reservation_id` int(11) NOT NULL,
  `menu_item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`advance_order_id`),
  KEY `fk_advorder_menuitem` (`menu_item_id`),
  KEY `idx_advorders_reservation` (`reservation_id`),
  CONSTRAINT `fk_advorder_menuitem` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`item_id`),
  CONSTRAINT `fk_advorder_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `reservation_advance_orders` WRITE;
/*!40000 ALTER TABLE `reservation_advance_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `reservation_advance_orders` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `reservation_blackouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservation_blackouts` (
  `blackout_id` int(11) NOT NULL AUTO_INCREMENT,
  `blackout_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`blackout_id`),
  UNIQUE KEY `uq_blackout_date` (`blackout_date`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `fk_blackout_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `reservation_blackouts` WRITE;
/*!40000 ALTER TABLE `reservation_blackouts` DISABLE KEYS */;
/*!40000 ALTER TABLE `reservation_blackouts` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `reservation_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservation_payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `reservation_id` int(11) NOT NULL,
  `payment_purpose` enum('reservation_fee','advance_order_deposit','balance_payment') NOT NULL,
  `deposit_percentage` decimal(5,2) DEFAULT NULL,
  `amount_due` decimal(12,2) NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','paymongo_gcash','paymongo_checkout') NOT NULL,
  `received_by` int(11) DEFAULT NULL,
  `payment_status` enum('unpaid','pending','partial','paid','failed') NOT NULL DEFAULT 'unpaid',
  `paymongo_payment_intent_id` varchar(100) DEFAULT NULL,
  `paymongo_reference_number` varchar(100) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`payment_id`),
  KEY `fk_respayment_reservation` (`reservation_id`),
  KEY `idx_respayments_status` (`payment_status`),
  KEY `idx_respayments_receivedby` (`received_by`),
  CONSTRAINT `fk_respayment_receivedby` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_respayment_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `reservation_payments` WRITE;
/*!40000 ALTER TABLE `reservation_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `reservation_payments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservations` (
  `reservation_id` int(11) NOT NULL AUTO_INCREMENT,
  `reservation_number` varchar(30) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `reservation_date` date NOT NULL,
  `slot_id` int(11) NOT NULL,
  `number_of_guests` int(11) NOT NULL,
  `status` enum('pending','confirmed','seated','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
  `payment_type` enum('reservation_fee','advance_order_deposit') NOT NULL DEFAULT 'reservation_fee',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`reservation_id`),
  UNIQUE KEY `reservation_number` (`reservation_number`),
  KEY `fk_reservation_customer` (`customer_id`),
  KEY `fk_reservation_slot` (`slot_id`),
  KEY `idx_reservations_date_slot` (`reservation_date`,`slot_id`),
  KEY `idx_reservations_status` (`status`),
  CONSTRAINT `fk_reservation_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_reservation_slot` FOREIGN KEY (`slot_id`) REFERENCES `time_slots` (`slot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `reservations` WRITE;
/*!40000 ALTER TABLE `reservations` DISABLE KEYS */;
/*!40000 ALTER TABLE `reservations` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=CURRENT_USER*/ /*!50003 TRIGGER `trg_reservations_lead_time_insert` BEFORE INSERT ON `reservations` FOR EACH ROW
BEGIN
    CALL sp_check_reservation_lead_time(NEW.reservation_date, NEW.slot_id);
    CALL sp_check_reservation_min_guests(NEW.number_of_guests);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=CURRENT_USER*/ /*!50003 TRIGGER `trg_reservations_lead_time_update` BEFORE UPDATE ON `reservations` FOR EACH ROW
BEGIN
    IF NEW.reservation_date <> OLD.reservation_date OR NEW.slot_id <> OLD.slot_id THEN
        CALL sp_check_reservation_lead_time(NEW.reservation_date, NEW.slot_id);
    END IF;

    IF NEW.number_of_guests <> OLD.number_of_guests THEN
        CALL sp_check_reservation_min_guests(NEW.number_of_guests);
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'admin'),(4,'cashier'),(5,'customer'),(3,'manager'),(2,'owner');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `shift_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shift_templates` (
  `shift_id` int(11) NOT NULL AUTO_INCREMENT,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_night_shift` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`shift_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `shift_templates` WRITE;
/*!40000 ALTER TABLE `shift_templates` DISABLE KEYS */;
INSERT INTO `shift_templates` VALUES (5,'16:00:00','01:00:00',1,'2026-07-23 07:12:24'),(21,'06:00:00','15:00:00',0,'2026-09-17 04:04:30');
/*!40000 ALTER TABLE `shift_templates` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `slot_availability`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `slot_availability` (
  `slot_availability_id` int(11) NOT NULL AUTO_INCREMENT,
  `reservation_date` date NOT NULL,
  `slot_id` int(11) NOT NULL,
  `max_capacity` int(11) NOT NULL,
  `booked_capacity` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`slot_availability_id`),
  UNIQUE KEY `uq_slot_date` (`reservation_date`,`slot_id`),
  KEY `fk_slotavail_slot` (`slot_id`),
  CONSTRAINT `fk_slotavail_slot` FOREIGN KEY (`slot_id`) REFERENCES `time_slots` (`slot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `slot_availability` WRITE;
/*!40000 ALTER TABLE `slot_availability` DISABLE KEYS */;
/*!40000 ALTER TABLE `slot_availability` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `sss_contribution_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sss_contribution_table` (
  `sss_bracket_id` int(11) NOT NULL AUTO_INCREMENT,
  `compensation_from` decimal(12,2) NOT NULL,
  `compensation_to` decimal(12,2) DEFAULT NULL COMMENT 'NULL = no upper bound (top bracket)',
  `monthly_salary_credit` decimal(12,2) NOT NULL,
  `employee_share` decimal(12,2) NOT NULL,
  `employer_share` decimal(12,2) NOT NULL COMMENT 'Includes the Employees'' Compensation (EC) contribution',
  `total_contribution` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`sss_bracket_id`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `sss_contribution_table` WRITE;
/*!40000 ALTER TABLE `sss_contribution_table` DISABLE KEYS */;
INSERT INTO `sss_contribution_table` VALUES (1,0.00,5249.99,5000.00,250.00,510.00,760.00,'2026-07-22 13:38:56'),(11,5250.00,5749.99,5500.00,275.00,560.00,835.00,'2026-08-04 13:59:13'),(12,5750.00,6249.99,6000.00,300.00,610.00,910.00,'2026-08-04 13:59:13'),(13,6250.00,6749.99,6500.00,325.00,660.00,985.00,'2026-08-04 13:59:13'),(14,6750.00,7249.99,7000.00,350.00,710.00,1060.00,'2026-08-04 13:59:13'),(15,7250.00,7749.99,7500.00,375.00,760.00,1135.00,'2026-08-04 13:59:13'),(16,7750.00,8249.99,8000.00,400.00,810.00,1210.00,'2026-08-04 13:59:13'),(17,8250.00,8749.99,8500.00,425.00,860.00,1285.00,'2026-08-04 13:59:13'),(18,8750.00,9249.99,9000.00,450.00,910.00,1360.00,'2026-08-04 13:59:13'),(19,9250.00,9749.99,9500.00,475.00,960.00,1435.00,'2026-08-04 13:59:13'),(20,9750.00,10249.99,10000.00,500.00,1010.00,1510.00,'2026-08-04 13:59:13'),(21,10250.00,10749.99,10500.00,525.00,1060.00,1585.00,'2026-08-04 13:59:13'),(22,10750.00,11249.99,11000.00,550.00,1110.00,1660.00,'2026-08-04 13:59:13'),(23,11250.00,11749.99,11500.00,575.00,1160.00,1735.00,'2026-08-04 13:59:13'),(24,11750.00,12249.99,12000.00,600.00,1210.00,1810.00,'2026-08-04 13:59:13'),(25,12250.00,12749.99,12500.00,625.00,1260.00,1885.00,'2026-08-04 13:59:13'),(26,12750.00,13249.99,13000.00,650.00,1310.00,1960.00,'2026-08-04 13:59:13'),(27,13250.00,13749.99,13500.00,675.00,1360.00,2035.00,'2026-08-04 13:59:13'),(28,13750.00,14249.99,14000.00,700.00,1410.00,2110.00,'2026-08-04 13:59:13'),(29,14250.00,14749.99,14500.00,725.00,1460.00,2185.00,'2026-08-04 13:59:13'),(30,14750.00,15249.99,15000.00,750.00,1530.00,2280.00,'2026-08-04 13:59:13'),(31,15250.00,15749.99,15500.00,775.00,1580.00,2355.00,'2026-08-04 13:59:13'),(32,15750.00,16249.99,16000.00,800.00,1630.00,2430.00,'2026-08-04 13:59:13'),(33,16250.00,16749.99,16500.00,825.00,1680.00,2505.00,'2026-08-04 13:59:13'),(34,16750.00,17249.99,17000.00,850.00,1730.00,2580.00,'2026-08-04 13:59:13'),(35,17250.00,17749.99,17500.00,875.00,1780.00,2655.00,'2026-08-04 13:59:13'),(36,17750.00,18249.99,18000.00,900.00,1830.00,2730.00,'2026-08-04 13:59:13'),(37,18250.00,18749.99,18500.00,925.00,1880.00,2805.00,'2026-08-04 13:59:13'),(38,18750.00,19249.99,19000.00,950.00,1930.00,2880.00,'2026-08-04 13:59:13'),(39,19250.00,19749.99,19500.00,975.00,1980.00,2955.00,'2026-08-04 13:59:13'),(40,19750.00,20249.99,20000.00,1000.00,2030.00,3030.00,'2026-08-04 13:59:13'),(41,20250.00,20749.99,20500.00,1025.00,2080.00,3105.00,'2026-08-04 13:59:13'),(42,20750.00,21249.99,21000.00,1050.00,2130.00,3180.00,'2026-08-04 13:59:13'),(43,21250.00,21749.99,21500.00,1075.00,2180.00,3255.00,'2026-08-04 13:59:13'),(44,21750.00,22249.99,22000.00,1100.00,2230.00,3330.00,'2026-08-04 13:59:13'),(45,22250.00,22749.99,22500.00,1125.00,2280.00,3405.00,'2026-08-04 13:59:13'),(46,22750.00,23249.99,23000.00,1150.00,2330.00,3480.00,'2026-08-04 13:59:13'),(47,23250.00,23749.99,23500.00,1175.00,2380.00,3555.00,'2026-08-04 13:59:13'),(48,23750.00,24249.99,24000.00,1200.00,2430.00,3630.00,'2026-08-04 13:59:13'),(49,24250.00,24749.99,24500.00,1225.00,2480.00,3705.00,'2026-08-04 13:59:13'),(50,24750.00,25249.99,25000.00,1250.00,2530.00,3780.00,'2026-08-04 13:59:13'),(51,25250.00,25749.99,25500.00,1275.00,2580.00,3855.00,'2026-08-04 13:59:13'),(52,25750.00,26249.99,26000.00,1300.00,2630.00,3930.00,'2026-08-04 13:59:13'),(53,26250.00,26749.99,26500.00,1325.00,2680.00,4005.00,'2026-08-04 13:59:13'),(54,26750.00,27249.99,27000.00,1350.00,2730.00,4080.00,'2026-08-04 13:59:13'),(55,27250.00,27749.99,27500.00,1375.00,2780.00,4155.00,'2026-08-04 13:59:13'),(56,27750.00,28249.99,28000.00,1400.00,2830.00,4230.00,'2026-08-04 13:59:13'),(57,28250.00,28749.99,28500.00,1425.00,2880.00,4305.00,'2026-08-04 13:59:13'),(58,28750.00,29249.99,29000.00,1450.00,2930.00,4380.00,'2026-08-04 13:59:13'),(59,29250.00,29749.99,29500.00,1475.00,2980.00,4455.00,'2026-08-04 13:59:13'),(60,29750.00,30249.99,30000.00,1500.00,3030.00,4530.00,'2026-08-04 13:59:13'),(61,30250.00,30749.99,30500.00,1525.00,3080.00,4605.00,'2026-08-04 13:59:13'),(62,30750.00,31249.99,31000.00,1550.00,3130.00,4680.00,'2026-08-04 13:59:13'),(63,31250.00,31749.99,31500.00,1575.00,3180.00,4755.00,'2026-08-04 13:59:13'),(64,31750.00,32249.99,32000.00,1600.00,3230.00,4830.00,'2026-08-04 13:59:13'),(65,32250.00,32749.99,32500.00,1625.00,3280.00,4905.00,'2026-08-04 13:59:13'),(66,32750.00,33249.99,33000.00,1650.00,3330.00,4980.00,'2026-08-04 13:59:13'),(67,33250.00,33749.99,33500.00,1675.00,3380.00,5055.00,'2026-08-04 13:59:13'),(68,33750.00,34249.99,34000.00,1700.00,3430.00,5130.00,'2026-08-04 13:59:13'),(69,34250.00,34749.99,34500.00,1725.00,3480.00,5205.00,'2026-08-04 13:59:13'),(70,34750.00,NULL,35000.00,1750.00,3530.00,5280.00,'2026-08-04 13:59:13');
/*!40000 ALTER TABLE `sss_contribution_table` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `stock_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_adjustments` (
  `adjustment_id` int(11) NOT NULL AUTO_INCREMENT,
  `adjustment_number` varchar(30) NOT NULL,
  `item_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `direction` enum('increase','decrease') NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `reason` enum('physical_count','damaged','lost','theft','correction','supplier_correction','other') NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `adjustment_date` date NOT NULL,
  `adjusted_by` int(11) NOT NULL,
  `transaction_id` bigint(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`adjustment_id`),
  UNIQUE KEY `adjustment_number` (`adjustment_number`),
  UNIQUE KEY `transaction_id` (`transaction_id`),
  KEY `item_id` (`item_id`),
  KEY `batch_id` (`batch_id`),
  KEY `adjusted_by` (`adjusted_by`),
  KEY `adjustment_date` (`adjustment_date`),
  CONSTRAINT `fk_adj_batch` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batches` (`batch_id`),
  CONSTRAINT `fk_adj_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  CONSTRAINT `fk_adj_txn` FOREIGN KEY (`transaction_id`) REFERENCES `inventory_transactions` (`transaction_id`),
  CONSTRAINT `fk_adj_user` FOREIGN KEY (`adjusted_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `stock_adjustments` WRITE;
/*!40000 ALTER TABLE `stock_adjustments` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_adjustments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(20) NOT NULL,
  `supplier_name` varchar(150) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `tin` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`supplier_id`),
  UNIQUE KEY `uq_suppliers_supplier_code` (`supplier_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `fk_settings_user` (`updated_by`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,'restaurant_name','OPO!','Displayed on receipts and the booking site',NULL,'2026-09-17 17:48:40'),(2,'total_capacity','60','Maximum pax the restaurant can seat at once',NULL,'2026-09-17 17:48:40'),(3,'opening_time','11:00:00','Daily opening time',NULL,'2026-09-17 17:48:40'),(4,'closing_time','01:00:00','Daily closing time',NULL,'2026-09-17 17:48:40'),(5,'currency','PHP','Default currency code',NULL,'2026-09-17 17:48:40'),(6,'reservation_min_lead_hours','2','Minimum hours before a slot starts that an online reservation can still be made',NULL,'2026-09-17 17:48:40'),(7,'reservation_fee_amount','100.00','Fallback flat reservation fee',NULL,'2026-09-17 17:48:40'),(8,'advance_order_deposit_percentage','50.00','Fallback deposit %',NULL,'2026-09-17 17:48:40'),(11,'reservation_min_guests','4','Minimum number of guests (pax) accepted for a single reservation',NULL,'2026-09-17 17:48:40'),(12,'advance_order_min_amount','200.00','Minimum total (in pesos) an advance food order must reach before it can be submitted',NULL,'2026-09-17 17:48:40'),(13,'reservation_max_guests','60','Maximum guests (pax) accepted for a single reservation',NULL,'2026-09-17 17:48:40'),(14,'operating_days','1,2,3,4,5,6,7','Comma-separated ISO weekday numbers (1=Mon..7=Sun) the restaurant accepts reservations on',NULL,'2026-09-17 17:48:40'),(15,'reservation_hold_minutes','30','Minutes a pending reservation holds its slot capacity before being released',NULL,'2026-09-17 17:48:40'),(17,'reservation_max_advance_days','90','Maximum number of days in advance a customer can book an online reservation',NULL,'2026-09-17 17:48:40'),(18,'reservation_no_show_hours','10','Hours after a reservation\'s slot start before it can be considered a no-show. Not yet automatically enforced ù reserved for an upcoming staff workflow.',NULL,'2026-09-17 17:48:40'),(25,'receipt_paper_width','80mm','Thermal receipt printer paper width -- controls the printed/preview receipt width',NULL,'2026-07-20 16:44:04'),(26,'restaurant_address','19 San Juan Rd, Calamba, 4027 Laguna, Philippines','Street/city address, printed on receipts and the booking site',NULL,'2026-09-17 17:48:40'),(27,'restaurant_tin','6098-7886-8','BIR Tax Identification Number (TIN), printed on receipts',NULL,'2026-09-17 17:48:40'),(28,'last_reorder_sweep_at','2026-08-11 15:00:25','Timestamp of the last demand-forecast-driven auto purchase order sweep, used to throttle it to at most once/day',NULL,'2026-08-11 07:00:25'),(29,'last_feedback_insights_sweep_at','2026-09-17 21:55:45',NULL,NULL,'2026-09-17 13:55:45'),(30,'auto_po_forecast_window_days','90','How many trailing days of sales history the auto-generated purchase order sweep forecasts demand over (Prophet-powered, see Demand Forecasting).',NULL,'2026-09-17 17:48:40'),(31,'auto_po_enabled','1','Whether the demand-forecast-driven auto purchase order sweep is enabled at all.',NULL,'2026-09-17 17:48:40'),(35,'auto_po_sweep_hour','2','Hour of day (0-23) the auto-PO sweep is allowed to run, at earliest. Actual sweep still ticks via the existing 5-minute scheduled task.',NULL,'2026-09-17 17:48:40'),(36,'forecast_horizon_days','7','How many days ahead Prophet forecasts ingredient demand; reorder level and max level are computed from this single window',NULL,'2026-09-17 17:48:40'),(37,'shortage_notification_horizon_days','3','How many days ahead the forecast-based shortage early-warning check projects consumption; must be <= forecast_horizon_days',NULL,'2026-09-17 17:48:40'),(38,'restaurant_phone','+63 947 314 1042','Contact phone number shown to customers and given to the chatbot assistant',NULL,'2026-09-17 17:48:40'),(39,'restaurant_email','opo@gmail.com','Contact email address shown to customers and given to the chatbot assistant',NULL,'2026-09-17 17:48:40'),(41,'business_day_cutoff_time','04:00:00',NULL,NULL,'2026-08-11 13:00:56'),(42,'auto_po_draft_reminder_days','1',NULL,NULL,'2026-09-17 17:48:40'),(44,'last_costing_fifo_fingerprint','661aef19927919946fce773278e3c590','Fingerprint of current FIFO ingredient costs; changes trigger an automatic menu recost.',NULL,'2026-09-02 03:42:16'),(54,'gcash_qr_path','assets/uploads/payment/gcash_qr_6a944e63e2b661.25299552.jpg','GCash QR image shown to the customer at the POS when GCash is the payment method',NULL,'2026-09-17 17:48:40'),(56,'forecast_backtest_summary','{\r\n  \"period_start\": \"2026-05-07\",\r\n  \"period_end\": \"2026-08-11\",\r\n  \"history_days\": 97,\r\n  \"ingredients\": 50,\r\n  \"horizons\": {\r\n    \"7\": {\r\n      \"window_wape_pct\": 24.42,\r\n      \"window_baseline_wape_pct\": 20.99,\r\n      \"window_beats_baseline\": false,\r\n      \"horizon_days\": 7,\r\n      \"entities_scored\": 50,\r\n      \"folds_min\": 11,\r\n      \"folds_max\": 11,\r\n      \"actual_total\": 8352.347,\r\n      \"forecast_total\": 8218.802,\r\n      \"pooled_wape_pct\": 49.79,\r\n      \"pooled_baseline_wape_pct\": 51.18,\r\n      \"pooled_beats_baseline\": true,\r\n      \"bias_pct\": 1.6,\r\n      \"bias_direction\": \"under\",\r\n      \"per_item_mean_wape_pct\": 74.68,\r\n      \"per_item_median_wape_pct\": 71.4,\r\n      \"per_item_baseline_wape_pct\": 76.57\r\n    },\r\n    \"14\": {\r\n      \"window_wape_pct\": 23.32,\r\n      \"window_baseline_wape_pct\": 16.73,\r\n      \"window_beats_baseline\": false,\r\n      \"horizon_days\": 14,\r\n      \"entities_scored\": 50,\r\n      \"folds_min\": 5,\r\n      \"folds_max\": 5,\r\n      \"actual_total\": 7615.546,\r\n      \"forecast_total\": 7105.941,\r\n      \"pooled_wape_pct\": 50.65,\r\n      \"pooled_baseline_wape_pct\": 50.04,\r\n      \"pooled_beats_baseline\": false,\r\n      \"bias_pct\": 6.69,\r\n      \"bias_direction\": \"under\",\r\n      \"per_item_mean_wape_pct\": 76.3,\r\n      \"per_item_median_wape_pct\": 72.86,\r\n      \"per_item_baseline_wape_pct\": 76.86\r\n    }\r\n  }\r\n}','Rolling-origin backtest result for the demand forecast, written by cron/refresh_forecast_backtest.php. JSON.',NULL,'2026-08-31 04:40:01'),(57,'forecast_backtest_ran_at','2026-08-31 12:40:01','When cron/refresh_forecast_backtest.php last measured forecast accuracy.',NULL,'2026-08-31 04:40:01'),(58,'forecast_history_days','90','How many trading days the model learns from. Rolling, so it always uses the freshest 90.',NULL,'2026-09-17 17:48:40'),(59,'forecast_default_horizon','7','Which of 7 / 14 a screen opens on.',NULL,'2026-09-17 17:48:40'),(60,'closure_detect_pct','20','A day below this share of the trailing 28-day median counts as a closure and is left out of training.',NULL,'2026-09-17 17:48:40'),(61,'mix_window_weeks','8','How far back the per-weekday menu mix is measured.',NULL,'2026-09-17 17:48:40'),(62,'mix_direct_fit_min_qty','3.0','A dish selling at least this many a day also gets its own Prophet model.',NULL,'2026-09-17 17:48:40'),(63,'review_period_days','1','How long until the system looks again. One, because it sweeps daily.',NULL,'2026-09-17 17:48:40'),(85,'trend_scan_interval_hours','6','How often Trend Setter looks between scheduled runs.',NULL,'2026-09-17 17:48:40'),(86,'trend_rise_z_threshold','2.50','Rising must clear a higher bar; upward calls are wrong more often.',NULL,'2026-09-17 17:48:40'),(87,'trend_fall_z_threshold','2.00','Falling threshold.',NULL,'2026-09-17 17:48:40'),(88,'trend_rise_streak_days','4','Consecutive days before a rise is believed.',NULL,'2026-09-17 17:48:40'),(89,'trend_fall_streak_days','3','Consecutive days before a fall is believed.',NULL,'2026-09-17 17:48:40'),(90,'trend_min_delta_pct','10','Never a tiny nudge.',NULL,'2026-09-17 17:48:40'),(91,'trend_max_delta_pct','40','Never a runaway one.',NULL,'2026-09-17 17:48:40'),(92,'trend_min_avg_daily_qty','3.0','Quieter dishes are ignored; the statistics are meaningless.',NULL,'2026-09-17 17:48:40'),(93,'trend_hold_days','7','Hard expiry, if no forecast run supersedes it first.',NULL,'2026-09-17 17:48:40'),(99,'safety_stock_days','1','Safety stock, expressed as days of forecast demand. Held beyond what is needed to reach the next delivery. One number for every ingredient -- the standard inventory term, and the same idea the reorder point has always used.',NULL,'2026-09-17 17:48:40'),(102,'forecast_holdout_series','{\"ok\": true, \"horizon_days\": 14, \"trained_on_days\": 76, \"train_start\": \"2026-06-02\", \"cutoff\": \"2026-08-17\", \"test_start\": \"2026-08-18\", \"test_end\": \"2026-08-30\", \"days\": [\"2026-08-18\", \"2026-08-19\", \"2026-08-20\", \"2026-08-22\", \"2026-08-23\", \"2026-08-24\", \"2026-08-25\", \"2026-08-26\", \"2026-08-27\", \"2026-08-28\", \"2026-08-29\", \"2026-08-30\"], \"actual\": [17.0, 17.0, 18.0, 27.0, 29.0, 20.0, 20.0, 21.0, 19.0, 26.0, 29.0, 25.0], \"predicted\": [18.6, 18.43, 17.01, 28.3, 29.12, 17.08, 18.55, 18.38, 16.96, 28.42, 28.23, 29.05], \"lower\": [14.45, 14.21, 12.67, 24.01, 24.64, 13.05, 14.11, 14.22, 12.62, 24.19, 23.72, 24.64], \"upper\": [23.04, 23.3, 21.0, 32.59, 33.66, 21.48, 23.02, 22.45, 21.22, 32.66, 32.56, 33.2], \"baseline\": [18.91, 18.73, 17.27, 28.9, 28.73, 29.55, 17.36, 18.91, 18.73, 17.27, 28.9, 28.73], \"wape_pct\": 8.1, \"baseline_wape_pct\": 12.55, \"beats_baseline\": true, \"actual_total\": 268.0, \"predicted_total\": 268.13, \"inside_band\": 12, \"band_pct\": 100.0}','Hold-out backtest: what the model predicted for a past window vs what actually happened. Written by cron/refresh_forecast_holdout.php. JSON.',NULL,'2026-09-02 23:51:19'),(103,'forecast_holdout_ran_at','2026-09-03 07:51:19','When cron/refresh_forecast_holdout.php last rebuilt the forecast-vs-actual evidence.',NULL,'2026-09-02 23:51:19'),(104,'forecast_round_servings_up','1',NULL,NULL,'2026-09-03 07:43:36'),(107,'forecast_view_max_days','14','Longest horizon the Demand Forecasting page can display. Runs compute this many days so any selectable horizon has data; purchasing still sizes against forecast_horizon_days.',NULL,'2026-09-17 17:48:40'),(108,'last_costing_inputs_fingerprint','47365ad3bddd83081a31ad9a8e89fdd1','Fingerprint of current FIFO ingredient costs; changes trigger an automatic menu recost.',NULL,'2026-09-09 11:38:22');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `tax_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tax_settings` (
  `tax_setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `tax_name` varchar(50) NOT NULL DEFAULT 'VAT',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 12.00,
  `tax_type` enum('inclusive','exclusive') NOT NULL DEFAULT 'inclusive',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `effective_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tax_setting_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `tax_settings` WRITE;
/*!40000 ALTER TABLE `tax_settings` DISABLE KEYS */;
INSERT INTO `tax_settings` VALUES (1,'VAT',12.00,'inclusive',1,'2026-07-16','2026-07-16 07:32:16','2026-09-17 13:11:38');
/*!40000 ALTER TABLE `tax_settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `time_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `time_slots` (
  `slot_id` int(11) NOT NULL AUTO_INCREMENT,
  `slot_label` varchar(50) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`slot_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `time_slots` WRITE;
/*!40000 ALTER TABLE `time_slots` DISABLE KEYS */;
INSERT INTO `time_slots` VALUES (1,'10:00 AM - 11:00 AM','10:00:00','11:00:00',1),(2,'11:00 AM - 12:00 PM','11:00:00','12:00:00',1),(3,'12:00 PM - 1:00 PM','12:00:00','13:00:00',1),(4,'1:00 PM - 2:00 PM','13:00:00','14:00:00',1),(5,'5:00 PM - 6:00 PM','17:00:00','18:00:00',1),(6,'6:00 PM - 7:00 PM','18:00:00','19:00:00',1),(7,'7:00 PM - 8:00 PM','19:00:00','20:00:00',1),(8,'8:00 PM - 9:00 PM','20:00:00','21:00:00',1);
/*!40000 ALTER TABLE `time_slots` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `unit_conversions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `unit_conversions` (
  `conversion_id` int(11) NOT NULL AUTO_INCREMENT,
  `from_unit_id` int(11) NOT NULL,
  `to_unit_id` int(11) NOT NULL,
  `conversion_factor` decimal(18,6) NOT NULL,
  PRIMARY KEY (`conversion_id`),
  UNIQUE KEY `uq_unit_conversion` (`from_unit_id`,`to_unit_id`),
  KEY `fk_conv_to` (`to_unit_id`),
  CONSTRAINT `fk_conv_from` FOREIGN KEY (`from_unit_id`) REFERENCES `unit_of_measures` (`unit_id`),
  CONSTRAINT `fk_conv_to` FOREIGN KEY (`to_unit_id`) REFERENCES `unit_of_measures` (`unit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `unit_conversions` WRITE;
/*!40000 ALTER TABLE `unit_conversions` DISABLE KEYS */;
INSERT INTO `unit_conversions` VALUES (1,9,8,12.000000),(2,2,5,0.035274),(3,2,4,0.002205),(4,2,3,1000.000000),(5,2,1,0.001000),(6,1,4,2.204620),(7,1,3,1000000.000000),(8,1,2,1000.000000),(9,6,7,1000.000000),(10,4,2,453.592000),(11,4,1,0.453592),(12,3,2,0.001000),(13,3,1,0.000001),(14,7,6,0.001000),(15,5,2,28.349500),(16,8,9,0.083333);
/*!40000 ALTER TABLE `unit_conversions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `unit_of_measures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `unit_of_measures` (
  `unit_id` int(11) NOT NULL AUTO_INCREMENT,
  `unit_code` varchar(10) NOT NULL,
  `unit_name` varchar(50) NOT NULL,
  `unit_type` enum('weight','volume','count') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`unit_id`),
  UNIQUE KEY `unit_code` (`unit_code`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `unit_of_measures` WRITE;
/*!40000 ALTER TABLE `unit_of_measures` DISABLE KEYS */;
INSERT INTO `unit_of_measures` VALUES (1,'kg','Kilogram','weight',1),(2,'g','Gram','weight',1),(3,'mg','Milligram','weight',1),(4,'lb','Pound','weight',1),(5,'oz','Ounce','weight',1),(6,'l','Liter','volume',1),(7,'ml','Milliliter','volume',1),(8,'pcs','Piece','count',1),(9,'dozen','Dozen','count',1);
/*!40000 ALTER TABLE `unit_of_measures` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `email_verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `google_id` (`google_id`),
  KEY `idx_users_role` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `wastage_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wastage_records` (
  `wastage_id` int(11) NOT NULL AUTO_INCREMENT,
  `wastage_number` varchar(30) NOT NULL,
  `item_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `reason` enum('expired','spoiled','burned','overcooked','dropped','customer_complaint','quality_issue','other') NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `wastage_date` date NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `transaction_id` bigint(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`wastage_id`),
  UNIQUE KEY `wastage_number` (`wastage_number`),
  UNIQUE KEY `transaction_id` (`transaction_id`),
  KEY `item_id` (`item_id`),
  KEY `batch_id` (`batch_id`),
  KEY `recorded_by` (`recorded_by`),
  KEY `wastage_date` (`wastage_date`),
  CONSTRAINT `fk_wst_batch` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batches` (`batch_id`),
  CONSTRAINT `fk_wst_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  CONSTRAINT `fk_wst_txn` FOREIGN KEY (`transaction_id`) REFERENCES `inventory_transactions` (`transaction_id`),
  CONSTRAINT `fk_wst_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `wastage_records` WRITE;
/*!40000 ALTER TABLE `wastage_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `wastage_records` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 DROP FUNCTION IF EXISTS `fn_convert_quantity` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
DELIMITER ;;
CREATE DEFINER=CURRENT_USER FUNCTION `fn_convert_quantity`(p_qty DECIMAL(14,6), p_from_unit INT, p_to_unit INT) RETURNS decimal(14,6)
    READS SQL DATA
    DETERMINISTIC
BEGIN
    DECLARE v_factor DECIMAL(18,6);

    IF p_from_unit = p_to_unit THEN
        RETURN p_qty;
    END IF;

    SELECT conversion_factor INTO v_factor
    FROM unit_conversions
    WHERE from_unit_id = p_from_unit AND to_unit_id = p_to_unit
    LIMIT 1;

    IF v_factor IS NULL THEN
        RETURN NULL;
    END IF;

    RETURN p_qty * v_factor;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 DROP PROCEDURE IF EXISTS `sp_check_advance_order_minimum` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
DELIMITER ;;
CREATE DEFINER=CURRENT_USER PROCEDURE `sp_check_advance_order_minimum`(IN p_reservation_id INT)
BEGIN
    DECLARE v_total DECIMAL(12,2) DEFAULT 0;
    DECLARE v_min_amount DECIMAL(12,2) DEFAULT 0;

    SELECT IFNULL(SUM(subtotal), 0) INTO v_total
    FROM reservation_advance_orders WHERE reservation_id = p_reservation_id;

    SELECT CAST(setting_value AS DECIMAL(12,2)) INTO v_min_amount
    FROM system_settings WHERE setting_key = 'advance_order_min_amount';

    IF v_total > 0 AND v_total < IFNULL(v_min_amount, 0) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Advance order total is below the minimum amount allowed.';
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 DROP PROCEDURE IF EXISTS `sp_check_reservation_lead_time` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
DELIMITER ;;
CREATE DEFINER=CURRENT_USER PROCEDURE `sp_check_reservation_lead_time`(
    IN p_date DATE, IN p_slot_id INT
)
BEGIN
    DECLARE v_start_time TIME;
    DECLARE v_min_lead_hours INT DEFAULT 0;
    DECLARE v_slot_datetime DATETIME;
    DECLARE v_cutoff DATETIME;

    SELECT start_time INTO v_start_time FROM time_slots WHERE slot_id = p_slot_id;

    IF v_start_time IS NOT NULL THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_min_lead_hours
        FROM system_settings WHERE setting_key = 'reservation_min_lead_hours';

        SET v_slot_datetime = TIMESTAMP(p_date, v_start_time);
        SET v_cutoff = DATE_ADD(NOW(), INTERVAL IFNULL(v_min_lead_hours, 0) HOUR);

        IF v_slot_datetime < v_cutoff THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'This time slot no longer meets the minimum lead time for new reservations.';
        END IF;
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 DROP PROCEDURE IF EXISTS `sp_check_reservation_min_guests` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
DELIMITER ;;
CREATE DEFINER=CURRENT_USER PROCEDURE `sp_check_reservation_min_guests`(IN p_guests INT)
BEGIN
    DECLARE v_min INT DEFAULT 1;
    DECLARE v_max INT DEFAULT 999;

    SELECT CAST(setting_value AS UNSIGNED) INTO v_min FROM system_settings WHERE setting_key = 'reservation_min_guests';
    SELECT CAST(setting_value AS UNSIGNED) INTO v_max FROM system_settings WHERE setting_key = 'reservation_max_guests';

    IF p_guests < IFNULL(v_min, 1) OR p_guests > IFNULL(v_max, 999) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Guest count does not meet the minimum guest count policy for a reservation.';
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 DROP PROCEDURE IF EXISTS `sp_deduct_inventory_fifo_recipe_unit` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
DELIMITER ;;
CREATE DEFINER=CURRENT_USER PROCEDURE `sp_deduct_inventory_fifo_recipe_unit`(
    IN p_item_id INT,
    IN p_needed_qty DECIMAL(14,6),
    IN p_unit_id INT,
    IN p_reference_type VARCHAR(30),
    IN p_reference_id INT,
    IN p_performed_by INT
)
proc_body: BEGIN
    DECLARE v_base_unit_id INT;
    DECLARE v_converted_qty DECIMAL(14,6);
    DECLARE v_total_available DECIMAL(14,6);
    DECLARE v_remaining_to_deduct DECIMAL(14,6);
    DECLARE v_batch_id INT;
    DECLARE v_batch_remaining DECIMAL(14,6);
    DECLARE v_deduct_from_batch DECIMAL(14,6);
    DECLARE v_done INT DEFAULT 0;

    DECLARE batch_cursor CURSOR FOR
        SELECT batch_id, quantity_remaining
        FROM inventory_batches
        WHERE item_id = p_item_id AND quantity_remaining > 0
        ORDER BY received_date ASC, batch_id ASC
        FOR UPDATE;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    SELECT base_unit_id INTO v_base_unit_id FROM inventory_items WHERE item_id = p_item_id;

    IF v_base_unit_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unknown inventory item';
    END IF;

    SET v_converted_qty = fn_convert_quantity(p_needed_qty, p_unit_id, v_base_unit_id);

    IF v_converted_qty IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No unit conversion path available for this ingredient';
    END IF;

    SELECT COALESCE(SUM(quantity_remaining), 0) INTO v_total_available
    FROM inventory_batches WHERE item_id = p_item_id;

    IF v_total_available < v_converted_qty THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient stock to deduct requested quantity';
    END IF;

    SET v_remaining_to_deduct = v_converted_qty;

    OPEN batch_cursor;
    read_loop: LOOP
        FETCH batch_cursor INTO v_batch_id, v_batch_remaining;
        IF v_done = 1 OR v_remaining_to_deduct <= 0 THEN
            LEAVE read_loop;
        END IF;

        IF v_batch_remaining >= v_remaining_to_deduct THEN
            SET v_deduct_from_batch = v_remaining_to_deduct;
        ELSE
            SET v_deduct_from_batch = v_batch_remaining;
        END IF;

        UPDATE inventory_batches
        SET quantity_remaining = quantity_remaining - v_deduct_from_batch
        WHERE batch_id = v_batch_id;

        INSERT INTO inventory_transactions
            (item_id, batch_id, transaction_type, quantity, reference_type, reference_id, performed_by)
        VALUES
            (p_item_id, v_batch_id, 'stock_out', v_deduct_from_batch, p_reference_type, p_reference_id, p_performed_by);

        SET v_remaining_to_deduct = v_remaining_to_deduct - v_deduct_from_batch;
    END LOOP;
    CLOSE batch_cursor;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50001 DROP VIEW IF EXISTS `inventory_stock_status`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED DEFINER=CURRENT_USER SQL SECURITY DEFINER VIEW `inventory_stock_status` AS select `i`.`item_id` AS `item_id`,coalesce(`b`.`current_stock`,0) AS `current_stock`,case when `i`.`is_active` = 0 then 'inactive' when coalesce(`b`.`current_stock`,0) <= 0 then 'out_of_stock' when `i`.`critical_level` is not null and coalesce(`b`.`current_stock`,0) <= `i`.`critical_level` then 'critical' when coalesce(`b`.`current_stock`,0) <= `i`.`reorder_level` then 'low' else 'ok' end collate utf8mb4_unicode_ci AS `stock_status` from (`inventory_items` `i` left join (select `inventory_batches`.`item_id` AS `item_id`,sum(`inventory_batches`.`quantity_remaining`) AS `current_stock` from `inventory_batches` group by `inventory_batches`.`item_id`) `b` on(`b`.`item_id` = `i`.`item_id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

