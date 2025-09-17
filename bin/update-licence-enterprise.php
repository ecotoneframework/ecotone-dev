<?php
declare(strict_types=1);

// Usage:
//   php bin/update-licence-enterprise.php --dry-run   # show what would change
//   php bin/update-licence-enterprise.php --apply     # apply changes

/**
 * Updates licence string from "Apache-2.0" to "Enterprise" for files
 * that were added on this branch since main (git tri-dot range main...HEAD).
 */

function printLine(string $message): void
{
    fwrite(STDOUT, $message . "\n");
}

function printError(string $message): void
{
    fwrite(STDERR, $message . "\n");
}

function isBinaryFile(string $path): bool
{
    $contents = @file_get_contents($path, false, null, 0, 8000);
    if ($contents === false) {
        return true; // treat unreadable as binary to be safe
    }
    return strpos($contents, "\0") !== false;
}

function getAddedFilesSinceMain(): array
{
    // Use symmetric difference to compare HEAD changes since the fork point with main
    $cmd = 'git diff --diff-filter=A --name-only main...HEAD 2>/dev/null';
    $output = shell_exec($cmd);
    if ($output === null) {
        return [];
    }
    $files = array_filter(array_map('trim', explode("\n", $output)));
    // Keep only files that still exist in working tree
    $files = array_values(array_filter($files, static function (string $file): bool {
        return is_file($file);
    }));
    return $files;
}

function updateLicenceInFile(string $path): array
{
    $original = file_get_contents($path);
    if ($original === false) {
        return ['changed' => false, 'error' => 'unreadable'];
    }

    // Replace only the licence token occurrences
    $updated = str_replace('Apache-2.0', 'Enterprise', $original);

    if ($updated === $original) {
        return ['changed' => false];
    }

    return ['changed' => true, 'updated' => $updated];
}

function main(array $argv): int
{
    $apply = in_array('--apply', $argv, true);
    $dryRun = in_array('--dry-run', $argv, true) || ! $apply;

    if (! $apply && ! $dryRun) {
        $dryRun = true; // default to dry-run
    }

    $files = getAddedFilesSinceMain();
    if (empty($files)) {
        printLine('No added files detected since main...HEAD.');
        return 0;
    }

    $processed = 0;
    $changed = 0;
    $skippedBinary = 0;
    $errors = 0;

    foreach ($files as $file) {
        $processed++;

        if (isBinaryFile($file)) {
            $skippedBinary++;
            continue;
        }

        $result = updateLicenceInFile($file);
        if (isset($result['error'])) {
            $errors++;
            printError("Error reading {$file}: {$result['error']}");
            continue;
        }

        if (! $result['changed']) {
            continue;
        }

        $changed++;
        if ($dryRun) {
            printLine("Would update: {$file}");
        } else {
            $bytes = file_put_contents($file, $result['updated']);
            if ($bytes === false) {
                $errors++;
                printError("Failed to write changes to {$file}");
            } else {
                printLine("Updated: {$file}");
            }
        }
    }

    printLine('');
    printLine('Summary:');
    printLine("- Files inspected: {$processed}");
    printLine("- Files changed:  {$changed}" . ($dryRun ? ' (dry-run)' : ''));
    if ($skippedBinary > 0) {
        printLine("- Binary/unreadable skipped: {$skippedBinary}");
    }
    if ($errors > 0) {
        printLine("- Errors: {$errors}");
    }

    return $errors > 0 ? 1 : 0;
}

exit(main($argv));


