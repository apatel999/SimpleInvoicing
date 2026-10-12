-- =============================================================================
-- SimpleInvoicing — MySQL / MariaDB schema
-- Compatible with typical free PHP hosting (MySQL 5.7+ / MariaDB 10.2+)
--
-- How to install (phpMyAdmin):
--   1. Open your assigned database (do not CREATE DATABASE — hosts provide one)
--   2. Import this file, or paste it into the SQL tab and run
--
-- Default login after seed:
--   Email:    admin@example.com
--   Password: admin123
--   Change this immediately after first login.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `invoice_items`;
DROP TABLE IF EXISTS `invoices`;
DROP TABLE IF EXISTS `email_templates`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- Login / users
-- -----------------------------------------------------------------------------
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'admin',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Customers
-- -----------------------------------------------------------------------------
CREATE TABLE `customers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_code` VARCHAR(30) NULL DEFAULT NULL,
  `name` VARCHAR(150) NOT NULL,
  `company` VARCHAR(150) NULL DEFAULT NULL,
  `email` VARCHAR(190) NULL DEFAULT NULL,
  `phone` VARCHAR(50) NULL DEFAULT NULL,
  `address_line1` VARCHAR(200) NULL DEFAULT NULL,
  `address_line2` VARCHAR(200) NULL DEFAULT NULL,
  `city` VARCHAR(100) NULL DEFAULT NULL,
  `state` VARCHAR(100) NULL DEFAULT NULL,
  `postal_code` VARCHAR(20) NULL DEFAULT NULL,
  `country` VARCHAR(100) NULL DEFAULT NULL,
  `tax_number` VARCHAR(80) NULL DEFAULT NULL,
  `notes` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_code` (`customer_code`),
  KEY `idx_customers_name` (`name`),
  KEY `idx_customers_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Invoices
-- status: draft | sent | viewed | partial | paid | overdue | cancelled
-- -----------------------------------------------------------------------------
CREATE TABLE `invoices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` VARCHAR(40) NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `created_by` INT UNSIGNED NULL DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `issue_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_type` VARCHAR(10) NOT NULL DEFAULT 'fixed',
  `discount_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency_code` VARCHAR(3) NOT NULL DEFAULT 'USD',
  `notes` TEXT NULL,
  `terms` TEXT NULL,
  `sent_at` DATETIME NULL DEFAULT NULL,
  `paid_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoices_number` (`invoice_number`),
  KEY `idx_invoices_customer` (`customer_id`),
  KEY `idx_invoices_status` (`status`),
  KEY `idx_invoices_due_date` (`due_date`),
  KEY `idx_invoices_issue_date` (`issue_date`),
  CONSTRAINT `fk_invoices_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_invoices_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Invoice line items
-- -----------------------------------------------------------------------------
CREATE TABLE `invoice_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  `line_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_items_invoice` (`invoice_id`),
  CONSTRAINT `fk_invoice_items_invoice`
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Payments
-- method: cash | check | bank_transfer | credit_card | paypal | other
-- -----------------------------------------------------------------------------
CREATE TABLE `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `payment_date` DATE NOT NULL,
  `method` VARCHAR(30) NOT NULL DEFAULT 'other',
  `reference` VARCHAR(100) NULL DEFAULT NULL,
  `notes` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payments_invoice` (`invoice_id`),
  KEY `idx_payments_customer` (`customer_id`),
  KEY `idx_payments_date` (`payment_date`),
  CONSTRAINT `fk_payments_invoice`
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_payments_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- App / company settings (key-value)
-- -----------------------------------------------------------------------------
CREATE TABLE `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(80) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Email templates
-- Placeholders: {{company_name}} {{customer_name}} {{invoice_number}}
--               {{invoice_total}} {{due_date}} {{issue_date}} {{payment_amount}}
--               {{balance_due}} {{invoice_link}}
-- -----------------------------------------------------------------------------
CREATE TABLE `email_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(50) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `subject` VARCHAR(200) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_templates_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Seed data
-- =============================================================================

INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `is_active`)
VALUES (
  'Administrator',
  'admin@example.com',
  '$2y$12$Dz67V4d0SqFBUEh7i62DhOv3kOmy.JMYcxnXBuvs/UtOUGdUQ/bPq',
  'admin',
  1
);

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('company_name', 'My Company'),
  ('company_email', 'billing@example.com'),
  ('company_phone', ''),
  ('company_website', ''),
  ('company_address', ''),
  ('company_city', ''),
  ('company_state', ''),
  ('company_postal_code', ''),
  ('company_country', ''),
  ('tax_id', ''),
  ('logo_path', ''),
  ('currency_code', 'USD'),
  ('currency_symbol', '$'),
  ('tax_rate', '0'),
  ('invoice_prefix', 'INV-'),
  ('next_invoice_number', '1'),
  ('invoice_number_padding', '4'),
  ('invoice_due_days', '14'),
  ('default_notes', 'Thank you for your business.'),
  ('default_terms', 'Payment is due within 14 days of the invoice date.'),
  ('date_format', 'Y-m-d'),
  ('smtp_host', ''),
  ('smtp_port', '587'),
  ('smtp_username', ''),
  ('smtp_password', ''),
  ('smtp_encryption', 'tls'),
  ('smtp_from_email', ''),
  ('smtp_from_name', ''),
  ('email_bcc', '');

