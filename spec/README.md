# Shared specification

Contract shared by every `cms-lite-<framework>` implementation. An implementation conforms when the browser-level behaviour below holds, whatever the framework underneath.

- `urls.md` - routes and their behaviour
- `messages.md` - translation keys and their Czech text
- `test-ids.md` - `data-testid` hooks the end-to-end tests rely on

The end-to-end suite lives in `e2e/` and runs against any implementation: `E2E_BASE_URL=<url> npx playwright test`.
