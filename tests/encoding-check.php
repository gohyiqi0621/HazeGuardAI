<?php
declare(strict_types=1);

/**
 * Scan the tree for mojibake: text that was decoded as Windows-1252 and written
 * back as UTF-8, so a single character like an ellipsis became three.
 *
 * The signature is U+00E2 (a-circumflex, from the first UTF-8 byte) followed by
 * U+20AC (the euro sign, which is what cp1252 maps byte 0x80 to). "a€¦" for "…".
 *
 * Usage:  php tests/encoding-check.php          report only
 *         php tests/encoding-check.php --fix    repair in place
 *
 * Why this exists: a source file edited with PowerShell's Get-Content/Set-Content
 * round-trip is decoded as Windows-1252 and written back as UTF-8, so every
 * non-ASCII character silently becomes two or three. An ellipsis becomes "a€¦".
 * Nothing in the test suite notices, because the file is still valid UTF-8 and
 * still parses — it just renders wrong. This check is the only thing that sees it.
 */

$root = dirname(__DIR__);
$skipDirs = ['storage', 'node_modules', '.git'];
$extensions = ['php', 'js', 'cjs', 'css', 'sql', 'md', 'xml', 'html'];
$fix = in_array('--fix', $argv, true);

/**
 * Characters cp1252 uses for bytes that are not ASCII.
 *
 * 0x80-0x9F are C1 controls in Unicode but printable in cp1252 (euro, trademark,
 * smart quotes), which is why "…" became three characters instead of staying one.
 */
const CP1252_SPECIAL = '\x{0080}-\x{009F}'
    . '|\x{00A1}-\x{00FF}'
    . '|\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0192}\x{02C6}\x{02DC}'
    . '|\x{20AC}\x{2122}';

/** The mojibake tell: a-circumflex followed by a cp1252 special character. */
function isMojibake(string $text): bool
{
    return preg_match('/\x{00E2}(?:' . CP1252_SPECIAL . ')/u', $text) === 1
        || str_contains($text, "\xEF\xBF\xBD");
}

/**
 * Undo the double-encoding, one run at a time.
 *
 * The whole file must NOT be converted: it may hold correctly-encoded UTF-8 too,
 * and pushing that through cp1252 would destroy it. So only the matched runs are
 * touched — each is a short string whose characters are exactly the cp1252
 * readings of the original UTF-8 bytes, so encoding it back to cp1252 recovers
 * those bytes, which are then decoded as UTF-8.
 */
function repair(string $text): string
{
    return preg_replace_callback(
        '/\x{00E2}(?:' . CP1252_SPECIAL . ')+/u',
        static function (array $m): string {
            // U+00E2 U+20AC U+00A6  ->  bytes E2 80 A6  ->  "…"
            $bytes = @iconv('UTF-8', 'Windows-1252', $m[0]);
            if ($bytes === false) {
                return $m[0];
            }
            $decoded = @iconv('UTF-8', 'UTF-8', $bytes);

            return $decoded === false ? $m[0] : $decoded;
        },
        $text
    ) ?? $text;
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$dirty = 0;
foreach ($files as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    foreach ($skipDirs as $skip) {
        if (str_starts_with($rel, $skip)) {
            continue 2;
        }
    }

    $ext = strtolower($file->getExtension());
    if (!in_array($ext, $extensions, true)) {
        continue;
    }

    $path = $file->getPathname();
    $text = file_get_contents($path);
    if ($text === false || !isMojibake($text)) {
        continue;
    }

    $dirty++;

    if ($fix) {
        file_put_contents($path, repair($text));
        echo "fixed  {$rel}\n";
    } else {
        // Show one example so the damage is visible, not just counted.
        preg_match('/.{0,30}\x{00E2}[\x{0080}-\x{20BF}].{0,20}/u', $text, $m);
        echo "DIRTY  {$rel}" . ($m ? '   e.g. ' . str_replace("\n", ' ', $m[0]) : '') . "\n";
    }
}

echo $dirty === 0 ? "clean\n" : ($fix ? "repaired {$dirty} file(s)\n" : "{$dirty} file(s) need --fix\n");