CREATE TABLE `path_redirect` (
	`locale` VARCHAR(10) NOT NULL,
	`old_path` VARCHAR(500) NOT NULL,
	`target_type` ENUM('page') NOT NULL,
	`target_id` INT UNSIGNED NOT NULL,
	`created_at` DATETIME NOT NULL,
	PRIMARY KEY (`locale`, `old_path`),
	KEY `idx_path_redirect_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
