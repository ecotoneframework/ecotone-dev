<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class FlowGraphParametersTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    #[DataProvider('malformed_parameters')]
    public function test_a_parameter_in_the_wrong_shape_is_refused_naming_the_shape_and_the_way_out(string $query, string $message): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $client->request('GET', '/flow?' . $query);

        self::assertResponseStatusCodeSame(400);
        self::assertSame($message . ' (400 Bad Request)', $client->getCrawler()->filter('title')->text());
    }

    #[DataProvider('settings_following_overrides')]
    public function test_following_a_node_together_with_a_setting_it_would_override_is_refused(string $query, string $message): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $client->request('GET', '/flow?focus=orders:message:order.place&' . $query);

        self::assertResponseStatusCodeSame(400);
        self::assertSame($message . ' (400 Bad Request)', $client->getCrawler()->filter('title')->text());
    }

    public static function settings_following_overrides(): iterable
    {
        yield 'chosen services' => ['services[]=billing', 'Cannot follow "orders:message:order.place" and show services[] at once: following draws the path of the node across whichever services it crosses. Remove services[] to follow the node, or remove focus to choose the services.'];
        yield 'a view' => ['view=detail', 'Cannot follow "orders:message:order.place" and show view=detail at once: following draws the path of the node across whichever services it crosses. Remove view to follow the node, or remove focus to choose the view.'];
    }

    public static function malformed_parameters(): iterable
    {
        yield 'a list given one value' => ['collapse=billing', 'Cannot read collapse=billing: collapse takes a list, one collapse[]={service} per service. Write collapse[]=billing, or remove collapse to draw every service in detail.'];
        yield 'a list holding a list' => ['services[a][]=billing', 'Cannot read services[]: each entry is one value, services[]={service}. Write services[]=billing once per service, or remove services to show every service.'];
        yield 'a list holding an empty value' => ['collapse[]=', 'Cannot read collapse[]: one of its values is empty. Name a service in each collapse[]={service}, or remove the empty collapse[] to draw every service in detail.'];
        yield 'one value given a list' => ['fold[]=channels', 'Cannot read fold[]: fold takes one value, fold=channels. Send it once as fold=channels, or remove fold to draw every channel.'];
        yield 'a focus given a list' => ['focus[]=a', 'Cannot read focus[]: focus takes one value, focus={service}:{node id}. Send it once as focus={service}:{node id}, or remove focus to see every service.'];
        yield 'a focus without a node' => ['focus=billing', 'Cannot follow "billing": focus takes {service}:{node id}, the service and the node joined by a colon. Click the node on the flow graph, type its name into Find, or remove focus to see every service.'];
        yield 'a focus without a service' => ['focus=:command:x', 'Cannot follow ":command:x": focus takes {service}:{node id}, the service and the node joined by a colon. Click the node on the flow graph, type its name into Find, or remove focus to see every service.'];
        yield 'a find given a list' => ['find[]=order', 'Cannot read find[]: find takes one value, find={node name}. Send it once as find={node name}, or remove find to stop searching.'];
        yield 'a trace given a list' => ['trace[]=abc', 'Cannot read trace[]: trace takes one value, trace={trace id}. Send it once as trace={trace id}, or remove trace to stop highlighting a trace.'];
        yield 'a scope given a list' => ['service[]=orders', 'Cannot read service[]: service takes one value, service={service}. Send it once as service={service}, or remove service to see all services.'];
        yield 'a view given a list' => ['view[]=detail', 'Cannot read view[]: view takes one value, view=overview or view=detail. Send it once as view=overview or view=detail, or remove view to let the size of the system decide.'];
    }
}
