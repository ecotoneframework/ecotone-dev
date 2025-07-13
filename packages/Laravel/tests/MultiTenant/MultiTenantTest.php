<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\MultiTenant;

use App\MultiTenant\Application\Command\RegisterCustomer;
use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Laravel\EcotoneProvider;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require __DIR__ . '/boostrap.php';

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class MultiTenantTest extends TestCase
{
    private Application $app;
    private QueryBus $queryBus;
    private CommandBus $commandBus;

    public function setUp(): void
    {
        $app = require __DIR__ . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        runMigrationForTenants(DB::connection('tenant_a_connection'), DB::connection('tenant_b_connection'));
        $this->app = $app;
        EcotoneCacheClear::clearEcotoneCacheDirectories(EcotoneProvider::getCacheDirectoryPath());
        $this->queryBus = $app->get(QueryBus::class);
        $this->commandBus = $app->get(CommandBus::class);
    }

    public function test_optimize_clear_triggers_ecotone_cache_clear_via_event(): void
    {
        $laravelCacheDirectory = storage_path('framework/cache/data/ecotone');
        $liteCacheDirectory = storage_path('framework/cache/data/ecotone');

        // Create cache directories and test files
        if (! File::exists($laravelCacheDirectory)) {
            File::makeDirectory($laravelCacheDirectory, 0755, true);
        }
        File::put($laravelCacheDirectory . '/test_cache_file', 'test content');

        if (! File::exists($liteCacheDirectory)) {
            File::makeDirectory($liteCacheDirectory, 0755, true);
        }
        File::put($liteCacheDirectory . '/test_lite_cache_file', 'lite test content');

        $this->assertTrue(File::exists($laravelCacheDirectory . '/test_cache_file'));
        $this->assertTrue(File::exists($liteCacheDirectory . '/test_lite_cache_file'));

        // Run optimize:clear command - this should trigger the CommandFinished event
        Artisan::call('optimize:clear');

        // Verify both Ecotone cache files were removed via the event listener
        $this->assertFalse(File::exists($laravelCacheDirectory . '/test_cache_file'));
        $this->assertFalse(File::exists($liteCacheDirectory . '/test_lite_cache_file'));
    }

    public function tearDown(): void
    {
        DB::connection('tenant_a_connection')->disconnect();
        DB::connection('tenant_b_connection')->disconnect();
    }

    public function test_run_message_handlers_for_multi_tenant_connection(): void
    {
        $this->commandBus->send(new RegisterCustomer(1, 'John Doe'), metadata: ['tenant' => 'tenant_a']);
        $this->commandBus->send(new RegisterCustomer(2, 'John Doe'), metadata: ['tenant' => 'tenant_a']);
        $this->commandBus->send(new RegisterCustomer(2, 'John Doe'), metadata: ['tenant' => 'tenant_b']);

        $this->assertSame(
            [1, 2],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
        );

        $this->assertSame(
            [2],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_b'])
        );
    }

    public function test_using_dbal_based_business_interfaces(): void
    {
        $this->commandBus->sendWithRouting(
            'customer.register_with_business_interface',
            new RegisterCustomer(1, 'John Doe'),
            metadata: ['tenant' => 'tenant_a']
        );

        $this->assertSame(
            [1],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
        );

        $this->assertSame(
            [],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_b'])
        );
    }

    public function test_sending_events_using_laravel_db_queue(): void
    {
        $this->commandBus->sendWithRouting(
            'customer.register_with_event',
            new RegisterCustomer(1, 'John Doe'),
            metadata: ['tenant' => 'tenant_a']
        );
        $this->commandBus->sendWithRouting(
            'customer.register_with_event',
            new RegisterCustomer(2, 'John Doe'),
            metadata: ['tenant' => 'tenant_a']
        );

        /** Consume Messages for Tenant A */
        Artisan::call('ecotone:run', ['consumerName' => 'notifications', '--stopOnFailure' => true, '--executionTimeLimit' => 1000]);

        /** This is not yet consumed */
        $this->commandBus->sendWithRouting(
            'customer.register_with_event',
            new RegisterCustomer(2, 'John Doe'),
            metadata: ['tenant' => 'tenant_b']
        );

        $this->assertSame(
            2,
            $this->queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_a'])
        );

        $this->assertSame(
            0,
            $this->queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_b'])
        );

        /** Consume Messages for Tenant B */
        Artisan::call('ecotone:run', ['consumerName' => 'notifications', '--stopOnFailure' => true, '--executionTimeLimit' => 1000]);

        $this->assertSame(
            2,
            $this->queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_a'])
        );

        $this->assertSame(
            1,
            $this->queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_b'])
        );
    }

    public function test_transactions_rollbacks_model_changes_and_published_events(): void
    {
        /** This one will be rolled back */
        try {
            $this->commandBus->sendWithRouting(
                'customer.register_with_event',
                new RegisterCustomer(1, 'John Doe'),
                metadata: ['tenant' => 'tenant_a', 'shouldThrowException' => true]
            );
        } catch (RuntimeException $exception) {
        }

        $this->commandBus->sendWithRouting(
            'customer.register_with_event',
            new RegisterCustomer(2, 'John Doe'),
            metadata: ['tenant' => 'tenant_a']
        );

        /** Consume Messages for Tenant A */
        Artisan::call('ecotone:run', ['consumerName' => 'notifications', '--stopOnFailure' => true, '--executionTimeLimit' => 1000]);

        $this->assertSame(
            1,
            $this->queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_a'])
        );
        $this->assertSame(
            [2],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
        );
    }
}
