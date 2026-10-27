ALTER TABLE `page_translation` DROP INDEX `uq_page_translation_path`, ADD UNIQUE KEY `uq_page_translation_path` (`locale`, `path`(200));
ALTER TABLE `page` ADD INDEX `idx_page_order` (`position` DESC) INVISIBLE, ADD INDEX `idx_page_expr` ((`position` + 1));
