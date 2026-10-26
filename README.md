# cms-lite-nette

[![CI](https://github.com/jantydlacka/cms-lite-nette/actions/workflows/ci.yml/badge.svg)](https://github.com/jantydlacka/cms-lite-nette/actions/workflows/ci.yml)
[![Coverage](https://raw.githubusercontent.com/jantydlacka/cms-lite-nette/badges/coverage.svg)](https://github.com/jantydlacka/cms-lite-nette/actions/workflows/ci.yml)

A small, well-structured CMS built on [Nette](https://nette.org): pages, news, images and a contact form. It is the first of a planned family, `cms-lite-<framework>`: every implementation follows the same specification in [`spec/`](spec/) and is checked by the same browser-level end-to-end suite in `e2e/`.

Status: phase 1 (skeleton) is done - application bootstrap, Docker setup, migrations, translator, clock, a homepage layout with light/dark theme, quality gate and CI configuration. Pages, news, images and the contact form come in later phases. The CI workflow is configured but has not run yet, so there are no published runs, and the coverage badge shows as broken until the first CI run on `main` publishes it.

## Quick start

Requires only Docker (with Compose).

```bash
git clone https://github.com/jantydlacka/cms-lite-nette.git
cd cms-lite-nette
# Linux: run the app container as your user, so vendor/, temp/ and log/ stay yours
touch .env && sed -i '/^CMS_[UG]ID=/d' .env && printf 'CMS_UID=%s\nCMS_GID=%s\n' "$(id -u)" "$(id -g)" >> .env
docker compose up -d --build
docker compose exec app composer install
docker compose up -d --wait
docker compose exec app bin/console migrations:continue
```

Migration files are ordered by file name across all groups (`structures`, `basic-data`, `dummy-data`), not per group: a new file must sort after the latest one already executed (use the next date/number prefix), or the runner refuses it.

Then open <http://localhost:8080>.

On Linux the `.env` line matters: the container runs as `CMS_UID:CMS_GID` (default `1000:1000`), and files it creates in the bind-mounted project belong to that user. `.env` must contain the actual numbers (Compose does not evaluate `$(id -u)` inside `.env`); the command above writes them (it replaces any earlier `CMS_UID`/`CMS_GID` lines, so running it twice adds no duplicates).

The order matters: the container health check requests `/_health`, which needs `vendor/`, so the first `up` cannot become healthy until `composer install` has run; the second `up -d --wait` waits for that. `/_health` does not use the database, so it turns healthy before the migrations run; it answers `503` while an asset the layout needs is missing.

Database settings come from the `CMS_DB_*` environment variables. In production all of them must be set and non-empty, otherwise boot fails; the development defaults apply only in debug mode. `config/local.neon` (gitignored), if present, is loaded in every mode and may override any setting, including `database:`, on top of those values.

Both ports are published on `127.0.0.1` only. They can be changed in `.env`:

```
CMS_HTTP_PORT=8081
CMS_DB_HOST_PORT=3308
```

Optional `config/local.neon` (gitignored) is loaded when present, for machine-specific overrides.

### Environment variables

| Variable | Used by | Meaning |
| --- | --- | --- |
| `CMS_UID`, `CMS_GID` | Compose | User and group the app container runs as; default `1000`. |
| `CMS_HTTP_PORT` | Compose | Host port of the web server; default `8080`. |
| `CMS_DB_HOST_PORT` | Compose | Host port of the bundled MySQL; default `3307`. |
| `MYSQL_ROOT_PASSWORD` | Compose | Root password of the bundled MySQL, also passed to the app as `CMS_DB_PASSWORD`; default `root`. |
| `NETTE_DEBUG` | App | `1` = debug mode (Tracy, translator in strict mode); any other value or unset = production. Compose sets `1` unless overridden. |
| `CMS_DEMO_DATA` | App | `1` = migrations include demo data, any other value = no demo data; unset follows debug mode. |
| `CMS_DB_HOST`, `CMS_DB_PORT`, `CMS_DB_NAME`, `CMS_DB_USER`, `CMS_DB_PASSWORD` | App | Database connection. Compose sets them for the bundled MySQL (`CMS_DB_PORT` is the port inside the Docker network, `3306`, not the host port). In debug mode unset or empty values fall back to `127.0.0.1`, `3306`, `cms`, `root`, `root`; in production every one must be set and non-empty, otherwise the application refuses to boot. |

### Development mode

`NETTE_DEBUG=1` is the default in the compose setup. In this mode an unknown URL shows the Tracy error screen, not the 404 page. Set `NETTE_DEBUG=0` (in `.env` or the shell, then `docker compose up -d`) to see production behaviour. `CMS_DEMO_DATA=1` or `0` forces demo data on or off; when unset it follows debug mode. Demo data also enables the `en` language; to enable a second language without it, add `cms: locales: [cs, en]` to `config/local.neon`.

## Quality

Everything runs inside the container:

```bash
docker compose exec app composer qa
```

`composer qa` runs lint (PHP, NEON, Latte), coding standard, PHPStan at level max without a baseline, Psalm taint analysis, the forbidden-words check and the Nette Tester suite (which includes the architecture rules); `composer qa:checks` runs everything except the test suite. Psalm taint analysis covers SQL, file paths and shell commands; it does not cover XSS, which Latte's autoescaping handles.

Install the git hooks (pre-commit and pre-push run parts of the gate):

```bash
docker compose exec app composer hooks:install
```

End-to-end tests run from the host against a running stack and need Node.js 20 or newer on the host (only for these tests). The contract is production behaviour, so run the stack with `NETTE_DEBUG=0` and the demo data loaded (`CMS_DEMO_DATA=1` for the web container as well, since the demo data enables the `en` language); clear the container cache first, because production mode does not refresh it:

```bash
rm -rf temp/cache
export NETTE_DEBUG=0 CMS_DEMO_DATA=1
docker compose up -d --wait
docker compose exec -T app bin/console migrations:reset
cd e2e && npm ci && npx playwright install chromium
E2E_BASE_URL=http://localhost:8080 npx playwright test
```

Set `E2E_BASE_URL` to your `CMS_HTTP_PORT`. Against a debug-mode stack (`NETTE_DEBUG=1`) the 404 checks fail and the Tracy bar logs console errors; add `E2E_IGNORE_CONSOLE_PATTERN=_tracy_bar` to skip those, see [`spec/README.md`](spec/README.md). Return to development mode with `unset NETTE_DEBUG CMS_DEMO_DATA; rm -rf temp/cache; docker compose up -d --wait`.

The pre-push hook and CI also run `bin/check-migrations-immutable [base]`, which fails when a migration that exists in the base branch was modified, deleted or renamed. The hook compares against `origin/main` and prints a warning and skips the check when that ref does not exist.

## Deployment note

Asset URLs and redirect `Location` headers are absolute URLs built from the request `Host`, so set `ServerName` (or trusted proxies) at deployment. Behind a TLS-terminating reverse proxy, configure trusted proxies and HTTPS detection in the application; otherwise the browser receives `http://` asset URLs on an HTTPS page (mixed content).

Clear `temp/cache` on every deploy: in production mode the container and template caches are not rebuilt when the code changes.

Deploy `www/assets/` together with the templates: when an asset file referenced by a template is missing, the page breaks in production mode – the response still has status 200, but the HTML stops inside `<head>` and the generic server error screen follows (the error is logged).

## Documentation

- [`docs/design.md`](docs/design.md) - architecture and decisions
- [`spec/`](spec/) - the specification shared by all `cms-lite-<framework>` implementations

## License

MIT, see [`LICENSE`](LICENSE).
