<?php
declare(strict_types=1);

/**
 * One-off migration: collapse credentials into a single table, and encrypt the key.
 *
 * Before: credential_types (name, brevo_secret_key) + providers +
 *         credential_type_providers + credential_keys (type, provider, label, api_key).
 * After:  credential_types (name, api_key), everything else dropped.
 *
 * The key value that mattered lived in credential_keys, so it is copied onto its
 * credential type first — otherwise collapsing the tables would silently throw a
 * working secret away. Every value is then encrypted with CREDENTIAL_KEY.
 *
 * Run once, from the project root:
 *     C:\xampp\php83\php.exe database/migrations/2026-10-12-credentials-encrypted.php
 *
 * Safe to re-run: it stops if the column is already named `api_key`.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use HazeGuard\Crypto;
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

/** Does credential_types have this column? */
function hasColumn(string $column): bool
{
    return Database::run(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        ['t' => 'credential_types', 'c' => $column]
    )->fetchColumn() !== false;
}

say('Migration: credentials -> single table, encrypted key');

if (!hasColumn('brevo_secret_key')) {
    say('  already migrated (credential_types.brevo_secret_key is gone) — nothing to do.');
    exit(0);
}

// -- 1. The newest key per credential type, read before the table is dropped. ---
$fromKeys = [];
if (hasTable('credential_keys')) {
    $rows = Database::run(
        'SELECT type_id, api_key FROM credential_keys ORDER BY id ASC'
    )->fetchAll();

    // Ascending order, so the last write per type wins: the newest key.
    foreach ($rows as $row) {
        $key = trim((string) $row['api_key']);
        if ($key !== '') {
            $fromKeys[(int) $row['type_id']] = $key;
        }
    }
}
say('  read ' . count($fromKeys) . ' key(s) from credential_keys');

// -- 2. Rename and widen the column. -------------------------------------------
// 512, not 255: `enc:v1:` + base64(12-byte nonce + 16-byte tag + plaintext) is
// about 1.5x the plaintext, and the field still caps the plaintext at 255.
Database::run(
    'ALTER TABLE credential_types
       CHANGE `brevo_secret_key` `api_key` VARCHAR(512) NULL DEFAULT NULL'
);
say('  renamed brevo_secret_key -> api_key (VARCHAR 512)');

// -- 3. Encrypt whatever each credential now holds. ----------------------------
$types = Database::run('SELECT id, name, api_key FROM credential_types ORDER BY id')->fetchAll();

$encrypted = 0;
$empty     = [];

foreach ($types as $type) {
    $id    = (int) $type['id'];
    $plain = $fromKeys[$id] ?? trim((string) $type['api_key']);

    if ($plain === '') {
        $empty[] = "#{$id} {$type['name']}";
        continue;
    }

    if (Crypto::isEncrypted($plain)) {
        continue;                       // already done by an earlier run
    }

    Database::run(
        'UPDATE credential_types SET api_key = :key WHERE id = :id',
        ['key' => Crypto::encrypt($plain), 'id' => $id]
    );
    $encrypted++;

    say("  encrypted #{$id} {$type['name']} (" . strlen($plain) . ' chars)');
}

// -- 4. Drop the tables nothing reads any more. --------------------------------
foreach (['credential_keys', 'credential_type_providers', 'providers'] as $table) {
    if (hasTable($table)) {
        Database::run("DROP TABLE `{$table}`");
        say("  dropped {$table}");
    }
}

say("Done. {$encrypted} credential(s) encrypted.");

if ($empty !== []) {
    say('');
    say('WARNING: these credentials now have no key, and the panel requires one:');
    foreach ($empty as $row) {
        say('  ' . $row);
    }
    say('Edit each one in the Credentials panel and paste its key.');
}
