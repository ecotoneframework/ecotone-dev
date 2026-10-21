<?php

declare(strict_types=1);

namespace App\Tests\Connect;

use App\Tests\Support\OpenedConnections;
use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class ConnectServicePageTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    public static function frameworks(): iterable
    {
        yield 'lite' => ['lite'];
        yield 'symfony' => ['symfony'];
        yield 'laravel' => ['laravel'];
    }

    public function test_the_connect_page_names_this_pulse_and_the_service(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/connect?service=orders');

        self::assertResponseIsSuccessful();
        $snippet = $crawler->filter('[data-snippet="lite"] [data-php]')->text(null, false);
        self::assertStringContainsString("withServiceName('orders')", $snippet);
        self::assertStringContainsString("pulseServiceName: 'ecotone_pulse'", $snippet);
        self::assertStringContainsString("DbalBackedMessageChannelBuilder::create('" . $this->serviceChannels['orders'] . "')", $snippet);
        self::assertStringContainsString("DbalBackedMessageChannelBuilder::create('" . $this->pulseChannel . "')", $snippet);
        self::assertStringContainsString('->withRequestChannel(', $snippet);
        self::assertStringContainsString('orders:' . $this->serviceChannels['orders'], $crawler->filter('[data-pulse-services]')->text());
    }

    public function test_each_framework_tab_says_how_the_licence_and_consumer_are_set(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/connect?service=orders');

        $channel = $this->serviceChannels['orders'];
        self::assertStringContainsString("licenceKey: getenv('ECOTONE_LICENCE_KEY')", $crawler->filter('[data-snippet="lite"] [data-bootstrap]')->text(null, false));
        self::assertStringContainsString("\$ecotone->run('" . $channel . "')", $crawler->filter('[data-snippet="lite"] [data-consumer]')->text());
        self::assertStringContainsString('%env(ECOTONE_LICENCE_KEY)%', $crawler->filter('[data-snippet="symfony"] [data-wiring]')->text());
        self::assertSame('bin/console ecotone:run ' . $channel, $crawler->filter('[data-snippet="symfony"] [data-consumer]')->text());
        self::assertStringContainsString("env('ECOTONE_LICENCE_KEY')", $crawler->filter('[data-snippet="laravel"] [data-wiring]')->text());
        self::assertSame('php artisan ecotone:run ' . $channel, $crawler->filter('[data-snippet="laravel"] [data-consumer]')->text());
    }

    #[DataProvider('frameworks')]
    public function test_each_framework_snippet_is_valid_php_in_the_place_that_framework_discovers_it(string $framework): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/connect?service=orders');

        $php = $crawler->filter('[data-snippet="' . $framework . '"] [data-php]')->text(null, false);
        self::assertNotEmpty(token_get_all($php, TOKEN_PARSE));
        self::assertSame($framework !== 'lite', str_contains($php, "namespace App;\n"));
        self::assertStringContainsString(['lite' => 'any autoloaded file', 'symfony' => 'src/PulseSetup.php', 'laravel' => 'app/PulseSetup.php'][$framework], $crawler->filter('[data-snippet="' . $framework . '"] [data-location]')->text());
    }

    #[DataProvider('frameworks')]
    public function test_a_service_booted_from_the_generated_snippet_reaches_connected(string $framework): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        $snippet = $client->request('GET', '/connect?service=orders')->filter('[data-snippet="' . $framework . '"]');
        $class = 'GeneratedPulseSetup' . $framework . uniqid();
        $setup = str_replace('final class PulseSetup', 'final class ' . $class, $snippet->filter('[data-php]')->text(null, false));
        $file = sys_get_temp_dir() . '/' . $class . '.php';
        file_put_contents($file, $setup);
        require_once $file;
        $qualified = ($framework === 'lite' ? '' : 'App\\') . $class;

        if ($framework === 'lite') {
            putenv('DATABASE_DSN=' . $_SERVER['PULSE_TRANSPORT_DSN']);
            $bootstrap = str_replace(['PulseSetup::class', '<?php'], [$qualified . '::class', ''], $snippet->filter('[data-bootstrap]')->text(null, false));
            $orders = eval($bootstrap . "\nreturn \$ecotone;");
        } else {
            $orders = EcotoneLite::bootstrapFlowTesting(
                classesToResolve: [$qualified],
                containerOrAvailableServices: [DbalConnectionFactory::class => OpenedConnections::factory($_SERVER['PULSE_TRANSPORT_DSN'])],
                enabledModules: [ModulePackageList::DBAL_PACKAGE],
                licenceKey: LicenceTesting::VALID_LICENCE,
            );
        }
        $client->request('GET', '/');
        $orders->run($this->serviceChannels['orders'], ExecutionPollingMetadata::createWithTestingSetup());
        $this->runPulseWorker($client->getKernel());

        self::assertSame('connected', $this->statusOf($client->request('GET', '/'), 'orders'));
        unlink($file);
    }

    public static function transports(): iterable
    {
        yield 'amqp' => ['amqp://guest:guest@rabbitmq:5672'];
        yield 'redis' => ['redis://redis:6379'];
        yield 'sqs' => ['sqs:?region=eu-west-1'];
        yield 'kafka' => ['kafka://kafka:9092'];
        yield 'dbal' => ['pgsql://ecotone:secret@database:5432/ecotone'];
    }

    #[DataProvider('transports')]
    public function test_every_class_a_snippet_imports_exists_for_every_transport(string $dsn): void
    {
        $this->configurePulseWithServices('orders');
        $this->configurePulse(['PULSE_TRANSPORT_DSN' => $dsn, 'PULSE_SERVICES' => 'orders']);
        $client = static::createClient();
        $this->setUpPulseInternalStorage($client->getKernel());

        $crawler = $client->request('GET', '/connect?service=orders');

        self::assertResponseIsSuccessful();
        $code = $crawler->filter('[data-snippet="lite"] [data-php], [data-snippet="lite"] [data-bootstrap]')->each(static fn (Crawler $block) => $block->text(null, false));
        preg_match_all('/^use ([\\\\\w]+);$/m', implode("\n", $code), $imports);
        self::assertGreaterThan(3, count($imports[1]));
        foreach ($imports[1] as $class) {
            self::assertTrue(class_exists($class) || interface_exists($class), $class);
        }
    }

    public function test_the_symfony_licence_key_setting_exists_in_the_bundle_configuration(): void
    {
        $processed = (new \Symfony\Component\Config\Definition\Processor())->processConfiguration(new \Ecotone\SymfonyBundle\DependencyInjection\Configuration(), [['licenceKey' => 'k']]);

        self::assertSame('k', $processed['licenceKey']);
    }

    public function test_a_service_name_that_would_end_up_in_generated_code_is_refused(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $client->request('GET', '/connect?service=' . urlencode("x');system('id');//"));

        self::assertResponseStatusCodeSame(400);
        self::assertCount(0, $client->getCrawler()->filter('[data-php]'));
        self::assertStringContainsString('[A-Za-z0-9_.-]', html_entity_decode($client->getCrawler()->filter('[data-invalid-service]')->text()));
    }

    public function test_a_service_name_with_a_comma_is_refused_because_it_would_corrupt_the_services_entry(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $client->request('GET', '/connect?service=' . urlencode('a,b'));

        self::assertResponseStatusCodeSame(400);
    }

    public function test_the_connect_page_without_a_service_uses_a_placeholder_name(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/connect');

        self::assertStringContainsString("withServiceName('my_service')", $crawler->filter('[data-snippet="lite"] [data-php]')->text(null, false));
        self::assertStringContainsString('my_service', $crawler->filter('[data-pulse-services]')->text());
    }

    private function pulseClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        return $client;
    }

    private function statusOf(Crawler $crawler, string $service): string
    {
        return $crawler->filter('tr[data-service="' . $service . '"] [data-status]')->attr('data-status');
    }
}
