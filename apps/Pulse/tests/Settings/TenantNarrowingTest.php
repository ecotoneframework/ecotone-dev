<?php

declare(strict_types=1);

namespace App\Tests\Settings;

use App\Settings\PulseConfigurationException;
use App\Settings\PulseSettings;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TenantNarrowingTest extends TestCase
{
    private const BASE = [
        'ECOTONE_LICENCE_KEY' => 'key',
        'PULSE_VERSION' => 'test',
        'PULSE_TRANSPORT_DSN' => 'amqp://guest:guest@rabbitmq:5672',
    ];

    public function test_a_service_entry_can_narrow_the_tenants_pulse_asks_for(): void
    {
        $settings = PulseSettings::fromEnvironment([...self::BASE, 'PULSE_SERVICES' => 'orders{tenants=tenant_a,tenant_b},billing:billing_channel{tenants=tenant_c},shipping'], __DIR__);

        self::assertSame(['tenant_a', 'tenant_b'], $settings->routes['orders']->tenants);
        self::assertSame(['tenant_c'], $settings->routes['billing']->tenants);
        self::assertSame('billing_channel', $settings->routes['billing']->channel);
        self::assertNull($settings->routes['shipping']->tenants);
    }

    public function test_a_tenant_list_with_a_blank_tenant_is_refused_with_the_form_to_use(): void
    {
        $this->expectException(PulseConfigurationException::class);
        $this->expectExceptionMessage('PULSE_SERVICES entry "orders{tenants=}" names tenant "", which is not a tenant name. List the tenants Pulse should ask for as "service_name{tenants=tenant_a,tenant_b}"');

        PulseSettings::fromEnvironment([...self::BASE, 'PULSE_SERVICES' => 'orders{tenants=}'], __DIR__);
    }

    public function test_a_tenant_listed_twice_is_refused(): void
    {
        $this->expectException(PulseConfigurationException::class);
        $this->expectExceptionMessage('lists a tenant twice, so Pulse would ask for it twice. List each tenant once.');

        PulseSettings::fromEnvironment([...self::BASE, 'PULSE_SERVICES' => 'orders{tenants=a,a}'], __DIR__);
    }

    public function test_a_brace_anywhere_but_the_end_of_an_entry_is_refused(): void
    {
        $this->expectException(PulseConfigurationException::class);
        $this->expectExceptionMessage('a "{" can only open the tenant list at the end of an entry');

        PulseSettings::fromEnvironment([...self::BASE, 'PULSE_SERVICES' => 'orders{tenants=a}:channel'], __DIR__);
    }
}
