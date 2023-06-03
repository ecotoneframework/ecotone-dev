<?php

namespace Monorepo;

use PhpBench\Expression\Ast\Node;
use PhpBench\Expression\Printer;
use PhpBench\Report\Console\ObjectRenderer;
use PhpBench\Report\Console\ObjectRendererInterface;
use PhpBench\Report\Model\Table;
use PhpBench\Report\Model\TableRow;
use Symfony\Component\Console\Helper\Table as SymfonyTable;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This class is mainly a copy of the TableRenderer class from phpbench/phpbench
 */
class PhpBenchMarkdownTableRenderer implements ObjectRendererInterface
{
    /**
     * @var Printer
     */
    private $printer;

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
    }

    public function render(OutputInterface $output, ObjectRenderer $renderer, object $object): bool
    {
        if (!$object instanceof Table) {
            return false;
        }

        $rows = [];

        if ($object->title()) {
            $output->writeln(sprintf('%s', $object->title()));
        }

        $buffer = new BufferedOutput();
        $style = new TableStyle();
        $style->setDefaultCrossingChar('|');

        $consoleTable = new SymfonyTable($buffer);
        $consoleTable->setStyle($style);
        $consoleTable->setHeaders($this->buildHeaders($object));
        $consoleTable->setRows($this->buildRows($object));
        $consoleTable->render();

        // Remove first and last lines of the table
        // to make it valid markdown.
        $output_lines = explode("\n", $buffer->fetch());
        array_shift($output_lines);
        array_pop($output_lines);
        array_pop($output_lines);
        $markdown_table = implode("\n", $output_lines);

        $output->write($markdown_table);
        $output->writeln('');

        return true;
    }

    /**
     * @return array<array<string,mixed>>
     */
    private function buildRows(Table $table): array
    {
        return array_map(function (TableRow $row) {
            return array_map(function (Node $node) {
                return $this->printer->print($node);
            }, $row->cells());
        }, $table->rows());
    }

    /**
     * @return array<mixed>
     */
    private function buildHeaders(Table $object): array
    {
        if (count($object->columnGroups()) <= 1) {
            return $object->columnNames();
        }

        $groups = [];

        foreach ($object->columnGroups() as $colGroup) {
            $label = $colGroup->label();
            $label = $colGroup->isDefault() ? '' : $label;
            $groups[] = new TableCell($label, ['colspan' => $colGroup->size()]);
        }

        return [
            $groups,
            $object->columnNames(),
        ];
    }
}