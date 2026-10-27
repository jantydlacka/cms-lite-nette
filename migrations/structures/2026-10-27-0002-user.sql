CREATE TABLE `user` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`email` VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	`password_hash` VARCHAR(255) NOT NULL,
	`name` VARCHAR(100) NOT NULL,
	`role` ENUM('editor','admin') NOT NULL,
	`active` TINYINT(1) NOT NULL DEFAULT 1,
	`created_at` DATETIME NOT NULL,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uniq_user_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
