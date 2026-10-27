# Test ids

Elements the end-to-end tests locate by `data-testid`.

| Test id | Element |
|---------|---------|
| `site-name` | Site name link in the page header; text is the configured site name. |
| `theme-toggle` | Button in the header that switches between light and dark mode. |
| `menu` | Main menu (`<nav>`) of the public pages; absent on error pages. |
| `menu-item` | Link of one menu item; the item of the shown page has `aria-current="page"`. |
| `breadcrumbs` | Breadcrumb navigation from the top-level page down to the shown page; only for nested pages. |
| `menu-news` | News link (`front.news.menu`), the last item of the top menu level; present when `cms: newsInMenu` is on; `aria-current="page"` on the news list and on a news detail. |
| `news-list` | List of the news items of one page of `/novinky` (`/en/news`); absent when there is no news. |
| `news-item` | One item of `news-list`. |
| `news-item-title` | Link to the news detail with its title. |
| `news-item-date` | Publication date of the item in the site time zone (`format.date`), a `<time>` element. |
| `news-item-perex` | Perex of the item, plain text. |
| `news-empty` | Message `front.news.empty` on the news list when there is no news. |
| `news-pagination` | Pagination of the news list with `front.news.pageOf`; only when there is more than one page. |
| `news-older` | Link `front.news.older` to the next page; absent on the last page. |
| `news-newer` | Link `front.news.newer` to the previous page; absent on the first page. |
| `news-detail` | The article of a news detail. |
| `news-detail-date` | Publication date of the detail in the site time zone (`format.date`), a `<time>` element. |
| `news-back` | Link `front.news.back` from the detail to the first page of the news list. |
| `error-title` | Heading of an error page (403, 404, 500); the 403 and 404 pages under `/admin` use the administration layout. |
| `page-title` | Main heading of a page, the homepage included; also of the news list (`front.news.title`) and of a news detail (its title). |
| `page-content` | Rendered content of a page or of a news detail. |
| `sign-in-form` | Sign-in form of the administration (`/admin/sign-in`). |
| `sign-in-email` | E-mail field of the sign-in form. |
| `sign-in-password` | Password field of the sign-in form. |
| `sign-in-submit` | Submit button of the sign-in form. |
| `form-error` | List of the errors of a submitted form, e.g. `admin.sign.invalid`. The page forms give the item of a field error the id `<field id>-error`, which the field names in `aria-describedby`. |
| `flash` | One flash message of the administration, e.g. `admin.sign.outDone`. |
| `admin-title` | Main heading of the dashboard (`admin.dashboard.title`). |
| `admin-user` | Name of the signed-in user in the administration header. |
| `admin-role` | Role of the signed-in user next to the name (`admin.role.editor`, `admin.role.admin`). |
| `sign-out` | Sign-out button in the administration header; it submits a POST form. |
| `admin-nav-pages` | Link to the page tree in the administration navigation, shown only to a signed-in user; `aria-current="page"` on the pages screens. |
| `pages-new` | Link to a new page above the page tree. |
| `pages-tree` | List of all pages in tree order (`/admin/pages`); absent when there are no pages. |
| `pages-item` | One row of the page tree; `data-depth` is its depth (0 for a top-level page). |
| `pages-item-title` | Link to the page detail, with the default-language title or `admin.pages.missing`. |
| `pages-homepage-badge` | Badge `admin.pages.homepage` of the homepage row. |
| `pages-draft-badge` | Badge `admin.pages.draft` of a row whose default-language translation is a draft. |
| `pages-up` | ↑ button of a row (POST form); absent on the first sibling. Its `aria-label` is `admin.pages.up` followed by the page title. |
| `pages-down` | ↓ button of a row (POST form); absent on the last sibling. Its `aria-label` is `admin.pages.down` followed by the page title. |
| `page-properties-form` | POST form of the page properties in the page detail (parent, menu flag, hidden version). |
| `page-parent` | Parent dropdown: `admin.pages.root` and every page except the page itself and its descendants. After a refused parent the detail selects the stored parent again. On a new page: `admin.pages.root` (chosen by default) and every page; the select is `required` and its first option is the placeholder `– admin.pages.parent –` (empty value, refused with `admin.form.invalidChoice`); after a refused parent nothing is selected, so the placeholder shows. A refused parent is an error of the dropdown (`aria-invalid`, `aria-describedby`). |
| `page-in-menu` | Checkbox `admin.pages.inMenu`. |
| `page-menu-hidden-note` | Note `admin.pages.menuHiddenByParent` below `page-in-menu` on the page detail, shown when the menu flag is checked and an ancestor is not in the menu or has no published default-language translation; the checkbox points to it with `aria-describedby`. |
| `page-properties-save` | Save button of the properties form. |
| `page-homepage-form` | POST form with `page-set-homepage`; absent on the homepage. |
| `page-set-homepage` | Button `admin.pages.setHomepage`. |
| `page-delete` | Link `admin.pages.delete` in the detail. |
| `page-delete-form` | POST form of the confirmation screen; absent when the page cannot be deleted. |
| `page-delete-question` | Confirmation question with the page title. |
| `page-delete-confirm` | Submit button of `page-delete-form` ([Smazat]). |
| `page-delete-reason` | Why the page cannot be deleted (homepage, subpages); replaces the question and the form. |
| `page-delete-back` | Link `admin.pages.deleteBack` to the detail. |
| `page-homepage-missing` | On the homepage: `admin.pages.homepageMissing` with the enabled languages without a published translation; absent when there are none. |
| `page-tab` | Link to the translation tab of one enabled language, `aria-current="page"` on the current one; only with more than one language. |
| `page-translation-heading` | Heading of the translation tab: the title, or `admin.pages.addTranslation` when the translation does not exist yet. |
| `page-translation-form` | POST form of one translation (hidden version; empty for a missing translation). |
| `page-new-form` | POST form of a new page (`/admin/pages/new`): parent, menu flag and the default-language translation. |
| `admin-nav-news` | Link to the news list in the administration navigation, shown only to a signed-in user; `aria-current="page"` on the news screens. |
| `admin-news-list` | List of the news items of one page of `/admin/news`; absent when there is no news. |
| `admin-news-item` | One row of `admin-news-list`. |
| `admin-news-item-title` | Title of the row in the default language, else the first existing translation. |
| `admin-news-author` | Name of the author of the row, or `admin.news.authorDeleted`; its `title` is `admin.news.author`. |
| `admin-news-state` | State badge of one enabled language in the row (`data-locale`): `admin.news.state.draft`, `.scheduled` (with date and time), `.published` (with date) or `.missing`. |
| `admin-news-new` | Link `admin.news.new` above the list. |
| `admin-news-empty` | Message `admin.news.empty` when there is no news. |
| `admin-news-pagination` | Pagination of `/admin/news` with `front.news.pageOf`; only when there is more than one page; the links carry `rel="prev"` and `rel="next"`. |
| `page-title-input` | Title field. |
| `page-slug-input` | Address field; `aria-invalid="true"` when the address was refused, and `aria-describedby` names the hint and the error. |
| `page-content-input` | Content field (Markdown). |
| `page-meta-input` | Search description field. |
| `page-status-input` | Status dropdown (draft / published). |
| `page-save` | Save button of the translation form and of the new page form. |
| `page-preview` | Preview button of the translation form; saves nothing. |
| `page-preview-output` | The previewed content rendered through the same Markdown filter as the public page; only after a preview. |
| `demo-banner` | Warning strip of the administration (`admin.demo.banner`), shown only while demo data are enabled. |

## Theme contract

- The public and the administration layout share the mode script and the toggle.
- `<html data-theme="light|dark">` reflects the active mode.
- The mode is set by a blocking inline script in `<head>` before any stylesheet: the value of `localStorage['cms-theme']` when it is `light` or `dark`, otherwise `prefers-color-scheme`.
- All `localStorage` access is wrapped in try/catch; the toggle still switches the mode when storage is unavailable.
- The toggle shows the text `front.theme.label`; its accessible name (`aria-label`) is `front.theme.dark`, and it carries `aria-pressed="true"` when the mode is dark, otherwise `"false"`.
- The toggle writes the new mode to `localStorage['cms-theme']`.
