<?php
/*
 * licence Apache-2.0
 */

require __DIR__ . "/../vendor/autoload.php";

$finder = new \Symfony\Component\Finder\Finder();

$files = $finder->files()->in(__DIR__ . "/../packages/*/src")->name("*.php");

$enterpriseNotice = require __DIR__ . "/enterprise-licence-notice.php";
$isEnterpriseLicensedFile = require __DIR__ . "/enterprise-licence-scope.php";
$enterpriseNoticeLines = explode("\n", $enterpriseNotice);
$enterpriseNoticeBody = implode("\n", array_slice($enterpriseNoticeLines, 2, -2)) . "\n";
$enterpriseNoticeBodyLineCount = count($enterpriseNoticeLines) - 4;
$enterpriseNoticeLastLine = $enterpriseNoticeLines[count($enterpriseNoticeLines) - 3];

function addLicenceToFile(string $file, string $enterpriseNotice)
{
    $lines = file($file);
    foreach ($lines as $index => $line) {
        if (preg_match('/^\s*(abstract class|final class|class|interface|trait)\s+/', $line)) {
            array_splice($lines, $index, 0, [$enterpriseNotice]);
            break;
        }
    }
    file_put_contents($file, implode('', $lines));
}

function findSupersededNoticeEnd(array $lines, int $markerLine, string $enterpriseNoticeLastLine): ?int
{
    for ($index = $markerLine + 1; $index < count($lines) && trim($lines[$index]) !== '*/'; $index++) {
        if (rtrim($lines[$index], "\r\n") === $enterpriseNoticeLastLine) {
            return $index;
        }
    }

    return null;
}

function completeEnterpriseNoticeInFile(string $file, string $enterpriseNotice, string $enterpriseNoticeBody, int $enterpriseNoticeBodyLineCount, string $enterpriseNoticeLastLine)
{
    $lines = file($file);
    $markerLine = array_key_first(preg_grep('/^\s*\*\s*(@licence|licence)\s+Enterprise\s*$/', $lines));
    if ($markerLine === null || implode('', array_slice($lines, $markerLine + 1, $enterpriseNoticeBodyLineCount)) === $enterpriseNoticeBody) {
        return;
    }

    $supersededNoticeEnd = findSupersededNoticeEnd($lines, $markerLine, $enterpriseNoticeLastLine);
    if ($supersededNoticeEnd !== null) {
        array_splice($lines, $markerLine + 1, $supersededNoticeEnd - $markerLine, [$enterpriseNoticeBody]);
        file_put_contents($file, implode('', $lines));

        return;
    }

    $lineAfterMarker = trim($lines[$markerLine + 1] ?? '');
    $lineBeforeMarker = trim($lines[$markerLine - 1] ?? '');
    if ($lineAfterMarker === '*/' && in_array($lineBeforeMarker, ['/*', '/**'], true)) {
        array_splice($lines, $markerLine - 1, 3, [$enterpriseNotice]);
    } else {
        array_splice($lines, $markerLine + 1, 0, [$enterpriseNoticeBody . (in_array($lineAfterMarker, ['*/', '*'], true) ? '' : " *\n")]);
    }
    file_put_contents($file, implode('', $lines));
}

foreach ($files as $file) {
    $fileContent = file_get_contents($file->getRealPath());

    if (! preg_match('/\*\s*(@licence|licence)\s+(Enterprise|MIT|Apache\-2\.0)\s*/', $fileContent, $matches)) {
        addLicenceToFile($file, $enterpriseNotice);
    }
}

$enterpriseFiles = (new \Symfony\Component\Finder\Finder())
    ->files()
    ->in(__DIR__ . "/../packages/*")
    ->exclude('vendor')
    ->name("*.php")
    ->contains('/\*\s*(@licence|licence)\s+Enterprise\b/')
    ->filter($isEnterpriseLicensedFile);

foreach ($enterpriseFiles as $file) {
    completeEnterpriseNoticeInFile($file->getRealPath(), $enterpriseNotice, $enterpriseNoticeBody, $enterpriseNoticeBodyLineCount, $enterpriseNoticeLastLine);
}
