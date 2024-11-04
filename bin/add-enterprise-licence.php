<?php
/*
 * licence Apache-2.0
 */

require __DIR__ . "/../vendor/autoload.php";

$finder = new \Symfony\Component\Finder\Finder();

$files = $finder->files()->in(__DIR__ . "/../packages")->name("*.php");

function addLicenceToFile(string $file)
{
    $lines = file($file);
    foreach ($lines as $index => $line) {
        if (preg_match('/^\s*(abstract class|final class|class|interface)\s+/', $line)) {
            array_splice($lines, $index, 0, [
                <<<LICENCE
                /**
                 * licence Enterprise
                 */
                
                LICENCE
            ]);
            break;
        }
    }
    file_put_contents($file, implode('', $lines));
}

foreach ($files as $file) {
    $fileContent = file_get_contents($file->getRealPath());

    if (! preg_match('/\*\s*(@licence|licence)\s+(Enterprise|Apache\-2\.0)\s*/', $fileContent, $matches)) {
        addLicenceToFile($file);
    }
}
