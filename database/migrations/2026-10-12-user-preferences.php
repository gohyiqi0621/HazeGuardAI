<?php
declare(strict_types=1);

/**
 * One-off migration: the profile screen's stored preferences.
 *
 * Adds:  users.password_changed_at   — stamps every password change
 *        user_preferences            — one row per account
 *
 * Why password_changed_at now: PHP stores sessions in files, so a password change
 * has no list of sessions to clear. The column lets GET /api/auth/session
 * recognise a session that was granted before the change and end it. This is the
 * gap the reset flow has always had; it is closed once, here, and both the reset
 * path and the profile change-password path stamp it.
 *
 * No notification columns. Nothing delivers a notification, so storing "daily
 * summary: on" would record a promise the app cannot keep.
 *
 * Run once, from the project root:
 *     C:\xampp\php\php.exe database/migrations/2026-10-12-user-preferences.php
 *
 * Safe to re-run: both changes are guarded by a presence check.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use HazeGuard\Database;

function say(string $line): void
{
    echo $line . PHP_EOL;
}

function hasColumn(string $table, string $column): bool
{
    return Database::run(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        ['t' => $table, 'c' => $column]
    )->fetchColumn() !== false;
}

say('Migration: user preferences + password_changed_at');

// -- 1. password_changed_at ----------------------------------------------------
if (hasColumn('users', 'password_changed_at')) {
    say('  users.password_changed_at already exists — nothing to add.');
} else {
    Database::run('ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL DEFAULT NULL');
    say('  added users.password_changed_at');
}

// -- 2. user_preferences -------------------------------------------------------
// The CREATE TABLE body is lifted from database/schema.sql rather than repeated
// here, so the two cannot drift: if schema.sql defines it, this migration creates
// exactly that.
$schema = file_get_contents(dirname(__DIR__) . '/schema.sql') ?: '';
if (!preg_match('/CREATE TABLE IF NOT EXISTS user_preferences\s*\((.*?)\)\s*ENGINE=/s', $schema, $m)) {
    say('  could not find the user_preferences definition in schema.sql.');
    exit(1);
}

Database::run("CREATE TABLE IF NOT EXISTS user_preferences ({$m[1]}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
say('  created user_preferences');

say('Done. The profile screen now stores activities and a preferred location.');