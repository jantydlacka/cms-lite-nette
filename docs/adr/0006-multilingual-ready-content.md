# 0006 Content is ready for several languages

## Context

The first sites are Czech only, but multilingual content will come. Adding languages to a schema, a router and URLs that assumed one language later means migrating data and changing every lookup. Hard-coding Czech anywhere (links, segments, the admin) would close that door.

## Decision

- **Languages come from configuration**: `cms: defaultLocale` (default `cs`) and `cms: locales` (default `[cs]`), validated in `app/Core/DI/CmsExtension.php`; `config/demo.neon` adds `en` for the demo data. Links and lookups take the language from there; the only codes written in the code are the defaults, the plural rules of `cs` and `en` (`app/Core/Localization/PluralRules.php`) and the segment map below.
- **One shared structure, a translation row per language**: `page` holds the tree, `page_translation` the content per language (`PRIMARY KEY (page_id, locale)`, `UNIQUE (locale, path)`); likewise `news` and `news_translation` (`UNIQUE (locale, slug)`). The draft/published status lives on the translation, so each language is published separately; the publication date of a news item is shared. Settings carry a `locale` column where an empty string means "any language" (`PRIMARY KEY (key, locale)`; not `NULL`, which MySQL would not hold unique).
- **The locale is a parameter of the repository ports** (`findPublishedByPath(string $locale, string $path)`, `findVisible(string $locale, …)`), and of the services' commands.
- **How a URL names its language is a strategy behind one interface**, asked only by the router and the 404 page: `LocaleStrategy` (`app/Core/Routing/LocaleStrategy.php`). Today's `PrefixLocaleStrategy` leaves the default language without a prefix (`/o-nas`) and puts every other one under its code (`/en/about-us`).
- **No fallback language**: a page or news item without a published translation in the requested language is 404, including a language's homepage at its root (`/en/`).
- **Fixed segments are translated**: the news list is `/novinky?strana=N` in Czech and `/en/news?page=N` in English (`app/Model/News/NewsSegments.php`); every segment of every language is reserved for pages (see [0003](0003-flat-paths-materialized-path-and-redirects.md)).
- **The administration has no language prefix** and runs in the default language; a page or news item has one tab per configured language, and one save stores one language.
- **UI texts** go through `Nette\Localization\Translator`, implemented over `lang/cs.neon` and `lang/en.neon` with plural rules per language (`app/Core/Localization/PluralRules.php`, cs and en; `NeonTranslator.php` beside it).

## Consequences

- `NewsSegments` knows two sets: Czech, and English for every other language. A third language today gets the English segments; giving it its own means adding them there.
- Not built yet: a language switcher on the website, `hreflang` links, per-language site settings. Other strategies (a domain per language, unprefixed segments per language) would be new `LocaleStrategy` implementations; the unprefixed one would also need globally unique slugs.
- Changing the default language changes the URLs of the whole site; the redirect for that would be a router rule, not a record per page.
- Tests: `tests/Presentation/Front/RoutingTest.phpt` ("/en/about-us renders the English translation in English", "/en/ without an English homepage is not found", "a Czech path under /en/ is not found, without a fallback language", "the English 404 page is in English"), `tests/Presentation/Front/NewsPresenterTest.phpt` ("the English list shows only English items, under its own segment"), `tests/Model/News/NewsSegmentsTest.phpt`, `tests/Model/Page/ReservedSegmentsTest.phpt`, `tests/Presentation/Admin/PageEditTest.phpt` ("with one language there are no language tabs", "the homepage shows the languages without a published translation"), `tests/Spec/TranslationParityTest.phpt`, `tests/Core/DI/CmsExtensionTest.phpt`.
