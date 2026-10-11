<?php
/*
 * licence Apache-2.0
 */

require __DIR__ . "/../vendor/autoload.php";

$finder = new \Symfony\Component\Finder\Finder();

$files = $finder->files()->in(__DIR__ . "/../packages/*/src")->name("*.php");

foreach ($files as $file) {
    $fileContent = file_get_contents($file->getRealPath());

    if (! preg_match('/\*\s*(@licence|licence)\s+(Enterprise|MIT|Apache\-2\.0|BSD\-3\-Clause)\s*/', $fileContent, $matches)) {
        throw new \RuntimeException("Missing licence in file: " . $file->getRealPath(). "\n. You can add related licence by triggering `php bin/add-apache-licence.php` or if you are changing Enterprise modules `php bin/add-enterprise-licence.php`");
    }
}

$enterpriseNotice = require __DIR__ . "/enterprise-licence-notice.php";
$enterpriseNoticeLines = explode("\n", $enterpriseNotice);
$enterpriseNoticeBody = implode("\n", array_slice($enterpriseNoticeLines, 2, -2)) . "\n";
$enterpriseNoticeBodyLineCount = count($enterpriseNoticeLines) - 4;

$enterpriseFiles = (new \Symfony\Component\Finder\Finder())
    ->files()
    ->in(__DIR__ . "/../packages/*")
    ->exclude('vendor')
    ->name("*.php")
    ->contains('/\*\s*(@licence|licence)\s+Enterprise\b/');

foreach ($enterpriseFiles as $file) {
    $lines = file($file->getRealPath());
    $markerLine = array_key_first(preg_grep('/^\s*\*\s*(@licence|licence)\s+Enterprise\s*$/', $lines));

    if ($markerLine === null || implode('', array_slice($lines, $markerLine + 1, $enterpriseNoticeBodyLineCount)) !== $enterpriseNoticeBody) {
        throw new \RuntimeException(
            "Incomplete Enterprise licence notice in file: " . $file->getRealPath() . "\n"
            . "The file is marked `licence Enterprise`, but the marker line is not followed by the full Enterprise notice, so a reader of this file alone is not told that the code is proprietary, needs a licence, and may not be copied.\n"
            . "Run `php bin/add-enterprise-licence.php`, which completes the notice in every Enterprise file, or put the text of `bin/enterprise-licence-notice.php` in place of the short header by hand. The notice has to follow the first `licence Enterprise` line unchanged:\n"
            . $enterpriseNotice
        );
    }
}
