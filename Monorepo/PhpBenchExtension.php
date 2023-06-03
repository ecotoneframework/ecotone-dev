<?php

namespace Monorepo;

use PhpBench\DependencyInjection\Container;
use PhpBench\DependencyInjection\ExtensionInterface;
use PhpBench\Expression\ExpressionEvaluator;
use PhpBench\Expression\Printer;
use PhpBench\Extension\ConsoleExtension;
use PhpBench\Extension\ExpressionExtension;
use PhpBench\Report\Console\ObjectRenderer as ConsoleObjectRenderer;
use PhpBench\Report\Console\Renderer\BarChartRenderer;
use PhpBench\Report\Console\Renderer\ReportRenderer;
use PhpBench\Report\Console\Renderer\ReportsRenderer;
use PhpBench\Report\Console\Renderer\TextRenderer;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PhpBenchExtension implements ExtensionInterface
{
    private static ?OutputInterface $output = null;

    public static function setDefaultOutput(OutputInterface $output): void
    {
        self::$output = $output;
    }

    public function configure(OptionsResolver $resolver): void
    {
    }

    public function load(Container $container): void
    {
        $container->set(ConsoleObjectRenderer::class, new ConsoleObjectRenderer(
                self::$output ?? $container->get(ConsoleExtension::SERVICE_OUTPUT_STD),
                new ReportsRenderer(),
                new BarChartRenderer(
                    $container->get(ExpressionEvaluator::class),
                    $container->get(ExpressionExtension::SERVICE_PLAIN_PRINTER)
                ),
                new ReportRenderer(),
                new PhpBenchMarkdownTableRenderer($container->get(Printer::class)),
                new TextRenderer()
            )
        );
    }
}