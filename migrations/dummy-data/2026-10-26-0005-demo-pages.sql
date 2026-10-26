-- Demo pages. Rows are referenced through variables holding the generated ids, never fixed ids.
SET @now = UTC_TIMESTAMP();

INSERT INTO `page` (`parent_id`, `position`, `in_menu`, `created_at`, `updated_at`) VALUES (NULL, 0, 1, @now, @now);
SET @uvod = LAST_INSERT_ID();
INSERT INTO `page` (`parent_id`, `position`, `in_menu`, `created_at`, `updated_at`) VALUES (NULL, 1, 1, @now, @now);
SET @o_nas = LAST_INSERT_ID();
INSERT INTO `page` (`parent_id`, `position`, `in_menu`, `created_at`, `updated_at`) VALUES (@o_nas, 0, 1, @now, @now);
SET @tym = LAST_INSERT_ID();
INSERT INTO `page` (`parent_id`, `position`, `in_menu`, `created_at`, `updated_at`) VALUES (NULL, 2, 1, @now, @now);
SET @kontakt = LAST_INSERT_ID();
INSERT INTO `page` (`parent_id`, `position`, `in_menu`, `created_at`, `updated_at`) VALUES (NULL, 3, 1, @now, @now);
SET @rozpracovano = LAST_INSERT_ID();

INSERT INTO `page_translation` (`page_id`, `locale`, `title`, `slug`, `path`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@uvod, 'cs', 'Úvod', 'uvod', 'uvod', 'Toto je ukázková úvodní stránka.\n\n## Kam dál\n\nPodívejte se, [kdo jsme](/o-nas), nebo nás [kontaktujte](/kontakt).', 'Ukázková úvodní stránka.', 'published', @now, @now),
(@o_nas, 'cs', 'O nás', 'o-nas', 'o-nas', 'Jsme malý ukázkový web.\n\n## Lidé\n\nSeznamte se s naším [týmem](/tym).', 'Informace o ukázkovém webu.', 'published', @now, @now),
(@o_nas, 'en', 'About us', 'about-us', 'about-us', 'We are a small demo site.\n\n## In Czech\n\nRead the [Czech version](/o-nas).', 'About the demo site.', 'published', @now, @now),
(@tym, 'cs', 'Tým', 'tym', 'tym', 'Tým tvoří ukázkoví členové.\n\n## Další informace\n\nVraťte se na stránku [O nás](/o-nas).', 'Ukázkový tým.', 'published', @now, @now),
(@kontakt, 'cs', 'Kontakt', 'kontakt', 'kontakt', 'Ozvěte se nám, rádi odpovíme.\n\n## E-mail\n\nNapište nám na [info@example.com](mailto:info@example.com).', 'Kontaktní údaje.', 'published', @now, @now),
(@rozpracovano, 'cs', 'Rozpracováno', 'rozpracovano', 'rozpracovano', 'Tato stránka je koncept.\n\n## Poznámka\n\nZpět na [úvod](/).', '', 'draft', @now, @now);

INSERT INTO `setting` (`key`, `locale`, `value`) VALUES ('homepage', '', @uvod);

INSERT INTO `path_redirect` (`locale`, `old_path`, `target_type`, `target_id`, `created_at`) VALUES ('cs', 'o-firme', 'page', @o_nas, @now);
