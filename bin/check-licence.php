<?php

/*
 * licence Apache-2.0
 */

require __DIR__ . '/../vendor/autoload.php';

$finder = new Symfony\Component\Finder\Finder();

$files = $finder->files()->in(__DIR__ . '/../packages/*/src')->name('*.php');

$pulseFeaturesInOpenSourceFiles = [];
foreach ($files as $file) {
    $fileContent = file_get_contents($file->getRealPath());

    if (! preg_match('/\*\s*(@licence|licence)\s+(Enterprise|MIT|Apache\-2\.0|BSD\-3\-Clause)\s*/', $fileContent, $matches)) {
        throw new RuntimeException('Missing licence in file: ' . $file->getRealPath(). "\n. You can add related licence by triggering `php bin/add-apache-licence.php` or if you are changing Enterprise modules `php bin/add-enterprise-licence.php`");
    }
    if ($matches[2] === 'Apache-2.0' && preg_match('/\bPulseFeature\b/', $fileContent)) {
        $pulseFeaturesInOpenSourceFiles[] = $file->getRealPath();
    }
}

if ($pulseFeaturesInOpenSourceFiles !== []) {
    throw new RuntimeException("These licence Apache-2.0 files build Ecotone Pulse feature reports, which are Enterprise:\n" . implode("\n", $pulseFeaturesInOpenSourceFiles) . "\nMove each report into a licence Enterprise <Module>PulseFeatures class beside its module, whose static reportedFor(\$serviceExtensions, ...) returns nothing unless PulseConfiguration is registered, and call it from the module's getModuleExtensions().");
}

$enterpriseLicenceNoticeViolations = (require __DIR__ . '/enterprise-licence-notice-violations.php')(dirname(__DIR__));
if ($enterpriseLicenceNoticeViolations !== []) {
    throw new RuntimeException(implode("\n\n", $enterpriseLicenceNoticeViolations));
}
