<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConsoleWriterCommandTest extends EcotoneIntegrationTestCase
{
    public function test_console_command_writes_formatted_output_through_tempest_console(): void
    {
        $this->console
            ->call('console-writer:show', ['name' => 'orders'])
            ->assertSuccess()
            ->assertContains('Starting orders')
            ->assertContains('Processed orders')
            ->assertContains('Almost done orders')
            ->assertContains('Failed orders')
            ->assertContains('running')
            ->assertContains('2/2');
    }
}
