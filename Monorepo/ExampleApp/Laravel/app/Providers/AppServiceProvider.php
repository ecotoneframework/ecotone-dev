<?php

namespace Monorepo\ExampleApp\Laravel\app\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Monorepo\ExampleApp\Common\Domain\Clock;
use Monorepo\ExampleApp\Common\Domain\Notification\NotificationSender;
use Monorepo\ExampleApp\Common\Domain\Notification\NotificationSubscriber;
use Monorepo\ExampleApp\Common\Domain\Product\ProductRepository;
use Monorepo\ExampleApp\Common\Domain\Shipping\ShippingService;
use Monorepo\ExampleApp\Common\Domain\Shipping\ShippingSubscriber;
use Monorepo\ExampleApp\Common\Domain\User\UserRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Authentication\AuthenticationService;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\Infrastructure\Converter\UuidConverter;
use Monorepo\ExampleApp\Common\Infrastructure\ErrorChannelService;
use Monorepo\ExampleApp\Common\Infrastructure\InMemory\InMemoryOrderRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Output;
use Monorepo\ExampleApp\Common\Infrastructure\StubNotificationSender;
use Monorepo\ExampleApp\Common\Infrastructure\StubShippingService;
use Monorepo\ExampleApp\Common\Infrastructure\SystemClock;
use Monorepo\ExampleApp\Common\UI\OrderController;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Psr\Log\LoggerInterface;
use Test\Ecotone\OpenTelemetry\Integration\TracingTestCase;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Configuration::class, fn () => new Configuration());
        $this->app->singleton(Output::class, fn () => new Output());

        $this->app->singleton(AuthenticationService::class, function (Application $app) {
            $configuration = $app->make(Configuration::class);
            return $configuration->authentication();
        });

        $this->app->alias(LoggerInterface::class, 'logger');
        $this->app->singleton('files', fn () => new Filesystem());

        $this->app->singleton(OrderController::class);
        $this->app->singleton(UuidConverter::class);
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(InMemoryOrderRepository::class);
        $this->app->singleton(NotificationSubscriber::class);
        $this->app->singleton(NotificationSender::class, StubNotificationSender::class);
        $this->app->singleton(ShippingSubscriber::class);
        $this->app->singleton(ShippingService::class, StubShippingService::class);
        $this->app->singleton(ErrorChannelService::class, ErrorChannelService::class);

        $this->app->singleton(UserRepository::class, function (Application $app) {
            $configuration = $app->make(Configuration::class);
            return $configuration->userRepository();
        });

        $this->app->singleton(ProductRepository::class, function (Application $app) {
            $configuration = $app->make(Configuration::class);
            return $configuration->productRepository();
        });

        $this->app->singleton(InMemoryExporter::class, fn() => new InMemoryExporter());
        $this->app->singleton(TracerProviderInterface::class, function (Application $app) {
            $exporter = $app->make(InMemoryExporter::class);
            return TracingTestCase::prepareTracer($exporter);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
    }
}
