<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;

/**
 * @internal
 */
final class ConceptHintsTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    public function test_every_page_offers_the_explain_concepts_toggle_and_applies_the_stored_choice_before_it_paints(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        foreach (['/', '/services', '/dead-letters', '/flow', '/topology', '/traces', '/connect', '/catalogue'] as $path) {
            $crawler = $client->request('GET', $path);

            $toggle = $crawler->filter('header.topbar button[data-concepts-toggle]');
            self::assertSame('Explain concepts', $toggle->text(), $path);
            self::assertSame('true', $toggle->attr('aria-pressed'), $path);
            $head = implode("\n", $crawler->filter('head script')->each(static fn ($script): string => $script->text('', false)));
            self::assertStringContainsString("localStorage.getItem('pulse-concepts')", $head, $path);
            self::assertStringContainsString("documentElement.dataset.concepts='off'", $head, $path);
        }
    }
}
