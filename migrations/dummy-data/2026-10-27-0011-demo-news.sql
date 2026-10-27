-- Demo news. Rows are referenced through variables holding the generated ids, never fixed ids.
SET @now = UTC_TIMESTAMP();
SET @author = (SELECT `id` FROM `user` WHERE `email` = 'admin@example.com');

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (@now - INTERVAL 1 DAY, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Spuštění ukázkového webu', 'spusteni-ukazkoveho-webu', 'Ukázkový web je v provozu a čeká na první čtenáře.', '## Co je nového

Web jsme právě spustili. Postupně zde přibudou další texty.', 'Zprávy najdete na jednom místě.', 'published', @now, @now),
(@n, 'en', 'Launch of the demo site', 'launch-of-the-demo-site', 'The demo site is up and waiting for its first readers.', '## Details

This is an invented demo item. Read the Czech version for more.', 'The demo site is up and waiting for its first readers.', 'published', @now, @now);

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (@now - INTERVAL 2 DAY, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Nový přehled novinek', 'novy-prehled-novinek', 'Novinky jsou nově řazeny od nejnovějších a stránkují se po deseti.', '## Jak to funguje

Nejnovější zprávy jsou nahoře, starší najdete na další straně.', 'Přehled novinek.', 'published', @now, @now),
(@n, 'en', 'A new news overview', 'a-new-news-overview', 'News is now sorted from the newest and split into pages of ten.', '## Details

This is an invented demo item. Read the Czech version for more.', 'News is now sorted from the newest and split into pages of ten.', 'published', @now, @now);

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (@now - INTERVAL 3 DAY, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Úprava kontaktních údajů', 'uprava-kontaktnich-udaju', 'Kontaktní stránku jsme aktualizovali, najdete na ní nový e-mail.', '## Co se změnilo

Starý kontaktní formulář nahradila jednoduchá adresa.', 'Aktualizované kontakty.', 'published', @now, @now),
(@n, 'en', 'Contact details updated', 'contact-details-updated', 'The contact page has been updated and lists a new e-mail address.', '## Details

This is an invented demo item. Read the Czech version for more.', 'The contact page has been updated and lists a new e-mail address.', 'published', @now, @now);

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (@now - INTERVAL 4 DAY, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Tipy pro psaní textů', 'tipy-pro-psani-textu', 'Krátké rady, jak psát srozumitelné a stručné články.', '## První rada

Pište krátce a nejdůležitější věc uveďte na začátku.', 'Rady pro autory.', 'published', @now, @now);

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (@now - INTERVAL 5 DAY, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Jak funguje stránkování', 'jak-funguje-strankovani', 'Starší zprávy jsou na dalších stranách přehledu.', '## Starší zprávy

Pod posledním článkem najdete odkaz na starší novinky.', 'Vysvětlení stránkování.', 'published', @now, @now);

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (@now - INTERVAL 6 DAY, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Práce s nadpisy', 'prace-s-nadpisy', 'Správné nadpisy usnadňují čtení i orientaci na stránce.', '## Úrovně nadpisů

Hlavní nadpis stránky vytváří systém, v textu začínejte druhou úrovní.', 'Použití nadpisů.', 'published', @now, @now);

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (@now - INTERVAL 7 DAY, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Plánované zveřejnění', 'planovane-zverejneni', 'Novinku lze připravit dopředu a zveřejnit později.', '## Postup

Stačí nastavit budoucí datum zveřejnění.', 'Plánování novinek.', 'published', @now, @now);

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (@now - INTERVAL 8 DAY, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Koncepty a revize', 'koncepty-a-revize', 'Rozepsané texty zůstávají skryté, dokud je nezveřejníte.', '## Skryté texty

Koncept uvidí jen přihlášení správci.', 'Koncepty.', 'published', @now, @now);

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (@now - INTERVAL 9 DAY, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Dvojjazyčný obsah', 'dvojjazycny-obsah', 'Každou novinku lze napsat česky i anglicky.', '## Překlady

Každý jazyk má vlastní titulek, adresu a text.', 'Překlady novinek.', 'published', @now, @now);

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (@now - INTERVAL 10 DAY, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Údržba v neděli', 'udrzba-v-nedeli', 'V neděli ráno krátce omezíme provoz kvůli údržbě.', '## Termín

Výpadek by neměl trvat déle než několik minut.', 'Plánovaná údržba.', 'published', @now, @now);

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (@now - INTERVAL 11 DAY, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Změna adres článků', 'zmena-adres-clanku', 'Po změně adresy staré odkazy dál fungují.', '## Staré odkazy

Starou adresu systém automaticky přesměruje na novou.', 'Přesměrování odkazů.', 'published', @now, @now);

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (@now - INTERVAL 12 DAY, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Poděkování čtenářům', 'podekovani-ctenarum', 'Děkujeme všem, kdo si ukázkový web prohlédli.', '## Na závěr

Budeme rádi za každou zpětnou vazbu.', 'Poděkování.', 'published', @now, @now);

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (NULL, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Rozpracovaná novinka', 'rozpracovana-novinka', 'Tento text je koncept a na webu zatím není vidět.', '## Poznámka

Dokončete text a novinku zveřejněte.', '', 'draft', @now, @now);

INSERT INTO `news` (`published_at`, `author_id`, `created_at`, `updated_at`) VALUES (@now + INTERVAL 30 DAY, @author, @now, @now);
SET @n = LAST_INSERT_ID();

INSERT INTO `news_translation` (`news_id`, `locale`, `title`, `slug`, `perex`, `content`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(@n, 'cs', 'Připravovaná novinka', 'pripravovana-novinka', 'Tato novinka se na webu objeví za třicet dní.', '## Naplánováno

Překlad je zveřejněný, čeká jen na datum.', '', 'published', @now, @now);
