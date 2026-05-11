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

## Architecture

**Auth stack.** `config/auth.php` wires the `api` guard to Passport's driver (`driver => passport`). `routes/api.php` is otherwise empty save for a `/user` route guarded by `auth:api`. New protected API endpoints should be added there with `auth:api` middleware; OAuth2 token issuance routes are registered by Passport itself.

**Passport on Laravel 13.** Migrations for `oauth_auth_codes`, `oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_clients`, `oauth_device_codes` were published into `database/migrations/` (dated 2026-05-11) rather than served from the vendor — edit these locally if schema needs to change. `User` implements `Laravel\Passport\Contracts\OAuthenticatable` and uses `HasApiTokens`. Passport's signing keys are sourced from `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` env vars if set, otherwise generated files; run `passport:keys` after fresh installs.

**Laravel 13 conventions in use.** The `User` model uses PHP 8 attributes `#[Fillable([...])]` and `#[Hidden([...])]` (new in L13) instead of `$fillable` / `$hidden` properties — follow this pattern for new models. `bootstrap/app.php` is the single source of routing / middleware / exception configuration; there is no `app/Http/Kernel.php`. The `app/Providers/` dir contains only `AppServiceProvider`.

**Testing.** Pest 4, with `tests/Pest.php` binding the `TestCase` class to `Feature` tests. `RefreshDatabase` is **commented out** by default — re-enable it on `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature')` if you write tests that touch the DB. `phpunit.xml` sets `DB_DATABASE=testing` for the test env; the Sail compose mounts `create-testing-database.sh` to auto-create that DB inside MySQL.

**Local env quirk.** `.env` uses `DB_HOST=mysql` (Sail's service hostname). Running artisan from the host without Sail won't resolve that host. The committed `database/database.sqlite` is leftover from the SQLite default in `.env.example` and is not used by the current `.env`.
