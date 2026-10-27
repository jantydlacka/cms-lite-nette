-- English translation of the demo homepage; the page is found through its Czech slug.
SET @now = UTC_TIMESTAMP();
SET @uvod = (SELECT `page_id` FROM `page_translation` WHERE `locale` = 'cs' AND `slug` = 'uvod');

INSERT INTO `page_translation` (`page_id`, `locale`, `title`, `slug`, `path`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@uvod, 'en', 'Welcome', 'welcome', 'welcome', 'This is a demo homepage.\n\n## Where next\n\nSee [who we are](/en/about-us).', 'A demo homepage.', 'published', @now, @now);
