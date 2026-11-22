<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

const CHECKER = __DIR__ . '/check-flow-catalogue.php';

$cases = [
    'a complete catalogue passes' => [
        ['one', 'two'], ['one', 'two'], true,
        null,
    ],
    'a scenario method its class does not list' => [
        ['one', 'two'], ['one'], true,
        'DemoFlowScenarios::two() builds the scenario `scenario two`, which Demo\Flow\DemoFlowScenarios::all() does not list',
    ],
    'a scenario class the catalogue does not include' => [
        ['one'], ['one'], false,
        'Demo\Flow\DemoFlowScenarios::all() lists `scenario one`, but Demo\Flow\DemoCatalogue::all() does not include',
    ],
    'two scenarios with the same name' => [
        ['one', 'one'], ['one', 'one'], true,
        'the scenario name `scenario one` is used 2 times',
    ],
];

$failures = 0;
foreach ($cases as $name => [$methods, $listed, $catalogued, $expected]) {
    $directory = demoCatalogue(methods: $methods, listed: $listed, catalogued: $catalogued);
    exec(sprintf('php %s %s %s 2>&1', escapeshellarg(CHECKER), escapeshellarg($directory), escapeshellarg('Demo\Flow\DemoCatalogue')), $output, $exitCode);
    $text = implode("\n", $output);
    $output = [];
    exec('rm -rf ' . escapeshellarg($directory));
    $passed = $expected === null ? $exitCode === 0 : $exitCode === 1 && str_contains($text, $expected);
    if (! $passed) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}\n  exit {$exitCode}, expected " . ($expected ?? 'success') . "\n  got: {$text}\n\n");
        continue;
    }
    echo "ok   {$name}\n";
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of " . count($cases) . " catalogue checker cases failed.\n");
    exit(1);
}
echo count($cases) . " catalogue checker cases passed.\n";

/**
 * @param list<string> $methods
 * @param list<string> $listed
 */
function demoCatalogue(array $methods, array $listed, bool $catalogued): string
{
    $directory = sys_get_temp_dir() . '/check-flow-catalogue-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $bodies = '';
    foreach ($methods as $position => $method) {
        $bodies .= "    public static function {$method}" . ($position > 0 && $method === $methods[0] ? 'Again' : '') . "(): FlowScenario\n    {\n        return new FlowScenario(name: 'scenario {$method}', classesToResolve: [], services: [], extensionObjects: [], stages: []);\n    }\n\n";
    }
    $all = implode(', ', array_map(fn (string $method, int $position): string => 'self::' . $method . ($position > 0 && $method === $listed[0] ? 'Again' : '') . '()', $listed, array_keys($listed)));
    file_put_contents($directory . '/DemoFlowScenarios.php', "<?php\n\nnamespace Demo\\Flow;\n\nuse Ecotone\\Test\\Flow\\FlowScenario;\n\nfinal class DemoFlowScenarios\n{\n    public static function all(): array\n    {\n        return [{$all}];\n    }\n\n{$bodies}}\n");
    file_put_contents($directory . '/DemoCatalogue.php', "<?php\n\nnamespace Demo\\Flow;\n\nfinal class DemoCatalogue\n{\n    public static function all(): array\n    {\n        return " . ($catalogued ? '[...DemoFlowScenarios::all()]' : '[]') . ";\n    }\n}\n");

    return $directory;
}
