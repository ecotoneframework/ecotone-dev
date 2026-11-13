<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler;

use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Handler\ClosureInAttribute\ConvertedClosureExpressionService;
use Test\Ecotone\Messaging\Fixture\Handler\ClosureInAttribute\PreferredChannel;
use Test\Ecotone\Messaging\Fixture\Handler\ClosureInAttribute\TenantChannels;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
#[RequiresPhp('>= 8.5.0')]
final class ClosureExpressionResultConversionTest extends TestCase
{
    public function test_reference_closure_expression_result_is_converted_to_the_parameter_type(): void
    {
        $handler = new ConvertedClosureExpressionService();

        $this->bootstrapWith($handler)->sendCommandWithRouting('channel.fromReferenceClosure', metadata: ['tenantId' => 'tenant-a']);

        self::assertSame(PreferredChannel::Sms, $handler->channel);
    }

    public function test_header_closure_expression_result_is_converted_to_the_parameter_type(): void
    {
        $handler = new ConvertedClosureExpressionService();

        $this->bootstrapWith($handler)->sendCommandWithRouting('channel.fromHeaderClosure', metadata: ['preferences' => ['channel' => 'email']]);

        self::assertSame(PreferredChannel::Email, $handler->channel);
    }

    public function test_payload_closure_expression_result_is_converted_to_the_parameter_type(): void
    {
        $handler = new ConvertedClosureExpressionService();

        $this->bootstrapWith($handler)->sendCommandWithRouting('channel.fromPayloadClosure', ['channel' => 'email']);

        self::assertSame(PreferredChannel::Email, $handler->channel);
    }

    public function test_attribute_extending_reference_resolves_a_query_result_converted_to_the_parameter_type(): void
    {
        $handler = new ConvertedClosureExpressionService();

        $this->bootstrapWith($handler)->sendCommandWithRouting('channel.fromQuery', metadata: ['tenantId' => 'tenant-b']);

        self::assertSame(PreferredChannel::Email, $handler->channel);
    }

    private function bootstrapWith(ConvertedClosureExpressionService $handler): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [ConvertedClosureExpressionService::class, TenantChannels::class],
            [$handler, new TenantChannels()],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
