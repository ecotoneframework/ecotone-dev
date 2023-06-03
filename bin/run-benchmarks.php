#!/usr/bin/env php
<?php

use Monorepo\PhpBenchExtension;
use PhpBench\Console\Application;
use PhpBench\PhpBench;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__ . "/../vendor/autoload.php";

$profilesToBenchmark = [
    'ecotone',
    'symfony',
    'laravel'
];

$commandInput = new ArgvInput(definition: new InputDefinition([
    new InputArgument('output', InputArgument::OPTIONAL),
    new InputOption('--baseline', null, InputOption::VALUE_NONE, 'generate baseline'),
]));
$outputToStream = $commandInput->getArgument('output');
$generateBaseline = $commandInput->getOption('baseline');

$console = new ConsoleOutput();
$buffer = new BufferedOutput();
$buffer->writeln("# PR stats");
PhpBenchExtension::setDefaultOutput($buffer);
foreach ($profilesToBenchmark as $profile) {
    $buffer->writeln("<details><summary>$profile benchmarks</summary>");
    $input = $generateBaseline
        ? new StringInput("run --profile=$profile --report=aggregate --tag=main")
        : new StringInput("run --profile=$profile --report=aggregate --ref=main");

    $container = PhpBench::loadContainer($input);

    $app = $container->get(Application::class);
    $app->setAutoExit(false);
    $app->run($input, $console);
    $buffer->writeln("</details>");
}

if ($outputToStream) {
    file_put_contents($outputToStream, $buffer->fetch());
} else {
    $console->writeln($buffer->fetch());
}
