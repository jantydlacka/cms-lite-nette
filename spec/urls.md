# URLs

A site has one default language and optional further languages. A page path is a single slug (flat paths): `^[a-z0-9]+(?:-[a-z0-9]+)*$`, 1 to 100 characters.

| Path | Behaviour |
|------|-----------|
| `/` | Homepage in the default language: HTTP 200. |
| `/<locale>/` | Homepage in a further language, e.g. `/en/`: HTTP 200. |
| `/<path>` | Published page in the default language, e.g. `/o-nas`: HTTP 200, `<html lang>` is the default language. |
| `/<locale>/<path>` | Published page in a further language, e.g. `/en/about-us`: HTTP 200, `<html lang>` is that language. |
| `/_health` | Health check, see below. |

The default language has no prefix; every other language has the prefix `<locale>/`.

## Redirects

| Request | Response |
|---------|----------|
| The path of the page configured as the homepage, e.g. `/uvod` | 301 to `/` (`/en/` for a further language). |
| An old path of a renamed page (`/o-firme`) | 301 to the current path of the page; a redirect whose target is not published answers 404 until it is published again. |
| `/en` (prefix without the slash) | 301 to `/en/`. |
| Any other path with a trailing slash, e.g. `/o-nas/` | 301 to the same path without it. |

## Not found

An unknown path, a path with an invalid shape (`/O-NAS`, `/o--nas`, `/o-nas%20`), a draft page and a reserved segment answer HTTP 404 with the error page (`error.404.title`), never 500.

There is no language fallback: a page that exists only in another language is 404, e.g. `/en/o-nas` when the page has no English translation; likewise `/en/` without an English homepage.

## Reserved segments

The first segment of a page path must not be a language code or one of `admin`, `novinky`, `news`, `sitemap.xml`. Such a slug is rejected when a page is saved.

## `/_health`

Plain text, independent of the database and of the language settings.

| Condition | Response |
|-----------|----------|
| Stylesheets and scripts the layout needs are present | 200, body `ok`. |
| One of them is missing | 503, body `missing asset: <names>`. |

## Content

Page content is Markdown. The page title is the only `<h1>`; headings in the content start at `##`. Raw HTML in the content is shown as text, and links with an unsafe scheme such as `javascript:` are dropped.

## Production mode

The contract describes production behaviour (debug mode off). In debug mode an unknown path shows the debugger's error screen instead of the 404 page.
