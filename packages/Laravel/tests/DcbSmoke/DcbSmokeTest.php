<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\DcbSmoke;

use App\DcbSmoke\Laravel\Application\IssueCoupon;
use App\DcbSmoke\Laravel\Application\RedeemCoupon;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Laravel\EcotoneProvider;
use Ecotone\Test\LicenceTesting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class DcbSmokeTest extends TestCase
{
    private Application $app;

    public function setUp(): void
    {
        putenv('LARAVEL_DCB_SMOKE_LICENCE_KEY=' . LicenceTesting::VALID_LICENCE);

        $app = require __DIR__ . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        EcotoneCacheClear::clearEcotoneCacheDirectories(EcotoneProvider::getCacheDirectoryPath());

        $this->app = $app;

        $connection = $app->make(DbalConnectionFactory::class)->createContext()->getDbalConnection();
        foreach (['ecotone_tagged_events', 'ecotone_tag_versions', 'ecotone_event_stream'] as $tableName) {
            if ($connection->createSchemaManager()->tablesExist([$tableName])) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }

        $app->make(ConsoleKernel::class)->call('ecotone:migration:database:setup', ['--initialize' => true]);
    }

    protected function tearDown(): void
    {
        putenv('LARAVEL_DCB_SMOKE_LICENCE_KEY');
        restore_exception_handler();
    }

    public function test_a_tagged_event_is_appended_and_a_decision_model_is_injected_into_a_handler(): void
    {
        /** @var CommandBus $commandBus */
        $commandBus = $this->app->make(CommandBus::class);

        $commandBus->send(new IssueCoupon('SUMMER24', 1));
        $commandBus->send(new RedeemCoupon('SUMMER24'));

        $this->expectExceptionMessage('Coupon SUMMER24 is exhausted');
        $commandBus->send(new RedeemCoupon('SUMMER24'));
    }
}
