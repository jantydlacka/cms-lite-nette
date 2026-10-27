CREATE TABLE `news` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`published_at` DATETIME NULL,
	`author_id` INT UNSIGNED NULL,
	`version` INT UNSIGNED NOT NULL DEFAULT 1,
	`created_at` DATETIME NOT NULL,
	`updated_at` DATETIME NOT NULL,
	PRIMARY KEY (`id`),
	KEY `idx_news_author` (`author_id`),
	CONSTRAINT `fk_news_author` FOREIGN KEY (`author_id`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `news_translation` (
	`news_id` INT UNSIGNED NOT NULL,
	`locale` VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`title` VARCHAR(200) NOT NULL,
	`slug` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`perex` VARCHAR(300) NOT NULL,
	`content` MEDIUMTEXT NOT NULL,
	`meta_description` VARCHAR(300) NOT NULL DEFAULT '',
	`status` ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
	`version` INT UNSIGNED NOT NULL DEFAULT 1,
	`created_at` DATETIME NOT NULL,
	`updated_at` DATETIME NOT NULL,
	PRIMARY KEY (`news_id`, `locale`),
	UNIQUE KEY `uq_news_translation_slug` (`locale`, `slug`),
	KEY `idx_news_translation_list` (`locale`, `status`),
	CONSTRAINT `fk_news_translation_news` FOREIGN KEY (`news_id`) REFERENCES `news` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
