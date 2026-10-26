# Test ids

Elements the end-to-end tests locate by `data-testid`.

| Test id | Element |
|---------|---------|
| `site-name` | Site name link in the page header; text is the configured site name. |
| `theme-toggle` | Button in the header that switches between light and dark mode. |
| `menu` | Main menu (`<nav>`) of the public pages; absent on error pages. |
| `menu-item` | Link of one menu item; the item of the shown page has `aria-current="page"`. |
| `breadcrumbs` | Breadcrumb navigation from the top-level page down to the shown page; only for nested pages. |
| `page-title` | Main heading of a page, the homepage included. |
| `page-content` | Rendered content of a page. |

## Theme contract

- `<html data-theme="light|dark">` reflects the active mode.
- The mode is set by a blocking inline script in `<head>` before any stylesheet: the value of `localStorage['cms-theme']` when it is `light` or `dark`, otherwise `prefers-color-scheme`.
- All `localStorage` access is wrapped in try/catch; the toggle still switches the mode when storage is unavailable.
- The toggle shows the text `front.theme.label`; its accessible name (`aria-label`) is `front.theme.dark`, and it carries `aria-pressed="true"` when the mode is dark, otherwise `"false"`.
- The toggle writes the new mode to `localStorage['cms-theme']`.
