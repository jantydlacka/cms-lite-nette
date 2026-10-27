CREATE TABLE `login_attempt` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`email` VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	`ip` VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`attempted_at` DATETIME NOT NULL,
	PRIMARY KEY (`id`),
	KEY `idx_login_attempt_pair` (`email`, `ip`, `attempted_at`),
	KEY `idx_login_attempt_ip` (`ip`, `attempted_at`),
	KEY `idx_login_attempt_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
