<?php
/*
 * licence Apache-2.0
 */

require __DIR__ . "/../vendor/autoload.php";

$finder = new \Symfony\Component\Finder\Finder();

$files = $finder->files()->in(__DIR__ . "/../packages/*/src")->name("*.php");

foreach ($files as $file) {
    $fileContent = file_get_contents($file->getRealPath());

    if (! preg_match('/\*\s*(@licence|licence)\s+(Enterprise|Apache\-2\.0)\s*/', $fileContent, $matches)) {
        throw new \RuntimeException("Missing licence in file: " . $file->getRealPath(). "\n. You can add it related licence by triggering `bin/add-apache-licence.php` or if you are changing Enterprise modules `bin/add-enterprise-licence.php`");
    }
}
