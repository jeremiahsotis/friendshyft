# Repository Guidelines

## Project Structure & Module Organization
This is a WordPress plugin. The main entry point is `friendshyft.php`, which registers hooks and loads core classes.
- `includes/` holds core PHP classes (database, signup logic, notifications, integrations).
- `admin/` contains WordPress admin UI classes and screens.
- `public/` contains public-facing assets/templates.
- `assets/`, `css/`, and `js/` hold static files (styles, scripts, images).
- `tests/` contains PHPUnit tests; configuration is in `phpunit.xml.dist`.
- Root-level `fs-*.php` files are feature migrations; `includes/class-database-migrations.php` manages schema versions.

## Build, Test, and Development Commands
There is no build step; WordPress executes PHP directly.
- `composer install` installs dev dependencies (PHPUnit).
- `composer test` runs PHPUnit test suite.
- `composer test-coverage` generates HTML coverage in `coverage/`.
- Local dev runs in Local by Flywheel; access `wp-admin` to manage the plugin UI.

## Coding Style & Naming Conventions
- PHP uses 4-space indentation and WordPress-style braces on the same line.
- Class names are `StudlyCaps` and files follow `class-*.php` (for example, `includes/class-signup.php`).
- Feature migrations use `fs-*.php` filenames.
- Prefix functions, hooks, and options with `fs_` or `friendshyft_` to avoid collisions.
- Match existing patterns in adjacent files when extending admin screens or database logic.

## Testing Guidelines
- PHPUnit is configured via `phpunit.xml.dist` and loads from `tests/`.
- Name test files with `.php` suffix and keep them under `tests/`.
- Manual QA flows and cron checks are documented in `TESTING-PLAN.md`; use WP-CLI to trigger cron when needed (example: `wp cron event run fs_update_predictions_cron`).

## Commit & Pull Request Guidelines
- Commits in history use short, imperative summaries (example: “Update TODO.md”, “Implement comprehensive volunteer history view in portal”).
- PRs should include: what changed, how to verify (commands or manual steps), and screenshots for UI changes.
- Call out database migrations explicitly and note whether activation is required to apply them.

## Security & Configuration Notes
- Database migrations run automatically on activation; prefer schema changes through the migration flow.
- Be mindful of volunteer PII (email/phone) in logs and test fixtures.