INSERT INTO `email_templates` (`slug`, `name`, `subject`, `body`, `is_active`) VALUES
(
  'invoice_sent',
  'Invoice sent',
  'Invoice {{invoice_number}} from {{company_name}}',
  '<p>Dear {{customer_name}},</p>
<p>Please find invoice <strong>{{invoice_number}}</strong> for your records. A copy is included below.</p>
<p style="margin:18px 0 6px;color:#6b7280;font-size:13px">Invoice date: {{issue_date}}<br>
Amount due: <strong style="color:#111827">{{invoice_total}}</strong><br>
Due date: {{due_date}}</p>
<p style="margin:28px 0 8px"><a href="{{invoice_link}}" style="display:inline-block;background:#5851ea;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;line-height:1;padding:12px 22px;border-radius:6px">View invoice</a></p>
<p style="margin:0 0 22px;font-size:13px;color:#6b7280">Or open this link: {{invoice_link}}</p>
<p style="margin:28px 0 0">If you have questions about this invoice, reply to this email and we will be glad to help.</p>
<p style="margin:22px 0 0">Kind regards,<br>{{company_name}}</p>',
  1
),
(
  'payment_received',
  'Payment received',
  'Payment received for invoice {{invoice_number}}',
  '<p>Dear {{customer_name}},</p>
<p>Thank you. We have received your payment of <strong>{{payment_amount}}</strong> toward invoice <strong>{{invoice_number}}</strong>.</p>
<p style="margin:18px 0 6px;color:#6b7280;font-size:13px">Remaining balance: <strong style="color:#111827">{{balance_due}}</strong></p>
<p>A receipt summary is included below.</p>
<p style="margin:28px 0 0">If you have questions about this invoice, reply to this email and we will be glad to help.</p>
<p style="margin:22px 0 0">Kind regards,<br>{{company_name}}</p>',
  1
),
(
  'invoice_reminder',
  'Invoice reminder',
  'Reminder: invoice {{invoice_number}} is due {{due_date}}',
  '<p>Dear {{customer_name}},</p>
<p>This is a courtesy reminder that invoice <strong>{{invoice_number}}</strong> is due on <strong>{{due_date}}</strong>.</p>
<p style="margin:18px 0 6px;color:#6b7280;font-size:13px">Amount due: <strong style="color:#111827">{{balance_due}}</strong></p>
<p>Please arrange payment by the due date. You can review the invoice using the button below.</p>
<p style="margin:28px 0 8px"><a href="{{invoice_link}}" style="display:inline-block;background:#5851ea;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;line-height:1;padding:12px 22px;border-radius:6px">View invoice</a></p>
<p style="margin:0 0 22px;font-size:13px;color:#6b7280">Or open this link: {{invoice_link}}</p>
<p style="margin:28px 0 0">If you have questions about this invoice, reply to this email and we will be glad to help.</p>
<p style="margin:22px 0 0">Kind regards,<br>{{company_name}}</p>',
  1
),
(
  'invoice_overdue',
  'Overdue invoice',
  'Invoice {{invoice_number}} is now overdue',
  '<p>Dear {{customer_name}},</p>
<p>Invoice <strong>{{invoice_number}}</strong> is now past due. The original due date was {{due_date}}.</p>
<p style="margin:18px 0 6px;color:#6b7280;font-size:13px">Outstanding balance: <strong style="color:#111827">{{balance_due}}</strong></p>
<p>Please arrange payment at your earliest convenience. If payment has already been sent, you may disregard this notice.</p>
<p style="margin:28px 0 8px"><a href="{{invoice_link}}" style="display:inline-block;background:#5851ea;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;line-height:1;padding:12px 22px;border-radius:6px">View invoice</a></p>
<p style="margin:0 0 22px;font-size:13px;color:#6b7280">Or open this link: {{invoice_link}}</p>
<p style="margin:28px 0 0">If you have questions about this invoice, reply to this email and we will be glad to help.</p>
<p style="margin:22px 0 0">Kind regards,<br>{{company_name}}</p>',
  1
);
