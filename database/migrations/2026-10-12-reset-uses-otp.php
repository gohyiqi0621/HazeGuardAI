<?php
declare(strict_types=1);

/**
 * One-off migration: password reset moves from an emailed link to an emailed code.
 *
 * Before: email_otps.purpose ENUM('verify_email','login')
 *         + password_reset_tokens holding hashed link tokens.
 * After:  email_otps.purpose ENUM('verify_email','login','reset_password')
 *         + password_reset_tokens dropped.
 *
 * Why the move: a reset link is built from APP_URL, so every wrong APP_URL (a
 * stale port, a subdirectory install, a LAN address that changed) produced a
 * link to nowhere — and the link is useless anyway if the mail is read on a
 * phone, because `localhost` means the machine running the browser. A typed
 * code has no URL to get wrong and works from any device.
 *
 * The link path is not just bypassed, it is gone: AuthModel no longer calls
 * TokenModel::issueResetToken()/consumeResetToken() and Mailer no longer builds
 * a reset URL, so this table has no reader left. Dropping it removes the last
 * place a stale link token could sit unnoticed.
 *
 * Run once, from the project root:
 *     C:\xampp\php83\php.exe database/migrations/2026-10-12-reset-uses-otp.php
 *
 * Safe to re-run: it stops when the enum already carries 'reset_password'.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use HazeGuard\Database;

/** Print a line of progress. */
function say(string $line): void
{
    echo $line . PHP_EOL;
}

/** Does this database have a table? */
function hasTable(string $name): bool
{
    return Database::run(
        'SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
        ['t' => $name]
    )->fetchColumn() !== false;
}

/** The COLUMN_TYPE of email_otps.purpose, or '' when the table is absent. */
function purposeType(): string
{
    $type = Database::run(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'email_otps'
            AND COLUMN_NAME = 'purpose'"
    )->fetchColumn();

    return $type === false ? '' : (string) $type;
}

say('Migration: password reset uses an OTP instead of a link');

// -- 1. Widen the enum so a reset code has somewhere to live. ------------------
$type = purposeType();

if ($type === '') {
    say('  email_otps.purpose is missing — run database/schema.sql first.');
    exit(1);
}

if (str_contains($type, 'reset_password')) {
    say("  purpose already carries reset_password — nothing to widen.");
} else {
    Database::run(
        "ALTER TABLE email_otps
           MODIFY purpose ENUM('verify_email','login','reset_password') NOT NULL"
    );
    say('  widened email_otps.purpose to include reset_password');
}

// -- 2. Retire the link-token table. -------------------------------------------
if (hasTable('password_reset_tokens')) {
    // Count first: the rows are the only record that links were ever issued, and
    // an operator may want to know how many were thrown away.
    $spent = (int) Database::run(
        'SELECT COUNT(*) FROM password_reset_tokens WHERE used_at IS NULL'
    )->fetchColumn();

    Database::run('DROP TABLE `password_reset_tokens`');
    say("  dropped password_reset_tokens ({$spent} unspent link(s) discarded)");
} else {
    say('  password_reset_tokens is already gone.');
}

say('Done. Password reset now issues a 6-digit code via email_otps.');
