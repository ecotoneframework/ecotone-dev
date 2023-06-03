#!/usr/bin/env php
<?php

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

$vendorDir = __DIR__ . "/../vendor/";

require $vendorDir . "autoload.php";

$profilesToBenchmark = [
    'ecotone',
    'symfony',
    'laravel',
    'ecotone.opcache',
    'symfony.opcache',
    'laravel.opcache'
];

$commandInput = new ArgvInput(definition: new InputDefinition([
    new InputArgument('output', InputArgument::OPTIONAL),
    new InputOption('--baseline', null, InputOption::VALUE_NONE, 'generate baseline'),
    new InputOption('--ref', null, InputOption::VALUE_OPTIONAL, 'baseline tag'),
]));
$outputToStream = $commandInput->getArgument('output');
$generateBaseline = $commandInput->getOption('baseline');
$refBaseline = $commandInput->getOption('ref');

$console = new ConsoleOutput();
$buffer = new BufferedOutput();
$buffer->writeln("# PR stats");

foreach ($profilesToBenchmark as $profile) {
    $buffer->writeln("<details><summary>$profile benchmarks</summary>");
    $buffer->writeln("");
    $inputString = $generateBaseline
        ? "run --profile=$profile --progress=none --report=github-report --tag=main.$profile"
        : "run --profile=$profile --progress=none --report=github-report";
    if ($refBaseline) {
        $inputString .= " --ref=$refBaseline.$profile";
    }

    $output = [];
    exec("$vendorDir/bin/phpbench $inputString", $output);

    array_shift($output);
    array_pop($output);
    array_pop($output);

    $output = array_map(fn($line) => str_replace("-+-", " | ", $line), $output);
    $output = array_map(fn($line) => str_replace("+-", "| ", $line), $output);
    $output = array_map(fn($line) => str_replace("-+", " |", $line), $output);

    $buffer->writeln(implode("\n", $output));
    $buffer->writeln("");
    $buffer->writeln("</details>");
}

if ($outputToStream) {
    file_put_contents($outputToStream, $buffer->fetch());
} else {
    $console->writeln($buffer->fetch());
}
