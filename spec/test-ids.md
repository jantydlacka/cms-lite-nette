# Test ids

Elements the end-to-end tests locate by `data-testid`.

| Test id | Element |
|---------|---------|
| `site-name` | Site name link in the page header; text is the configured site name. |
| `theme-toggle` | Button in the header that switches between light and dark mode. |
| `menu` | Main menu (`<nav>`) of the public pages; absent on error pages. |
| `menu-item` | Link of one menu item; the item of the shown page has `aria-current="page"`. |
| `breadcrumbs` | Breadcrumb navigation from the top-level page down to the shown page; only for nested pages. |
| `error-title` | Heading of an error page (403, 404, 500); the 403 and 404 pages under `/admin` use the administration layout. |
| `page-title` | Main heading of a page, the homepage included. |
| `page-content` | Rendered content of a page. |
| `sign-in-form` | Sign-in form of the administration (`/admin/sign-in`). |
| `sign-in-email` | E-mail field of the sign-in form. |
| `sign-in-password` | Password field of the sign-in form. |
| `sign-in-submit` | Submit button of the sign-in form. |
| `form-error` | List of the errors of a submitted form, e.g. `admin.sign.invalid`. |
| `flash` | One flash message of the administration, e.g. `admin.sign.outDone`. |
| `admin-title` | Main heading of the dashboard (`admin.dashboard.title`). |
| `admin-user` | Name of the signed-in user in the administration header. |
| `admin-role` | Role of the signed-in user next to the name (`admin.role.editor`, `admin.role.admin`). |
| `sign-out` | Sign-out button in the administration header; it submits a POST form. |
| `demo-banner` | Warning strip of the administration (`admin.demo.banner`), shown only while demo data are enabled. |

## Theme contract

- The public and the administration layout share the mode script and the toggle.
- `<html data-theme="light|dark">` reflects the active mode.
- The mode is set by a blocking inline script in `<head>` before any stylesheet: the value of `localStorage['cms-theme']` when it is `light` or `dark`, otherwise `prefers-color-scheme`.
- All `localStorage` access is wrapped in try/catch; the toggle still switches the mode when storage is unavailable.
- The toggle shows the text `front.theme.label`; its accessible name (`aria-label`) is `front.theme.dark`, and it carries `aria-pressed="true"` when the mode is dark, otherwise `"false"`.
- The toggle writes the new mode to `localStorage['cms-theme']`.
