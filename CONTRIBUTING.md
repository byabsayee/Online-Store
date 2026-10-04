# Contributing to Kafeel

Thanks for considering a contribution — bug reports, fixes, features, and
docs improvements are all welcome.

## Ways to contribute

- **Bug reports** — open an issue with steps to reproduce, what you
  expected, and what happened instead. Screenshots help.
- **Feature requests** — open an issue describing the use case, not just
  the implementation. What problem does it solve?
- **Pull requests** — for anything beyond a typo fix, open an issue first
  to discuss the approach before writing code, so you're not surprised by
  a design disagreement after the work is done.

## Development setup

Kafeel runs as a small Docker stack: nginx + PHP 8.3-FPM in one container
(via supervisord), MariaDB, and phpMyAdmin.

```bash
git clone https://github.com/TechZeeLand/Kafeel.git
cd Kafeel
cp .env.example .env          # adjust DB credentials, site name, etc.
mkdir -p uploads/products

# Build a local image tagged exactly like the one docker-compose.yml
# references, so `docker compose up` uses it instead of trying to pull
# from GHCR. (docker-compose.yml has no `build:` section on purpose — see
# the comment at the top of that file — so this is a plain `docker build`,
# not `docker compose build`.)
./build.sh

docker compose up -d   # docker-compose.override.yml is picked up
                        # automatically and adds the live-reload mounts
```

Then visit `http://localhost:1023` (or whatever `WEB_PORT` you set).
phpMyAdmin is at `http://localhost:1024`.

Edit anything under `src/` and refresh the browser — no rebuild needed.
You only need to re-run `./build.sh` (then `docker compose up -d` to
recreate the container with the fresh image) after changing the
`Dockerfile`, nginx config, supervisor config, or PHP extensions/ini —
not for everyday app-code changes.

## Code style

- PHP: 4-space indent, `snake_case` for functions/variables,
  `UPPER_SNAKE_CASE` for constants. Keep `src/includes/` (config, DB, auth,
  shared helpers) framework-free — no dependencies beyond PDO/core PHP.
- Prepared statements (PDO) for every query — no raw string interpolation
  of user input into SQL, ever.
- Escape all output with `htmlspecialchars()` unless you have a specific,
  commented reason not to.
- Match the existing "field ledger" visual theme (ink-navy/brass/paper,
  monospace, index-card product tiles) for any UI work — see
  `src/assets/css/`.

Before opening a PR, at minimum lint-check your PHP:

```bash
find src -name "*.php" -print0 | xargs -0 -n1 php -l
```

(The `php-lint` GitHub Actions workflow runs this automatically on every
PR, but catching it locally first saves a round trip.)

## Pull requests

- Keep PRs focused — one feature or fix per PR is much easier to review
  than a bundle of unrelated changes.
- Update `README.md` if you change setup steps, environment variables, or
  the project layout.
- Don't commit `.env`, real uploaded product photos, or database dumps —
  `.gitignore`/`.dockerignore` already exclude these; please don't
  `git add -f` around them.
- Describe *what* changed and *why* in the PR description; link the issue
  it addresses if there is one.

## Reporting security issues

Please don't open a public issue for a security vulnerability (e.g. an
auth bypass, SQL injection, or file-upload exploit). Instead, reach out to
the maintainer directly — see the contact info on the
[TechZeeLand GitHub profile](https://github.com/TechZeeLand) — so it can
be fixed before it's public.

## License

By contributing, you agree that your contributions will be licensed under
the project's [AGPL-3.0 license](LICENSE).
