-- CPM Raffle reset import for XAMPP
-- Generated from project schema usage on 2026-07-04
-- Scope:
-- 1) Preserve: users, system_settings
-- 2) Reset event data: tickets, generated_tickets, buyers, ticket_assignments,
--    ticket_activations, ticket_purchase_requests, chapter_allocations

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `rafflev2` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `rafflev2`;

-- --------------------------------------------------------
-- Core account table (preserved)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'admin',
  `email` VARCHAR(150) NULL,
  `chapter_name` VARCHAR(120) NULL,
  `profile_picture` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Ticket + activation tables (reset)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tickets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `ticket_number` VARCHAR(80) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `full_name` VARCHAR(120) NULL,
  `email` VARCHAR(150) NULL,
  `contact_number` VARCHAR(30) NULL,
  `instruction_link` VARCHAR(1024) NULL,
  `verification_link` VARCHAR(1024) NULL,
  `assigned_chapter_id` INT(11) NULL,
  `chapter_assigned_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tickets_ticket_number` (`ticket_number`),
  KEY `idx_tickets_status` (`status`),
  KEY `idx_tickets_created_at` (`created_at`),
  KEY `idx_tickets_assigned_chapter_id` (`assigned_chapter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `generated_tickets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `qr_code_id` INT(11) NULL,
  `ticket_number` VARCHAR(80) NOT NULL,
  `instruction_link` VARCHAR(1024) NULL,
  `verification_link` VARCHAR(1024) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `download` VARCHAR(10) NOT NULL DEFAULT 'no',
  `download_count` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_generated_tickets_ticket_number` (`ticket_number`),
  KEY `idx_generated_tickets_qr_code_id` (`qr_code_id`),
  KEY `idx_generated_tickets_download` (`download`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ticket_activations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `ticket_number` VARCHAR(80) NOT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'activated',
  `activation_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_activations_ticket_number` (`ticket_number`),
  KEY `idx_ticket_activations_datetime` (`activation_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Buyer + assignment tables (reset)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buyers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `buyer_type` VARCHAR(20) NOT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `contact` VARCHAR(30) NOT NULL,
  `chapter` VARCHAR(120) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `email_sent_at` DATETIME NULL,
  `activation_selector` VARCHAR(32) NULL,
  `activation_token_hash` VARCHAR(64) NULL,
  `activation_token_expires_at` DATETIME NULL,
  `activation_token_last_used_at` DATETIME NULL,
  `activation_portal_sent_at` DATETIME NULL,
  `activation_completed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_buyers_activation_selector` (`activation_selector`),
  KEY `idx_buyers_status` (`status`),
  KEY `idx_buyers_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ticket_assignments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `buyer_id` INT(11) NOT NULL,
  `ticket_number` VARCHAR(80) NOT NULL,
  `assignment_mode` VARCHAR(20) NOT NULL,
  `assigned_by_admin_id` INT(11) NULL,
  `assignment_status` VARCHAR(20) NOT NULL DEFAULT 'assigned',
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_assignments_ticket_number` (`ticket_number`),
  KEY `idx_ticket_assignments_buyer_id` (`buyer_id`),
  CONSTRAINT `fk_ticket_assignments_buyer`
    FOREIGN KEY (`buyer_id`) REFERENCES `buyers`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chapter_allocations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `chapter_id` INT(11) NOT NULL,
  `admin_id` INT(11) NOT NULL,
  `start_ticket` VARCHAR(80) NOT NULL,
  `end_ticket` VARCHAR(80) NOT NULL,
  `allocated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chapter_allocations_chapter_id` (`chapter_id`),
  KEY `idx_chapter_allocations_admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Onsite purchase tables
-- system_settings is preserved, purchase requests are reset
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`)
VALUES ('onsite_payment_info', 'GCash: 0917-123-4567\nBank: BDO - 1234567890 (PSME Inc.)');

