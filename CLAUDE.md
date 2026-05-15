# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Fresh Laravel 13 + Passport 13 application serving as an OAuth2 API. Runs locally via Laravel Sail (Docker) with MySQL 8.4. App code is essentially stock skeleton — the OAuth2 surface area is what's being built out.

## Commands

All `php artisan` / `composer` / `npm` commands assume you are inside the Sail container (or use `./vendor/bin/sail <cmd>` from the host). The `.env` is configured for MySQL on the `mysql` service host, so artisan commands run from the host without Sail will fail to connect to the DB.

- `./vendor/bin/sail up -d` — start the stack (laravel.test + mysql)
- `composer dev` — run server + queue worker + log tailer (pail) + vite concurrently (host-based dev only; not for Sail)
- `composer test` — clears config then runs `php artisan test` (Pest)
- `php artisan test --filter=TestName` — run a single test
- `php artisan test tests/Feature/ExampleTest.php` — run a single file
- `./vendor/bin/pint` — format PHP (Laravel's opinionated wrapper around PHP-CS-Fixer)
- `php artisan migrate` — run migrations (Passport tables live in this app's migrations dir, not vendor)
- `php artisan passport:keys` — generate the OAuth signing keys (required before issuing tokens)
- `php artisan passport:client` — create OAuth clients interactively

## Dev Container

`.devcontainer/devcontainer.json` lets VS Code attach directly to the running `laravel.test` Sail container so PHP, Pest, Xdebug, and the database client all run "inside" Docker. Once attached, the integrated terminal opens at `/var/www/html` as the `sail` user (UID 1000) — `php`, `artisan`, `composer`, and `pest` work directly **without** the `./vendor/bin/sail` prefix.

**Prereqs.** Docker running on the host + the `ms-vscode-remote.remote-containers` extension in VS Code. `Ctrl+Shift+P` → "Dev Containers: Reopen in Container" to attach; "Dev Containers: Rebuild Container" after any change to `devcontainer.json`, the `Dockerfile`, or `docker/8.5/php.ini`.

**Required `.env` vars.** `WWWUSER=1000` and `WWWGROUP=1000` (or your host UID/GID) MUST be present in `.env`. The `./vendor/bin/sail` wrapper script injects them at runtime when you use `sail up`, but Dev Containers calls `docker compose build` directly and does not pass through the wrapper — without these, the `groupadd -g $WWWGROUP sail` line in `docker/8.5/Dockerfile` fails with "invalid group ID".

**Compose file is `compose.yaml`, not `docker-compose.yml`.** Sail 11+ renamed it. `dockerComposeFile` in `devcontainer.json` must point to `../compose.yaml`.

**Xdebug works out of the box.** `docker/8.5/php.ini` sets `xdebug.mode=develop,debug` with `start_with_request=trigger` on port 9003. `devcontainer.json` declares `remoteEnv: { "XDEBUG_TRIGGER": "1" }` so any terminal inside the container has the trigger pre-set — running `php artisan test` auto-activates the debugger if a `php-debug` "Listen for Xdebug" session is running on the host (port 9003 is forwarded). The `xdebug.client_host` value in `php.ini` is effectively a no-op because the compose service env (`XDEBUG_CONFIG=client_host=host.docker.internal`) overrides it; the `extra_hosts` mapping in `compose.yaml` resolves `host.docker.internal` to the host gateway.

**PHP binary path.** `devcontainer.json` sets `php.validate.executablePath` and `php.debug.executablePath` to `/usr/bin/php` (a symlink to the versioned binary, currently `php8.5`). Use the unversioned path so Sail upgrades (8.5 → 8.6) don't silently break VS Code's PHP integration.

**VS Code extensions installed in the container.** Declared in `devcontainer.json` under `customizations.vscode.extensions`:
- `laravel.vscode-laravel` — official Laravel extension (probes for herd/valet/docker/lando/ddev on startup and logs "not found" to stderr; ignore that noise).
- `recca0120.vscode-phpunit` — provides the ▶ gutter icons next to `it(...)` / `test(...)` in Pest files. The other Pest extensions in the marketplace (`pestphp.pest-vscode` doesn't exist; `m1guelpf.better-pest` only provides keyboard shortcuts, no gutter icons) were tried and discarded.
- `bmewburn.vscode-intelephense-client` — language server (Go to Definition, Find References, real-time diagnostics). Don't remove it thinking IntelliPHP replaces it; IntelliPHP is only AI completions.
- `xdebug.php-debug`, `amiralizadeh9480.laravel-extra-intellisense`, `DEVSENSE.intelli-php-vscode`.

**Test runner settings (`.vscode/settings.json`).** Required so `recca0120.vscode-phpunit` doesn't auto-wrap commands in `./vendor/bin/sail exec` (which fails because there's no Docker daemon inside the Dev Container):
```jsonc
{
    "phpunit.command": "",                                  // no wrapper — call pest directly
    "phpunit.php": "/usr/bin/php",                          // PHP binary inside the container
    "phpunit.phpunit": "/var/www/html/vendor/bin/pest",     // use Pest, not PHPUnit
    "phpunit.args": ["--colors=always"]
}
```

**Pitfalls.**
- After modifying `remoteEnv` or `extensions` in `devcontainer.json`, a Reload Window is NOT sufficient — must Rebuild Container.
- The `sail` CLI still works inside the Dev Container but is redundant.
- Intelephense throws false positives on Pest's dynamically-bound `$this` (e.g. `$this->jsonApi()`, `$this->assertDatabaseEmpty()` flagged as undefined). The methods exist at runtime — add `/** @var \Tests\TestCase $this */` at the top of a test file if the noise is distracting, but the tests run fine regardless.
- `XDEBUG_TRIGGER=1` set in `remoteEnv` makes Xdebug try to connect to a listener on every PHP execution. If no "Listen for Xdebug" session is running, you'll see `Xdebug: [Step Debug] Could not connect to debugging client` warnings before each test output — harmless, just noisy. With `xdebug.mode=develop,debug` active, tests also run ~3× slower because Xdebug is loaded. To get fast, quiet test runs: temporarily disable Xdebug per-command with `php -d xdebug.mode=off …`, or remove `remoteEnv` and set `XDEBUG_TRIGGER=1` manually only in debug sessions.

## Architecture

**Auth stack.** `config/auth.php` wires the `api` guard to Passport's driver (`driver => passport`). `routes/api.php` is otherwise empty save for a `/user` route guarded by `auth:api`. New protected API endpoints should be added there with `auth:api` middleware; OAuth2 token issuance routes are registered by Passport itself.

**Passport on Laravel 13.** Migrations for `oauth_auth_codes`, `oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_clients`, `oauth_device_codes` were published into `database/migrations/` (dated 2026-05-11) rather than served from the vendor — edit these locally if schema needs to change. `User` implements `Laravel\Passport\Contracts\OAuthenticatable` and uses `HasApiTokens`. Passport's signing keys are sourced from `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` env vars if set, otherwise generated files; run `passport:keys` after fresh installs.

**Laravel 13 conventions in use.** The `User` model uses PHP 8 attributes `#[Fillable([...])]` and `#[Hidden([...])]` (new in L13) instead of `$fillable` / `$hidden` properties — follow this pattern for new models. `bootstrap/app.php` is the single source of routing / middleware / exception configuration; there is no `app/Http/Kernel.php`. The `app/Providers/` dir contains only `AppServiceProvider`.

**Testing.** Pest 4, with `tests/Pest.php` binding the `TestCase` class to `Feature` tests and applying `RefreshDatabase` + `MakesJsonApiRequests` globally (so `$this->jsonApi()->withData(...)` and `$this->assertDatabaseEmpty(...)` work in every Feature test without per-file `uses(...)`). `phpunit.xml` sets `DB_DATABASE=testing` for the test env; the Sail compose mounts `create-testing-database.sh` to auto-create that DB inside MySQL. `tests/Pest.php` also defines project-specific helpers: `jsonData(Model $m)` builds a JSON:API resource payload via reflection over the model's relationships; `userWithPermission(string $permission, ?User $user = null)` returns a User with the Spatie permission granted.

**Local env quirk.** `.env` uses `DB_HOST=mysql` (Sail's service hostname). Running artisan from the host without Sail won't resolve that host. The committed `database/database.sqlite` is leftover from the SQLite default in `.env.example` and is not used by the current `.env`.
