# Coding Rules

This project is a plain PHP application deployed directly under the web root.
Keep changes conservative, small, and compatible with the current hosting style.

## Project Shape

- Do not introduce a framework, build step, package manager, or new runtime unless explicitly required.
- Keep PHP files runnable in the current direct-deploy layout.
- Use existing file naming and page-level scripts unless a split clearly reduces risk.
- Prefer shared helpers only when the same behavior is already duplicated or will be reused.

## PHP Style

- Start PHP files with `<?php` and `declare(strict_types=1);` when creating new PHP files.
- Use `PDO` prepared statements for database input.
- Escape HTML output with `htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8')`.
- Validate request data before use, especially `$_GET`, `$_POST`, `$_FILES`, and session values.
- Keep timezone-sensitive business logic in `Asia/Tokyo`.
- Catch non-critical integration failures only where page availability matters, and avoid hiding critical write failures.
- Avoid large unrelated refactors inside large legacy files such as `admin.php`.

## Database Changes

- This codebase currently uses runtime `CREATE TABLE IF NOT EXISTS` and guarded `ALTER TABLE` calls.
- When adding columns, check for existence through `information_schema` before `ALTER TABLE`.
- Keep schema changes idempotent.
- Wrap multi-step writes in transactions when data consistency matters.
- Do not rename or drop columns/tables without an explicit migration and backup plan.

## Security

- Do not add new secrets, passwords, API keys, tokens, or credentials to the repository.
- Do not print or paste existing secrets from `config.php` or `.vscode/sftp.json` in logs, comments, docs, or chat output.
- Do not weaken authentication, CSRF checks, upload validation, or admin access checks.
- For uploaded files, keep extension, MIME type, size, and storage path validation.
- Do not expose raw exception messages to end users on public pages.

## Frontend

- Reuse `assets/styles.css` and existing page patterns before adding page-specific CSS.
- Keep layouts responsive for mobile.
- Keep form labels, validation messages, and operational text in Japanese unless the surrounding UI is English.
- Avoid decorative redesigns when editing operational admin/member screens.

## Mail, Stripe, and External APIs

- Keep external API operations idempotent where possible.
- Preserve existing retry, checkpoint, and duplicate-prevention behavior.
- Do not run live Stripe, SMTP, or cron-like actions during local investigation unless the user explicitly asks.
- When changing mail content, verify subject, sender, recipient, and unsubscribe behavior where applicable.

## Analytics

- Most public pages include `analytics/logger.php`.
- Do not let analytics failures block page loads.
- Do not add tracking of sensitive personal data unless explicitly required and justified.

## Testing and Verification

- At minimum, run `php -l` on changed PHP files.
- For form or admin changes, manually verify the affected page flow where feasible.
- For SQL changes, verify idempotency by checking that repeated execution will not fail.
- For UI changes, check both desktop and mobile widths when the page is user-facing.

## Deployment Caution

- This project appears to auto-upload through VS Code SFTP configuration.
- Confirm intent before making broad file changes.
- Keep generated files, local dumps, and temporary diagnostics out of the project root.
