# cms-lite-nette

[![CI](https://github.com/jantydlacka/cms-lite-nette/actions/workflows/ci.yml/badge.svg)](https://github.com/jantydlacka/cms-lite-nette/actions/workflows/ci.yml)
[![Coverage](https://raw.githubusercontent.com/jantydlacka/cms-lite-nette/badges/coverage.svg)](https://github.com/jantydlacka/cms-lite-nette/actions/workflows/ci.yml)

A small, well-structured CMS built on [Nette](https://nette.org): pages, news, images and a contact form. It is the first of a planned family, `cms-lite-<framework>`: every implementation follows the same specification in [`spec/`](spec/) and is checked by the same browser-level end-to-end suite in `e2e/`.

Status: phase 1 (skeleton) is done - application bootstrap, Docker setup, migrations, translator, clock, a homepage layout with light/dark theme, quality gate and CI configuration. Pages, news, images and the contact form come in later phases. The CI workflow is configured but has not run yet, so there are no published runs.

## Quick start

Requires only Docker (with Compose).

```bash
git clone https://github.com/jantydlacka/cms-lite-nette.git
cd cms-lite-nette
docker compose up -d --build
docker compose exec app composer install
docker compose up -d --wait
docker compose exec app bin/console migrations:continue
```

Then open <http://localhost:8080>.

The order matters: the container health check requests the homepage, which needs `vendor/`, so the first `up` cannot become healthy until `composer install` has run; the second `up -d --wait` waits for that.

Host ports can be changed in a `.env` file next to `docker-compose.yml`:

```
CMS_HTTP_PORT=8081
CMS_DB_PORT=3308
```

Optional `config/local.neon` (gitignored) is loaded when present, for machine-specific overrides.

### Development mode

`NETTE_DEBUG=1` is the default in the compose setup. In this mode an unknown URL shows the Tracy error screen, not the 404 page. Set `NETTE_DEBUG=0` (in `.env` or the shell, then `docker compose up -d`) to see production behaviour. `CMS_DEMO_DATA=1` or `0` forces demo data on or off; when unset it follows debug mode.

## Quality

Everything runs inside the container:

```bash
docker compose exec app composer qa
```

`composer qa` runs lint (PHP, NEON, Latte), coding standard, PHPStan at level max without a baseline, Psalm taint analysis, the Nette Tester suite (which includes the architecture rules) and the forbidden-words check. Psalm taint analysis covers SQL, file paths and shell commands; it does not cover XSS, which Latte's autoescaping handles.

Install the git hooks (pre-commit and pre-push run parts of the gate):

```bash
docker compose exec app composer hooks:install
```

End-to-end tests run from the host against a running stack:

```bash
cd e2e && npm ci && npx playwright install chromium
E2E_BASE_URL=http://localhost:8080 npx playwright test
```

## Deployment note

Asset URLs are emitted as absolute URLs using the request host. Behind a TLS-terminating reverse proxy, configure trusted proxies and HTTPS detection in the application; otherwise the browser receives `http://` asset URLs on an HTTPS page (mixed content).

## Documentation

- [`docs/design.md`](docs/design.md) - architecture and decisions
- [`spec/`](spec/) - the specification shared by all `cms-lite-<framework>` implementations

## License

MIT, see [`LICENSE`](LICENSE).
