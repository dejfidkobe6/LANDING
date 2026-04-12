-- BeSix Platform — databázové schéma
-- Spusť jednou: mysql -u USER -p DB_NAME < db/schema.sql

CREATE TABLE IF NOT EXISTS `users` (
  `id`                   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`                 VARCHAR(120)    NOT NULL,
  `email`                VARCHAR(180)    NOT NULL,
  `password_hash`        VARCHAR(255)    NOT NULL,
  `avatar_color`         VARCHAR(10)     NOT NULL DEFAULT '#4A5340',
  `google_id`            VARCHAR(100)    DEFAULT NULL,
  `is_verified`          TINYINT(1)      NOT NULL DEFAULT 0,
  `verification_token`   VARCHAR(80)     DEFAULT NULL,
  `reset_token`          VARCHAR(80)     DEFAULT NULL,
  `reset_token_expires`  DATETIME        DEFAULT NULL,
  `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email`     (`email`),
  UNIQUE KEY `uq_google_id` (`google_id`),
  KEY `idx_verification`    (`verification_token`),
  KEY `idx_reset`           (`reset_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invitations` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(180) NOT NULL,
  `invited_by` INT UNSIGNED NOT NULL,
  `sent_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inv_email` (`email`),
  FOREIGN KEY (`invited_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_access` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `app`        VARCHAR(20)  NOT NULL,
  `role`       VARCHAR(20)  NOT NULL DEFAULT 'clen',
  `granted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_app` (`user_id`, `app`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
