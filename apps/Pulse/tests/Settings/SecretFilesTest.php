<?php

declare(strict_types=1);

namespace App\Tests\Settings;

use App\Settings\PulseConfigurationException;
use App\Settings\PulseEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SecretFilesTest extends TestCase
{
    private const VARIABLES = ['ECOTONE_LICENCE_KEY', 'ECOTONE_LICENCE_KEY_FILE', 'PULSE_TRANSPORT_DSN', 'PULSE_TRANSPORT_DSN_FILE', 'PULSE_CONNECTION_LEGACY_DSN', 'PULSE_CONNECTION_LEGACY_DSN_FILE', 'APP_SECRET', 'APP_SECRET_FILE'];

    protected function setUp(): void
    {
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
    }

    public function test_a_variable_is_read_from_the_file_named_by_its_file_variable(): void
    {
        $this->set('ECOTONE_LICENCE_KEY_FILE', $this->secret("licence-from-file\n"));
        $this->set('PULSE_TRANSPORT_DSN_FILE', $this->secret('amqp://guest:guest@rabbitmq:5672'));
        $this->set('PULSE_CONNECTION_LEGACY_DSN_FILE', $this->secret('pgsql://u:p@db:5432/legacy'));

        $environment = PulseEnvironment::read();

        self::assertSame('licence-from-file', $environment['ECOTONE_LICENCE_KEY']);
        self::assertSame('amqp://guest:guest@rabbitmq:5672', $environment['PULSE_TRANSPORT_DSN']);
        self::assertSame('pgsql://u:p@db:5432/legacy', $environment['PULSE_CONNECTION_LEGACY_DSN']);
        self::assertSame('licence-from-file', getenv('ECOTONE_LICENCE_KEY'));
    }

    public function test_the_application_secret_can_come_from_a_file_too(): void
    {
        $this->set('APP_SECRET_FILE', $this->secret('s3cret'));

        PulseEnvironment::read();

        self::assertSame('s3cret', getenv('APP_SECRET'));
    }

    public function test_a_value_in_the_variable_itself_wins_over_the_file(): void
    {
        $this->set('ECOTONE_LICENCE_KEY', 'direct');
        $this->set('ECOTONE_LICENCE_KEY_FILE', $this->secret('from-file'));

        self::assertSame('direct', PulseEnvironment::read()['ECOTONE_LICENCE_KEY']);
    }

    public function test_a_file_that_cannot_be_read_is_refused_naming_the_variable(): void
    {
        $this->set('PULSE_TRANSPORT_DSN_FILE', '/run/secrets/missing_dsn');

        $this->expectException(PulseConfigurationException::class);
        $this->expectExceptionMessage('PULSE_TRANSPORT_DSN_FILE points to /run/secrets/missing_dsn, which cannot be read. Mount the secret at that path, or unset PULSE_TRANSPORT_DSN_FILE and set PULSE_TRANSPORT_DSN instead.');

        PulseEnvironment::read();
    }

    private function secret(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pulse-secret');
        file_put_contents($path, $content);

        return $path;
    }

    private function set(string $name, string $value): void
    {
        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv($name . '=' . $value);
    }

    private function clear(): void
    {
        foreach (self::VARIABLES as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
    }
}
