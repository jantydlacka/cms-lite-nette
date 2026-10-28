-- The demo homepage lists the latest news through the news component, in both languages.
SET @uvod = (SELECT `page_id` FROM `page_translation` WHERE `locale` = 'cs' AND `slug` = 'uvod');

UPDATE `page_translation` SET `content` = CONCAT(`content`, '\n\n## Novinky\n\n::: novinky 3') WHERE `page_id` = @uvod AND `locale` = 'cs';
UPDATE `page_translation` SET `content` = CONCAT(`content`, '\n\n## News\n\n::: novinky 3') WHERE `page_id` = @uvod AND `locale` = 'en';
