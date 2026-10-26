CREATE TABLE `page_translation` (
	`page_id` INT UNSIGNED NOT NULL,
	`locale` VARCHAR(10) NOT NULL,
	`title` VARCHAR(200) NOT NULL,
	`slug` VARCHAR(100) NOT NULL,
	`path` VARCHAR(500) NOT NULL,
	`content` MEDIUMTEXT NOT NULL,
	`meta_description` VARCHAR(300) NOT NULL DEFAULT '',
	`status` ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
	`created_at` DATETIME NOT NULL,
	`updated_at` DATETIME NOT NULL,
	PRIMARY KEY (`page_id`, `locale`),
	UNIQUE KEY `uq_page_translation_path` (`locale`, `path`),
	CONSTRAINT `fk_page_translation_page` FOREIGN KEY (`page_id`) REFERENCES `page` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
