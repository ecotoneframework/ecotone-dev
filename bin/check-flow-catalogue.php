<?php

declare(strict_types=1);

/*
 * licence Apache-2.0
 */

use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Test\Flow\FlowScenario;
use Ecotone\Test\Flow\FlowScenarioCatalogue;

require dirname(__DIR__) . '/vendor/autoload.php';

$directory = rtrim($argv[1] ?? dirname(__DIR__) . '/packages/Ecotone/src/Test/Flow', '/');
$catalogue = $argv[2] ?? FlowScenarioCatalogue::class;

$problems = catalogueProblems(directory: $directory, catalogue: $catalogue);
if ($problems !== []) {
    fwrite(STDERR, "The flow scenario catalogue is incomplete:\n- " . implode("\n- ", $problems) . "\n");
    exit(1);
}
echo 'Flow scenario catalogue: every scenario method is listed by its class, every class by ' . $catalogue . ", every scenario name is used once, and every scenario of a coroutine message channel is marked as needing a coroutine runtime.\n";

/**
 * @return list<string>
 */
function catalogueProblems(string $directory, string $catalogue): array
{
    $problems = [];
    $classes = [];
    foreach (glob($directory . '/*FlowScenarios.php') ?: [] as $file) {
        preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($file), $namespace);
        $classes[$file] = ($namespace[1] ?? '') . '\\' . basename($file, '.php');
        loadClass(class: $classes[$file], file: $file);
    }
    loadClass(class: $catalogue, file: $directory . '/' . substr(strrchr('\\' . $catalogue, '\\'), 1) . '.php');
    $catalogued = array_map(fn (FlowScenario $scenario): string => $scenario->name, $catalogue::all());
    foreach (array_count_values($catalogued) as $name => $times) {
        if ($times > 1) {
            $problems[] = "the scenario name `{$name}` is used {$times} times; each name becomes the file name of its baseline snapshot, so give each scenario its own name";
        }
    }
    foreach ($classes as $file => $class) {
        if (! class_exists($class) || ! method_exists($class, 'all')) {
            $problems[] = "{$file} declares no class {$class} with a static all(): name the class after its file and list its scenarios in all()";
            continue;
        }
        $listed = array_map(fn (FlowScenario $scenario): string => $scenario->name, $class::all());
        foreach ($class::all() as $scenario) {
            if (! $scenario->needsACoroutineRuntime && array_filter($scenario->extensionObjects, fn (object $extensionObject): bool => $extensionObject instanceof CoroutineMessageChannelBuilder) !== []) {
                $problems[] = "{$class}::all() lists `{$scenario->name}`, which registers a coroutine message channel but is not marked as needing a coroutine runtime: end the scenario with ->needingACoroutineRuntime(), so it is left out where neither swoole nor openswoole is loaded instead of failing there";
            }
        }
        foreach (array_diff($listed, $catalogued) as $name) {
            $problems[] = "{$class}::all() lists `{$name}`, but {$catalogue}::all() does not include {$class}::all(): add `...{$class}::all()` to it, so the baseline and the conformance tests run the scenario";
        }
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_STATIC) as $method) {
            if ($method->name === 'all' || $method->getNumberOfRequiredParameters() > 0 || (string) $method->getReturnType() !== FlowScenario::class) {
                continue;
            }
            $name = $method->invoke(null)->name;
            if (! in_array($name, $listed, true)) {
                $problems[] = "{$class}::{$method->name}() builds the scenario `{$name}`, which {$class}::all() does not list: add self::{$method->name}() to all(), or delete the method";
            }
        }
    }

    return $problems;
}

function loadClass(string $class, string $file): void
{
    if (! class_exists($class) && is_file($file)) {
        require_once $file;
    }
}
