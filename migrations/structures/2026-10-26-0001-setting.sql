CREATE TABLE `setting` (
	`key` VARCHAR(100) NOT NULL,
	`locale` VARCHAR(10) NOT NULL DEFAULT '',
	`value` TEXT NULL,
	PRIMARY KEY (`key`, `locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
