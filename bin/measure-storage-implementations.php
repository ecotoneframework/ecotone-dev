<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

const SEAMS = [
    'event store' => [
        'storage' => 'packages/PdoEventSourcing/src/Dbal/DbalEventStore.php',
        'in-memory' => 'packages/Ecotone/src/EventSourcing/EventStore/InMemoryEventStore.php',
    ],
    'document store' => [
        'storage' => 'packages/Dbal/src/DocumentStore/DbalDocumentStore.php',
        'in-memory' => 'packages/Ecotone/src/Messaging/Store/Document/InMemoryDocumentStore.php',
    ],
    'deduplication' => [
        'storage' => 'packages/Dbal/src/Deduplication/DbalHandledMessages.php',
        'in-memory' => 'packages/Dbal/src/Deduplication/InMemoryHandledMessages.php',
    ],
    'dead letter' => [
        'storage' => 'packages/Dbal/src/Recoverability/DbalDeadLetterStore.php',
        'in-memory' => 'packages/Dbal/src/Recoverability/InMemoryDeadLetterStore.php',
    ],
];

$usage = <<<'USAGE'
    Counts the tests that execute each storage seam's storage-backed and in-memory implementation,
    by running the root testsuites under per-test code coverage limited to the seam classes.
    A test counts for an implementation when it executes a line of that class outside its constructor.

    Run inside the app container, one invocation at a time:
      XDEBUG_MODE=coverage php bin/measure-storage-implementations.php <output-dir> [testsuite name ...]

    Without testsuite names every root testsuite runs. <output-dir> receives one coverage file per
    suite, the per-test listing (tests.json) and the summary (summary.txt).

    USAGE;

if ($argc < 2) {
    fwrite(STDERR, $usage);
    exit(2);
}

$outputDirectory = rtrim($argv[1], '/');
if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0777, true)) {
    fwrite(STDERR, "Cannot create {$outputDirectory}\n");
    exit(2);
}

$rootConfiguration = new DOMDocument();
$rootConfiguration->load($root . '/phpunit.xml.dist');
$suiteNames = array_slice($argv, 2) ?: array_map(
    fn (DOMElement $suite) => $suite->getAttribute('name'),
    iterator_to_array($rootConfiguration->getElementsByTagName('testsuite')),
);

$measuringConfiguration = $root . '/phpunit.measure-storage-implementations.xml';
file_put_contents($measuringConfiguration, configurationLimitedToSeams($rootConfiguration));

$testsByImplementation = [];
$suiteExitCodes = [];
try {
    foreach ($suiteNames as $suiteName) {
        $coverageFile = $outputDirectory . '/' . preg_replace('/[^a-z0-9]+/i', '-', $suiteName) . '.cov';
        @unlink($coverageFile);
        passthru(sprintf(
            'cd %s && php -d memory_limit=-1 vendor/bin/phpunit --configuration %s --testsuite %s --coverage-php %s > %s 2>&1',
            escapeshellarg($root),
            escapeshellarg($measuringConfiguration),
            escapeshellarg($suiteName),
            escapeshellarg($coverageFile),
            escapeshellarg($coverageFile . '.log'),
        ), $exitCode);
        $suiteExitCodes[$suiteName] = $exitCode;

        if (! file_exists($coverageFile)) {
            continue;
        }

        /** @var CodeCoverage $coverage */
        $coverage = include $coverageFile;
        foreach (testsExecutingSeams($root, $coverage->getData()->lineCoverage()) as $seam => $implementations) {
            foreach ($implementations as $implementation => $tests) {
                foreach ($tests as $test) {
                    $testsByImplementation[$seam][$implementation][$test] = $suiteName;
                }
            }
        }
    }
} finally {
    unlink($measuringConfiguration);
}

$summary = [];
foreach (SEAMS as $seam => $implementations) {
    foreach (array_keys($implementations) as $implementation) {
        $tests = $testsByImplementation[$seam][$implementation] ?? [];
        ksort($tests);
        $testsByImplementation[$seam][$implementation] = $tests;
        $summary[] = sprintf('%-15s %-10s %5d tests', $seam, $implementation, count($tests));
    }
}
$storageBackedTests = [];
foreach ($testsByImplementation as $implementations) {
    $storageBackedTests += $implementations['storage'] ?? [];
}
$summary[] = sprintf('%-26s %5d tests', 'storage-backed, any seam', count($storageBackedTests));
foreach ($suiteExitCodes as $suiteName => $exitCode) {
    $summary[] = sprintf('suite %-25s phpunit exit %d', $suiteName, $exitCode);
}

file_put_contents($outputDirectory . '/tests.json', json_encode($testsByImplementation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($outputDirectory . '/summary.txt', implode("\n", $summary) . "\n");
echo implode("\n", $summary), "\n";

function configurationLimitedToSeams(DOMDocument $rootConfiguration): string
{
    $configuration = clone $rootConfiguration;
    $source = $configuration->getElementsByTagName('source')->item(0);
    while ($source->firstChild !== null) {
        $source->removeChild($source->firstChild);
    }
    $include = $configuration->createElement('include');
    foreach (SEAMS as $implementations) {
        foreach ($implementations as $file) {
            $include->appendChild($configuration->createElement('file', $file));
        }
    }
    $source->appendChild($include);

    $coverage = $configuration->getElementsByTagName('coverage')->item(0);
    $coverage?->parentNode->removeChild($coverage);

    return $configuration->saveXML();
}

/**
 * @param array<string, array<int, string[]|null>> $lineCoverage
 * @return array<string, array<string, string[]>>
 */
function testsExecutingSeams(string $root, array $lineCoverage): array
{
    $result = [];
    foreach (SEAMS as $seam => $implementations) {
        foreach ($implementations as $implementation => $file) {
            $path = realpath($root . '/' . $file);
            $constructorLines = constructorLines($path);
            $tests = [];
            foreach ($lineCoverage[$path] ?? [] as $line => $coveringTests) {
                if (in_array($line, $constructorLines, true)) {
                    continue;
                }
                foreach ($coveringTests ?? [] as $test) {
                    $tests[$test] = true;
                }
            }
            $result[$seam][$implementation] = array_keys($tests);
        }
    }

    return $result;
}

/**
 * @return int[]
 */
function constructorLines(string $path): array
{
    $tokens = PhpToken::tokenize(file_get_contents($path));
    foreach ($tokens as $index => $token) {
        if ($token->is(T_STRING) && $token->text === '__construct' && $tokens[$index - 2]->is(T_FUNCTION)) {
            $depth = 0;
            for ($cursor = $index; $cursor < count($tokens); $cursor++) {
                if ($tokens[$cursor]->text === '{') {
                    $depth++;
                } elseif ($tokens[$cursor]->text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return range($token->line, $tokens[$cursor]->line);
                    }
                }
            }
        }
    }

    return [];
}
