CREATE TABLE `page` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`parent_id` INT UNSIGNED NULL,
	`position` INT NOT NULL DEFAULT 0,
	`in_menu` TINYINT(1) NOT NULL DEFAULT 0,
	`created_at` DATETIME NOT NULL,
	`updated_at` DATETIME NOT NULL,
	PRIMARY KEY (`id`),
	KEY `idx_page_parent` (`parent_id`),
	CONSTRAINT `fk_page_parent` FOREIGN KEY (`parent_id`) REFERENCES `page` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
