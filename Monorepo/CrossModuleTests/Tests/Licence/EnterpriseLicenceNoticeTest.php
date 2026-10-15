<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\Licence;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class EnterpriseLicenceNoticeTest extends TestCase
{
    private string $monorepoDirectory;

    protected function setUp(): void
    {
        $this->monorepoDirectory = sys_get_temp_dir() . '/enterprise-licence-notice-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->monorepoDirectory);
    }

    public function test_src_file_carries_the_full_notice_and_api_file_the_one_sentence(): void
    {
        $this->givenFile('packages/Shop/src/Checkout.php', $this->classWithHeader(self::fullNotice()));
        $this->givenFile('packages/Shop/Api/CheckoutConfiguration.php', $this->classWithHeader(self::apiNotice()));

        self::assertSame([], $this->violations());
    }

    public function test_src_file_without_the_full_notice_is_refused(): void
    {
        $this->givenFile('packages/Shop/src/Checkout.php', $this->classWithHeader(self::apiNotice()));

        $violations = $this->violations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('packages/Shop/src/Checkout.php', $violations[0]);
        self::assertStringContainsString('the full Enterprise notice', $violations[0]);
        self::assertStringContainsString('php bin/add-enterprise-licence.php', $violations[0]);
        self::assertStringContainsString('bin/enterprise-licence-notice.php', $violations[0]);
    }

    public function test_api_file_with_the_bare_marker_is_refused(): void
    {
        $this->givenFile('packages/Shop/Api/CheckoutConfiguration.php', $this->classWithHeader("/**\n * licence Enterprise\n */\n"));

        $violations = $this->violations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('packages/Shop/Api/CheckoutConfiguration.php', $violations[0]);
        self::assertStringContainsString('the one-sentence Enterprise notice', $violations[0]);
        self::assertStringContainsString('php bin/add-enterprise-licence.php', $violations[0]);
        self::assertStringContainsString('bin/enterprise-licence-api-notice.php', $violations[0]);
    }

    public function test_api_file_with_the_full_notice_is_refused(): void
    {
        $this->givenFile('packages/Shop/Api/CheckoutConfiguration.php', $this->classWithHeader(self::fullNotice()));

        $violations = $this->violations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('the one-sentence Enterprise notice', $violations[0]);
    }

    public function test_api_directory_inside_src_keeps_the_full_notice(): void
    {
        $this->givenFile('packages/Shop/src/Modelling/Api/Checkout.php', $this->classWithHeader(self::apiNotice()));

        $violations = $this->violations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('the full Enterprise notice', $violations[0]);
    }

    public function test_apache_licensed_files_are_not_checked(): void
    {
        $this->givenFile('packages/Shop/Api/Checkout.php', $this->classWithHeader("/**\n * licence Apache-2.0\n */\n"));

        self::assertSame([], $this->violations());
    }

    public function test_completing_the_notices_rewrites_each_file_to_the_form_its_directory_requires(): void
    {
        $this->givenFile('packages/Shop/src/Checkout.php', $this->classWithHeader(self::apiNotice()));
        $this->givenFile('packages/Shop/src/Payment.php', $this->classWithHeader("/**\n * licence Enterprise\n */\n"));
        $this->givenFile('packages/Shop/Api/CheckoutConfiguration.php', $this->classWithHeader(self::fullNotice()));
        $this->givenFile('packages/Shop/Api/PaymentConfiguration.php', $this->classWithHeader("/**\n * licence Enterprise\n */\n"));

        (require dirname(__DIR__, 4) . '/bin/enterprise-licence-notice-completion.php')($this->monorepoDirectory);

        self::assertSame([], $this->violations());
        self::assertSame($this->classWithHeader(self::fullNotice()), $this->fileContent('packages/Shop/src/Checkout.php'));
        self::assertSame($this->classWithHeader(self::fullNotice()), $this->fileContent('packages/Shop/src/Payment.php'));
        self::assertSame($this->classWithHeader(self::apiNotice()), $this->fileContent('packages/Shop/Api/CheckoutConfiguration.php'));
        self::assertSame($this->classWithHeader(self::apiNotice()), $this->fileContent('packages/Shop/Api/PaymentConfiguration.php'));
    }

    private static function fullNotice(): string
    {
        return require dirname(__DIR__, 4) . '/bin/enterprise-licence-notice.php';
    }

    private static function apiNotice(): string
    {
        return <<<NOTICE
            /**
             * licence Enterprise
             *
             * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
             */

            NOTICE;
    }

    private function violations(): array
    {
        return (require dirname(__DIR__, 4) . '/bin/enterprise-licence-notice-violations.php')($this->monorepoDirectory);
    }

    private function classWithHeader(string $header): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Shop;\n\n" . $header . "final class Checkout\n{\n}\n";
    }

    private function givenFile(string $relativePath, string $content): void
    {
        (new Filesystem())->dumpFile($this->monorepoDirectory . '/' . $relativePath, $content);
    }

    private function fileContent(string $relativePath): string
    {
        return file_get_contents($this->monorepoDirectory . '/' . $relativePath);
    }
}
