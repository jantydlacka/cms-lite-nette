# URLs

A site has one default language and optional further languages. A page path is a single slug (flat paths): `^[a-z0-9]+(?:-[a-z0-9]+)*$`, 1 to 100 characters.

| Path | Behaviour |
|------|-----------|
| `/` | Homepage in the default language: HTTP 200. |
| `/<locale>/` | Homepage in a further language, e.g. `/en/`: HTTP 200. |
| `/<path>` | Published page in the default language, e.g. `/o-nas`: HTTP 200, `<html lang>` is the default language. |
| `/<locale>/<path>` | Published page in a further language, e.g. `/en/about-us`: HTTP 200, `<html lang>` is that language. |
| `/_health` | Health check, see below. |
| `/admin` | Administration dashboard, see [Administration](#administration). |
| `/admin/sign-in` | Sign-in form of the administration. |
| `/admin/pages` | Page tree of the administration, see [Pages](#pages). |

The default language has no prefix; every other language has the prefix `<locale>/`.

## Redirects

| Request | Response |
|---------|----------|
| The path of the page configured as the homepage, e.g. `/uvod` | 301 to `/` (`/en/` for a further language). |
| An old path of a renamed page (`/o-firme`) | 301 to the current path of the page; a redirect whose target is not published answers 404 until it is published again. |
| `/en` (prefix without the slash) | 301 to `/en/`. |
| Any other path with a trailing slash, e.g. `/o-nas/` | 301 to the same path without it. |

## Not found

An unknown path, a path with an invalid shape (`/O-NAS`, `/o--nas`, `/o-nas%20`), a draft page and a reserved segment answer HTTP 404 with the error page (`error.404.title`), never 500. The error page is in the language of the URL: `/en/O-NAS` gives the English page, `/O-NAS` the Czech one.

There is no language fallback: a page that exists only in another language is 404, e.g. `/en/o-nas` when the page has no English translation; likewise `/en/` without an English homepage.

## Reserved segments

The first segment of a page path must not be a language code or one of `admin`, `novinky`, `news`, `sitemap.xml`. Such a slug is rejected when a page is saved.

## Administration

The administration has no language prefix and runs in the default language; its addresses are the same in every language version.

| Request | Response |
|---------|----------|
| `/admin` signed in, allowed | 200, the dashboard. |
| `/admin` not signed in | 302 to `/admin/sign-in?backlink=<key>` with the message `admin.sign.required`, or `admin.sign.expired` after 3 hours of inactivity. The backlink is a random key of the stored request, never a URL. |
| `/admin` signed in without permission | 403 with the title `error.403.title`, in the administration layout. |
| `/admin/sign-in` signed in | 302 to `/admin`. |
| Successful sign-in | 303 back to the stored request of the backlink; an unknown or expired backlink leads to `/admin`. |
| Wrong e-mail or password | 200, the form with `admin.sign.invalid`. |
| After 5 failures of one e-mail from one IP within 15 minutes (or 20 from one IP) | 200, the form with `admin.sign.throttled`, even with the right password. |
| Sign-out | The signal `signOut!` (`/admin?do=signOut`), only by a POST from the same origin: signs out, 303 to `/admin/sign-in` with `admin.sign.outDone`. A GET is refused (405); a cross-site POST only redirects (303) and signs nobody out. |
| A sign-in POST from another site | Not processed: 303 back to the form. |
| `/admin/pages` signed in (editor or administrator) | 200, the page tree. |
| `/admin/<anything else>` | 404 in the administration layout (default language). `/administrace` is a public 404. |
| A server error under `/admin` | 500 with `error.500.title` in the administration layout; the error is logged. |

### Pages

| Request | Response |
|---------|----------|
| `/admin/pages` | The page tree: every page in tree order (parent before its children, siblings by position), indented by depth, titled in the default language or `admin.pages.missing`, with the badges `admin.pages.homepage` and `admin.pages.draft`. |
| `/admin/pages/new` | A new page: parent, menu flag and the translation in the default language. Saving creates the page as the last of its siblings, 303 to its detail with `admin.pages.created`. |
| `/admin/pages/<id>` | Detail of the page in the default language; `<id>` is digits, a page that does not exist is 404. |
| `/admin/pages/<id>/<locale>` | Detail of the page with the tab of an enabled language; any other language is 404. |
| `/admin/pages/<id>/delete` | Confirmation of the deletion of the page. |
| Page properties (POST) | **Uložit vlastnosti**: parent and menu flag with the version the form was rendered from: 303 back with `admin.pages.saved`; a changed version gives `admin.pages.stale`, a parent that is the page itself or its descendant `admin.pages.invalidParent`, a parent that does not exist (forged or deleted meanwhile) `admin.pages.parentGone`, a value outside a dropdown `admin.form.invalidChoice`; nothing is saved then and the form keeps the sent values. |
| Translation (POST) | **Uložit text** saves the translation of the tab with its version (empty for a missing one): 303 back with `admin.pages.saved`; a refused address is an error at the address field (`admin.pages.slug.*`), a changed version `admin.pages.stale`, the homepage's default translation as a draft `admin.pages.homepageProtected`. **Náhled** renders the content below the form and saves nothing. |
| A page form posted to another screen | 404, e.g. `POST /admin/pages?do=homepage-submit`. |
| Set as homepage (POST) | 303 back with `admin.pages.homepageSet`; a page without a published default-language translation gives `admin.pages.homepageNeedsPublished`. |
| ↑ / ↓ in the tree | A POST form of the row, only from the same origin: swaps the page with its previous / next sibling, 303 to `/admin/pages` with `admin.pages.moved`. The first sibling has no ↑, the last no ↓. A GET of the form signal changes nothing; a cross-site POST only redirects (303); a page that does not exist is 404. |

### Stored requests

Every implementation stores and restores requests by the same rules:

- Only a GET without a signal (`?do=…`), or a POST from the same origin, is stored for after sign-in. Any other request redirects to `/admin/sign-in` without a backlink.
- A cross-site POST therefore gets no backlink. (A cross-site GET without a signal is stored: restoring it only shows a page.)
- A restored request never executes an action: a restored POST shows its page without running its signal or form handler, and a restored GET is a plain redirect to the stored address.
- A page save whose sign-in expired therefore comes back after sign-in as the form filled with the sent values, unsaved; saving it again stores it.

A deactivated account is signed out at its next request. The session cookie has no expiry (it ends with the browser), is `HttpOnly` and `SameSite=Lax`. Public pages never start a session.

## `/_health`

Plain text, independent of the database and of the language settings.

| Condition | Response |
|-----------|----------|
| Stylesheets and scripts the layouts need are present | 200, body `ok`. |
| One of them is missing | 503, body `missing asset: <names>`. |

## Content

Page content is Markdown. The page title is the only `<h1>`; headings in the content start at `##`. Raw HTML in the content is shown as text, and links with an unsafe scheme such as `javascript:` are dropped.

## Production mode

The contract describes production behaviour (debug mode off). In debug mode an unknown path shows the debugger's error screen instead of the 404 page.