CREATE TABLE IF NOT EXISTS `ticket_purchase_requests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `contact_number` VARCHAR(50) NOT NULL,
  `chapter` VARCHAR(100) NOT NULL,
  `ticket_quantity` INT(11) NOT NULL,
  `proof_of_payment_url` VARCHAR(1024) NOT NULL,
  `drive_file_id` VARCHAR(255) NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by_admin_id` INT(11) NULL,
  `assigned_tickets_range` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_purchase_status_created` (`status`, `created_at`),
  KEY `idx_ticket_purchase_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Email template tables
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_template_assignment` (
  `id` INT(11) NOT NULL PRIMARY KEY,
  `format` VARCHAR(10) NOT NULL DEFAULT 'html',
  `subject` TEXT NOT NULL,
  `body_html` MEDIUMTEXT NOT NULL,
  `body_text` MEDIUMTEXT NOT NULL,
  `attach_ticket_layout` TINYINT(1) NOT NULL DEFAULT 0,
  `attachment_path` VARCHAR(255) NULL,
  `attachment_filename` VARCHAR(255) NULL,
  `updated_by_admin_id` INT(11) NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `email_template_chapter_invitation` (
  `id` INT(11) NOT NULL PRIMARY KEY,
  `format` VARCHAR(10) NOT NULL DEFAULT 'html',
  `subject` TEXT NOT NULL,
  `body_html` MEDIUMTEXT NOT NULL,
  `body_text` MEDIUMTEXT NOT NULL,
  `attach_ticket_layout` TINYINT(1) NOT NULL DEFAULT 0,
  `attachment_path` VARCHAR(255) NULL,
  `attachment_filename` VARCHAR(255) NULL,
  `updated_by_admin_id` INT(11) NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `email_template_activation_thankyou` (
  `id` INT(11) NOT NULL PRIMARY KEY,
  `format` VARCHAR(10) NOT NULL DEFAULT 'html',
  `subject` TEXT NOT NULL,
  `body_html` MEDIUMTEXT NOT NULL,
  `body_text` MEDIUMTEXT NOT NULL,
  `attach_ticket_layout` TINYINT(1) NOT NULL DEFAULT 0,
  `attachment_path` VARCHAR(255) NULL,
  `attachment_filename` VARCHAR(255) NULL,
  `updated_by_admin_id` INT(11) NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `email_template_winner_contact` (
  `id` INT(11) NOT NULL PRIMARY KEY,
  `format` VARCHAR(10) NOT NULL DEFAULT 'html',
  `subject` TEXT NOT NULL,
  `body_html` MEDIUMTEXT NOT NULL,
  `body_text` MEDIUMTEXT NOT NULL,
  `attach_ticket_layout` TINYINT(1) NOT NULL DEFAULT 0,
  `attachment_path` VARCHAR(255) NULL,
  `attachment_filename` VARCHAR(255) NULL,
  `updated_by_admin_id` INT(11) NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed minimal defaults only if empty
INSERT IGNORE INTO `email_template_assignment`
(`id`,`format`,`subject`,`body_html`,`body_text`,`attach_ticket_layout`,`attachment_path`,`attachment_filename`,`updated_by_admin_id`)
VALUES
(1,'html','Your Ticket Assignment - {{ticket_count}} Ticket(s)','<p>Hello {{full_name}},</p><p>Your ticket assignment is ready.</p>','Hello {{full_name}}, Your ticket assignment is ready.',0,NULL,NULL,NULL);

INSERT IGNORE INTO `email_template_chapter_invitation`
(`id`,`format`,`subject`,`body_html`,`body_text`,`attach_ticket_layout`,`attachment_path`,`attachment_filename`,`updated_by_admin_id`)
VALUES
(1,'html','Action Required: {{ticket_count}} Tickets Assigned to {{chapter_name}} Chapter - Please Distribute','<p>Hello {{chapter_name}} President,</p><p>Your chapter portal account is ready.</p>','Hello {{chapter_name}} President, your chapter portal account is ready.',0,NULL,NULL,NULL);

INSERT IGNORE INTO `email_template_activation_thankyou`
(`id`,`format`,`subject`,`body_html`,`body_text`,`attach_ticket_layout`,`attachment_path`,`attachment_filename`,`updated_by_admin_id`)
VALUES
(1,'html','Thank You for Activating {{activated_ticket_count}} Ticket(s)','<p>Hello {{full_name}},</p><p>Thank you for activating your raffle ticket(s).</p>','Hello {{full_name}}, thank you for activating your raffle ticket(s).',0,NULL,NULL,NULL);

INSERT IGNORE INTO `email_template_winner_contact`
(`id`,`format`,`subject`,`body_html`,`body_text`,`attach_ticket_layout`,`attachment_path`,`attachment_filename`,`updated_by_admin_id`)
VALUES
(1,'html','Winner Notification - {{prize_name}}','<p>Hello {{full_name}},</p><p>Your ticket has been selected for <strong>{{prize_name}}</strong>.</p>','Hello {{full_name}}, your ticket has been selected for {{prize_name}}.',0,NULL,NULL,NULL);

-- --------------------------------------------------------
-- Reset event transactions (users/system_settings preserved)
-- --------------------------------------------------------
TRUNCATE TABLE `generated_tickets`;
TRUNCATE TABLE `ticket_activations`;
TRUNCATE TABLE `ticket_assignments`;
TRUNCATE TABLE `buyers`;
TRUNCATE TABLE `chapter_allocations`;
TRUNCATE TABLE `tickets`;
TRUNCATE TABLE `ticket_purchase_requests`;

SET FOREIGN_KEY_CHECKS = 1;

-- Ticket numbering format for this build is enforced in code:
-- PSMECPMXWEN_00001
