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
    new InputOption('--ref', null, InputOption::VALUE_OPTIONAL, 'baseline tag'),
]));
$outputToStream = $commandInput->getArgument('output');
$generateBaseline = $commandInput->getOption('baseline');
$refBaseline = $commandInput->getOption('ref');

$console = new ConsoleOutput();
$buffer = new BufferedOutput();
$buffer->writeln("# PR stats");
PhpBenchExtension::setDefaultOutput($buffer);
foreach ($profilesToBenchmark as $profile) {
    $buffer->writeln("<details><summary>$profile benchmarks</summary>");
    $inputString = $generateBaseline
        ? "run --profile=$profile --report=aggregate --tag=main"
        : "run --profile=$profile --report=aggregate";
    if ($refBaseline) {
        $inputString .= " --ref=$refBaseline";
    }
    $input = new StringInput($inputString);

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
