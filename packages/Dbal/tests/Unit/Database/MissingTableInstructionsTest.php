<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Unit\Database;

use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Dbal\Database\MissingTableInstructions;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MissingTableInstructionsTest extends TestCase
{
    public function test_names_the_feature_and_the_table(): void
    {
        $message = MissingTableInstructions::build('deduplication', 'ecotone_deduplication', null);

        $this->assertStringContainsString('deduplication', $message);
        $this->assertStringContainsString('ecotone_deduplication', $message);
    }

    public function test_symfony_instruction_names_bin_console(): void
    {
        $message = MissingTableInstructions::build('deduplication', 'ecotone_deduplication', 'bin/console');

        $this->assertStringContainsString('bin/console ecotone:migration:database:setup --initialize --feature=deduplication', $message);
        $this->assertStringContainsString('bin/console ecotone:migration:database:setup --sql --feature=deduplication', $message);
    }

    public function test_laravel_instruction_names_artisan(): void
    {
        $message = MissingTableInstructions::build('dead_letter', 'ecotone_error_messages', 'php artisan');

        $this->assertStringContainsString('php artisan ecotone:migration:database:setup --initialize --feature=dead_letter', $message);
        $this->assertStringContainsString('php artisan ecotone:migration:database:setup --sql --feature=dead_letter', $message);
    }

    public function test_tempest_instruction_names_tempest_binary(): void
    {
        $message = MissingTableInstructions::build('document_store', 'ecotone_document_store', './tempest');

        $this->assertStringContainsString('./tempest ecotone:migration:database:setup --initialize --feature=document_store', $message);
        $this->assertStringContainsString('./tempest ecotone:migration:database:setup --sql --feature=document_store', $message);
    }

    public function test_ecotone_lite_instruction_spells_out_the_code_to_run_when_there_is_no_console(): void
    {
        $message = MissingTableInstructions::build('message_queue', 'enqueue', null);

        $this->assertStringContainsString("getServiceFromContainer(\\Ecotone\\Api\\Dbal\\ExtensionObject\\DatabaseSetupManager::class)->initialize('message_queue')", $message);
        $this->assertStringContainsString("getCreateSqlStatementsForFeatures(['message_queue'])", $message);
        $this->assertStringNotContainsString('bin/console', $message);
        $this->assertStringNotContainsString('artisan', $message);
    }

    public function test_default_connection_message_stays_unchanged(): void
    {
        $message = MissingTableInstructions::build('deduplication', 'ecotone_deduplication', 'bin/console', DbalConnectionReference::DEFAULT);

        $this->assertStringNotContainsString('--connection=', $message);
        $this->assertStringNotContainsString('on connection', $message);
    }

    public function test_non_default_connection_is_named_in_the_message(): void
    {
        $message = MissingTableInstructions::build('event_stream', 'secondary_connection_stream', null, 'secondary_connection');

        $this->assertStringContainsString("on connection 'secondary_connection'", $message);
    }

    public function test_non_default_connection_adds_connection_option_to_console_commands(): void
    {
        $message = MissingTableInstructions::build('event_stream', 'secondary_connection_stream', 'bin/console', 'secondary_connection');

        $this->assertStringContainsString('bin/console ecotone:migration:database:setup --initialize --feature=event_stream --connection=secondary_connection', $message);
        $this->assertStringContainsString('bin/console ecotone:migration:database:setup --sql --feature=event_stream --connection=secondary_connection', $message);
    }

    public function test_non_default_connection_uses_registry_in_the_programmatic_snippet(): void
    {
        $message = MissingTableInstructions::build('event_stream', 'secondary_connection_stream', null, 'secondary_connection');

        $this->assertStringContainsString(
            "getServiceFromContainer(\\Ecotone\\Api\\Dbal\\ExtensionObject\\DatabaseSetupManagerRegistry::class)->getManagerFor('secondary_connection')->initialize('event_stream')",
            $message
        );
        $this->assertStringContainsString(
            "getManagerFor('secondary_connection')->getCreateSqlStatementsForFeatures(['event_stream'])",
            $message
        );
    }
}
