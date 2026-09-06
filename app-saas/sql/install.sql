-- =============================================================================
-- InvoiceHost — multi-tenant invoicing (copy of SimpleInvoicing)
-- One hosted app. You create tenants and assign subscriptions.
--
-- Import this file into a NEW database (do not use the original app database).
--
-- Platform login:
--   Email:    platform@example.com
--   Password: admin123
-- Demo tenant login:
--   Email:    admin@example.com
--   Password: admin123
-- Change both immediately after first login.
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
DROP TABLE IF EXISTS `subscriptions`;
DROP TABLE IF EXISTS `tenants`;
DROP TABLE IF EXISTS `plans`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `plans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `slug` VARCHAR(40) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `interval` VARCHAR(20) NOT NULL DEFAULT 'month',
  `invoice_limit` INT UNSIGNED NOT NULL DEFAULT 0,
  `customer_limit` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plans_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tenants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(80) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `notes` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenants_slug` (`slug`),
  KEY `idx_tenants_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subscriptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `plan_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'trial',
  `starts_at` DATE NOT NULL,
  `ends_at` DATE NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subscriptions_tenant` (`tenant_id`),
  CONSTRAINT `fk_subscriptions_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_subscriptions_plan`
    FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'admin',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_tenant` (`tenant_id`),
  CONSTRAINT `fk_users_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
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
  UNIQUE KEY `uq_customers_code` (`tenant_id`, `customer_code`),
  KEY `idx_customers_tenant` (`tenant_id`),
  KEY `idx_customers_name` (`name`),
  CONSTRAINT `fk_customers_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
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
  `internal_notes` TEXT NULL,
  `terms` TEXT NULL,
  `sent_at` DATETIME NULL DEFAULT NULL,
  `paid_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoices_number` (`tenant_id`, `invoice_number`),
  KEY `idx_invoices_tenant` (`tenant_id`),
  KEY `idx_invoices_customer` (`customer_id`),
  KEY `idx_invoices_status` (`status`),
  CONSTRAINT `fk_invoices_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_invoices_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_invoices_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `invoice_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `payment_date` DATE NOT NULL,
  `method` VARCHAR(30) NOT NULL DEFAULT 'other',
  `reference` VARCHAR(100) NULL DEFAULT NULL,
  `notes` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payments_tenant` (`tenant_id`),
  KEY `idx_payments_invoice` (`invoice_id`),
  CONSTRAINT `fk_payments_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_payments_invoice`
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_payments_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `setting_key` VARCHAR(80) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`tenant_id`, `setting_key`),
  CONSTRAINT `fk_settings_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `slug` VARCHAR(50) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `subject` VARCHAR(200) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_templates_slug` (`tenant_id`, `slug`),
  CONSTRAINT `fk_email_templates_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `plans` (`name`, `slug`, `price`, `interval`, `invoice_limit`, `customer_limit`, `is_active`) VALUES
  ('Trial', 'trial', 0.00, 'month', 25, 25, 1),
  ('Starter', 'starter', 29.00, 'month', 100, 100, 1),
  ('Pro', 'pro', 79.00, 'month', 0, 0, 1);

INSERT INTO `tenants` (`name`, `slug`, `status`) VALUES
  ('Demo Company', 'demo', 'active');

INSERT INTO `subscriptions` (`tenant_id`, `plan_id`, `status`, `starts_at`, `ends_at`)
VALUES (1, 1, 'trial', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY));

INSERT INTO `users` (`tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`) VALUES
(
  NULL,
  'Platform admin',
  'platform@example.com',
  '$2y$12$Dz67V4d0SqFBUEh7i62DhOv3kOmy.JMYcxnXBuvs/UtOUGdUQ/bPq',
  'platform',
  1
),
(
  1,
  'Administrator',
  'admin@example.com',
  '$2y$12$Dz67V4d0SqFBUEh7i62DhOv3kOmy.JMYcxnXBuvs/UtOUGdUQ/bPq',
  'admin',
  1
);

INSERT INTO `settings` (`tenant_id`, `setting_key`, `setting_value`) VALUES
  (1, 'company_name', 'Demo Company'),
  (1, 'company_email', 'billing@example.com'),
  (1, 'company_phone', ''),
  (1, 'company_website', ''),
  (1, 'company_address', ''),
  (1, 'company_city', ''),
  (1, 'company_state', ''),
  (1, 'company_postal_code', ''),
  (1, 'company_country', ''),
  (1, 'tax_id', ''),
  (1, 'logo_path', ''),
  (1, 'currency_code', 'USD'),
  (1, 'currency_symbol', '$'),
  (1, 'tax_rate', '0'),
  (1, 'invoice_prefix', 'INV-'),
  (1, 'next_invoice_number', '1'),
  (1, 'invoice_number_padding', '4'),
  (1, 'invoice_due_days', '14'),
  (1, 'default_notes', 'Thank you for your business.'),
  (1, 'default_terms', 'Payment is due within 14 days of the invoice date.'),
  (1, 'date_format', 'Y-m-d'),
  (1, 'smtp_host', ''),
  (1, 'smtp_port', '587'),
  (1, 'smtp_username', ''),
  (1, 'smtp_password', ''),
  (1, 'smtp_encryption', 'tls'),
  (1, 'smtp_from_email', ''),
  (1, 'smtp_from_name', ''),
  (1, 'email_bcc', '');

INSERT INTO `email_templates` (`tenant_id`, `slug`, `name`, `subject`, `body`, `is_active`) VALUES
(1, 'invoice_sent', 'Invoice sent', 'Invoice {{invoice_number}} from {{company_name}}',
'<p>Hello {{customer_name}},</p>
<p>Please find invoice <strong>{{invoice_number}}</strong> dated {{issue_date}}.</p>
<p>Amount due: <strong>{{invoice_total}}</strong><br>
Due date: {{due_date}}</p>
<p>You can view the invoice here: {{invoice_link}}</p>
<p>Thank you,<br>{{company_name}}</p>', 1),
(1, 'payment_received', 'Payment received', 'Payment received for invoice {{invoice_number}}',
'<p>Hello {{customer_name}},</p>
<p>We have received a payment of <strong>{{payment_amount}}</strong> for invoice <strong>{{invoice_number}}</strong>.</p>
<p>Remaining balance: {{balance_due}}</p>
<p>Thank you,<br>{{company_name}}</p>', 1),
(1, 'invoice_reminder', 'Invoice reminder', 'Reminder: invoice {{invoice_number}} is due on {{due_date}}',
'<p>Hello {{customer_name}},</p>
<p>This is a friendly reminder that invoice <strong>{{invoice_number}}</strong> is due on {{due_date}}.</p>
<p>Amount due: <strong>{{balance_due}}</strong></p>
<p>View invoice: {{invoice_link}}</p>
<p>Thank you,<br>{{company_name}}</p>', 1),
(1, 'invoice_overdue', 'Overdue invoice', 'Overdue: invoice {{invoice_number}}',
'<p>Hello {{customer_name}},</p>
<p>Invoice <strong>{{invoice_number}}</strong> is now overdue. The original due date was {{due_date}}.</p>
<p>Outstanding balance: <strong>{{balance_due}}</strong></p>
<p>Please arrange payment at your earliest convenience.</p>
<p>{{company_name}}</p>', 1);
