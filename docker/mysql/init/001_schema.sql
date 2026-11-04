SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `motoboys` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) NOT NULL COMMENT 'UUID v4 exposto na API',
    `token_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 do X-Registration-Token',
    `current_step` TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT 'Proxima etapa esperada (1..4)',
    `status` ENUM('rascunho', 'pendente_analise', 'aprovado', 'reprovado') NOT NULL DEFAULT 'rascunho',
    `protocol` VARCHAR(20) NULL DEFAULT NULL COMMENT 'LMV-XXXXXX gerado ao aceitar os termos',
    `name` VARCHAR(160) NOT NULL,
    `cpf` CHAR(11) NOT NULL COMMENT 'Somente 11 digitos numericos',
    `birth_date` DATE NOT NULL,
    `email` VARCHAR(180) NOT NULL,
    `phone` VARCHAR(15) NOT NULL COMMENT 'Somente digitos (10 ou 11)',
    `password_hash` VARCHAR(255) NOT NULL,
    `plate` VARCHAR(10) NOT NULL COMMENT 'Placa normalizada ex: ABC-1D23',
    `mei_cnpj` CHAR(14) NULL DEFAULT NULL COMMENT 'Somente 14 digitos (opcional)',
    `cnh` VARCHAR(15) NULL DEFAULT NULL COMMENT 'Somente digitos (opcional)',
    `cpf_final` CHAR(11) NULL DEFAULT NULL,
    `email_final` VARCHAR(180) NULL DEFAULT NULL,
    `ip_origem` VARCHAR(45) NULL DEFAULT NULL,
    `expires_at` DATETIME NULL DEFAULT NULL COMMENT 'Expiracao automatica para rascunhos abandonados',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_motoboys_public_id` (`public_id`),
    UNIQUE KEY `uk_motoboys_protocol` (`protocol`),
    UNIQUE KEY `uk_motoboys_cpf_final` (`cpf_final`),
    UNIQUE KEY `uk_motoboys_email_final` (`email_final`),
    KEY `idx_motoboys_cpf_status` (`cpf`, `status`),
    KEY `idx_motoboys_email_status` (`email`, `status`),
    KEY `idx_motoboys_status_created` (`status`, `created_at`),
    KEY `idx_motoboys_expires` (`status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `motoboy_bank_accounts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `motoboy_id` BIGINT UNSIGNED NOT NULL,
    `bank_code` VARCHAR(10) NOT NULL,
    `bank_name` VARCHAR(120) NOT NULL,
    `agency` VARCHAR(20) NOT NULL,
    `account` VARCHAR(30) NOT NULL,
    `account_type` ENUM('Conta corrente', 'Conta poupança', 'Conta pagamento') NOT NULL DEFAULT 'Conta corrente',
    `holder_cpf` CHAR(11) NOT NULL,
    `pix_type` ENUM('CPF', 'Celular', 'E-mail', 'CNPJ', 'Chave aleatória') NOT NULL DEFAULT 'CPF',
    `pix_key` VARCHAR(180) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_bank_motoboy` (`motoboy_id`),
    CONSTRAINT `fk_bank_motoboy` FOREIGN KEY (`motoboy_id`) REFERENCES `motoboys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `motoboy_availability` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `motoboy_id` BIGINT UNSIGNED NOT NULL,
    `weekday` TINYINT UNSIGNED NOT NULL COMMENT '1=Segunda-feira ... 7=Domingo',
    `day_label` VARCHAR(30) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_avail_motoboy_day` (`motoboy_id`, `weekday`),
    CONSTRAINT `fk_avail_motoboy` FOREIGN KEY (`motoboy_id`) REFERENCES `motoboys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `terms_acceptances` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `motoboy_id` BIGINT UNSIGNED NOT NULL,
    `terms_version` VARCHAR(20) NOT NULL,
    `accepted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(500) NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_terms_motoboy` (`motoboy_id`),
    CONSTRAINT `fk_terms_motoboy` FOREIGN KEY (`motoboy_id`) REFERENCES `motoboys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `banks` (
    `code` VARCHAR(10) NOT NULL,
    `value` VARCHAR(120) NOT NULL,
    `label` VARCHAR(120) NOT NULL,
    `short_desc` VARCHAR(100) NOT NULL,
    `badge` VARCHAR(40) NULL DEFAULT NULL,
    `color` VARCHAR(20) NOT NULL DEFAULT '#1e293b',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 100,
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `banks` (`code`, `value`, `label`, `short_desc`, `badge`, `color`, `sort_order`) VALUES
('260', '260 - Nu Pagamentos (Nubank)', 'Nubank (Nu Pagamentos)', 'Conta Digital • PIX Instantâneo', 'Popular', '#820ad1', 1),
('001', '001 - Banco do Brasil', 'Banco do Brasil S.A.', 'Banco Tradicional', NULL, '#0038a8', 2),
('341', '341 - Itaú Unibanco', 'Itaú Unibanco S.A.', 'Banco Tradicional', 'Popular', '#ec7000', 3),
('104', '104 - Caixa Econômica Federal', 'Caixa Econômica Federal', 'Poupança / Caixa Tem', NULL, '#005ca9', 4),
('237', '237 - Bradesco', 'Banco Bradesco S.A.', 'Banco Tradicional', NULL, '#cc092f', 5),
('077', '077 - Banco Inter', 'Banco Inter S.A.', 'Conta Digital • Zero Taxa', 'Digital', '#ff7a00', 6),
('033', '033 - Santander', 'Banco Santander Brasil', 'Banco Tradicional', NULL, '#ec0000', 7),
('336', '336 - Banco C6', 'C6 Bank S.A.', 'Conta Digital', 'Digital', '#242424', 8),
('290', '290 - PagBank', 'PagBank (PagSeguro)', 'Conta Pagamento', 'Digital', '#00a868', 9),
('380', '380 - PicPay', 'PicPay Bank', 'Carteira Digital', NULL, '#11c76f', 10),
('323', '323 - Mercado Pago', 'Mercado Pago', 'Conta Digital', NULL, '#009ee3', 11),
('756', '756 - Sicoob', 'Sicoob (Cooperativa)', 'Sistema Cooperativo', NULL, '#003641', 12),
('748', '748 - Sicredi', 'Sicredi', 'Sistema Cooperativo', NULL, '#3fa110', 13),
('403', '403 - Cora', 'Cora Sociedade de Crédito', 'Conta PJ / MEI', 'Ideal MEI', '#fe3e6d', 14)
ON DUPLICATE KEY UPDATE
  `value` = VALUES(`value`),
  `label` = VALUES(`label`),
  `short_desc` = VALUES(`short_desc`),
  `badge` = VALUES(`badge`),
  `color` = VALUES(`color`),
  `sort_order` = VALUES(`sort_order`);

