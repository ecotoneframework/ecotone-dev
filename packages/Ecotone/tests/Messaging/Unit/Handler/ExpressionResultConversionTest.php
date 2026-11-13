<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class ExpressionResultConversionTest extends TestCase
{
    public function test_reference_expression_result_is_converted_to_the_parameter_type_with_a_registered_converter(): void
    {
        $handler = new class () {
            public ?TenantInvoicing $invoicing = null;

            #[CommandHandler('invoice.issue')]
            public function issue(
                #[Reference(QueryBus::class, "service.sendWithRouting('tenant.invoicing')")] TenantInvoicing $invoicing,
            ): void {
                $this->invoicing = $invoicing;
            }

            #[QueryHandler('tenant.invoicing')]
            public function invoicingOf(#[Header('tenantId')] string $tenantId): array
            {
                return ['tenantId' => $tenantId, 'currency' => 'eur'];
            }

            #[Converter]
            public function toTenantInvoicing(array $invoicing): TenantInvoicing
            {
                return new TenantInvoicing($invoicing['tenantId'], $invoicing['currency']);
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting([$handler::class], [$handler], enabledModules: []);

        $ecotone->sendCommandWithRouting('invoice.issue', metadata: ['tenantId' => 'tenant-a']);

        self::assertEquals(new TenantInvoicing('tenant-a', 'eur'), $handler->invoicing);
    }

    public function test_reference_expression_scalar_result_is_converted_to_an_enum_parameter(): void
    {
        $handler = new class () {
            public ?NotificationChannel $channel = null;

            #[CommandHandler('notification.send')]
            public function send(
                #[Reference('tenantSettings', 'service.channelName()')] NotificationChannel $channel,
            ): void {
                $this->channel = $channel;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, 'tenantSettings' => new TenantSettings()],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('notification.send');

        self::assertSame(NotificationChannel::Sms, $handler->channel);
    }

    public function test_reference_expression_result_already_of_the_parameter_type_is_passed_as_it_is(): void
    {
        $handler = new class () {
            public ?TenantInvoicing $invoicing = null;

            #[CommandHandler('invoice.issue')]
            public function issue(
                #[Reference('tenantSettings', 'service.invoicing()')] TenantInvoicing $invoicing,
            ): void {
                $this->invoicing = $invoicing;
            }
        };
        $tenantSettings = new TenantSettings();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, 'tenantSettings' => $tenantSettings],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('invoice.issue');

        self::assertSame($tenantSettings->invoicing(), $handler->invoicing);
    }

    public function test_reference_expression_result_without_a_converter_reaches_the_handler_unchanged(): void
    {
        $handler = new class () {
            public ?float $limit = null;

            #[CommandHandler('limit.read')]
            public function read(
                #[Reference('tenantSettings', 'service.limit()')] float $limit,
            ): void {
                $this->limit = $limit;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, 'tenantSettings' => new TenantSettings()],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('limit.read');

        self::assertSame(5.0, $handler->limit);
    }

    public function test_header_expression_result_is_converted_to_the_parameter_type(): void
    {
        $handler = new class () {
            public ?NotificationChannel $channel = null;

            #[CommandHandler('notification.send')]
            public function send(
                #[Header('preferences', "value['channel']")] NotificationChannel $channel,
            ): void {
                $this->channel = $channel;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting([$handler::class], [$handler], enabledModules: []);

        $ecotone->sendCommandWithRouting('notification.send', metadata: ['preferences' => ['channel' => 'sms']]);

        self::assertSame(NotificationChannel::Sms, $handler->channel);
    }

    public function test_payload_expression_result_is_converted_to_the_parameter_type(): void
    {
        $handler = new class () {
            public ?NotificationChannel $channel = null;

            #[CommandHandler('notification.send')]
            public function send(
                #[Payload("value['channel']")] NotificationChannel $channel,
            ): void {
                $this->channel = $channel;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting([$handler::class], [$handler], enabledModules: []);

        $ecotone->sendCommandWithRouting('notification.send', ['channel' => 'sms']);

        self::assertSame(NotificationChannel::Sms, $handler->channel);
    }
}

enum NotificationChannel: string
{
    case Sms = 'sms';
}

final class TenantInvoicing
{
    public function __construct(public string $tenantId, public string $currency)
    {
    }
}

final class TenantSettings
{
    private TenantInvoicing $invoicing;

    public function __construct()
    {
        $this->invoicing = new TenantInvoicing('tenant-a', 'eur');
    }

    public function channelName(): string
    {
        return 'sms';
    }

    public function invoicing(): TenantInvoicing
    {
        return $this->invoicing;
    }

    public function limit(): int
    {
        return 5;
    }
}
