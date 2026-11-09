<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Home\Severity;
use App\Tests\Support\PulseEnvironmentTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Error\RuntimeError;

/**
 * @internal
 */
final class PagePatternsTest extends KernelTestCase
{
    use PulseEnvironmentTrait;

    public function test_a_verdict_carries_one_status_one_sentence_the_facts_and_one_action(): void
    {
        $page = $this->rendered("{% include '_verdict.html.twig' with {status: 'critical', sentence: 'storefront: 1 message failed and is waiting in dead letters.', meta: ['Connected', 'last answer 1 s ago'], action: {label: 'Open the failure', href: '/dead-letters?service=storefront'}} %}");

        $verdict = $page->filter('[data-verdict]');
        self::assertCount(1, $verdict);
        self::assertSame('critical', $verdict->attr('data-verdict'));
        self::assertSame('Critical', $verdict->filter('[data-status-pill]')->text());
        self::assertSame('Critical: needs action now', $verdict->filter('[data-status-pill]')->attr('title'));
        self::assertSame('storefront: 1 message failed and is waiting in dead letters.', $verdict->filter('[data-verdict-sentence]')->text());
        self::assertSame('Connected · last answer 1 s ago', $verdict->filter('[data-verdict-meta]')->text());
        self::assertSame('/dead-letters?service=storefront', $verdict->filter('a.pri[data-verdict-action]')->attr('href'));
    }

    public function test_a_verdict_without_problems_has_no_action(): void
    {
        $page = $this->rendered("{% include '_verdict.html.twig' with {status: 'ok', sentence: 'All clear: 2 channels draining, no failed messages.'} %}");

        self::assertSame('ok', $page->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('OK', $page->filter('[data-status-pill]')->text());
        self::assertCount(0, $page->filter('[data-verdict-action]'));
        self::assertCount(0, $page->filter('[data-verdict-meta]'));
    }

    public function test_a_problem_card_says_what_happened_where_and_offers_exactly_one_primary_action(): void
    {
        $page = $this->rendered("{% include '_problem.html.twig' with {status: 'critical', kind: 'dead-letters', text: '1 ChargeCard message failed after all retries and is kept in dead letters.', where: ['storefront', 'storefront_async', {label: 'Checkout::charge', title: 'App\\\\Payment\\\\Checkout::charge'}], evidence: 'PaymentProviderUnavailable at PaymentProvider.php:24', action: {label: 'Open the failure', href: '/dead-letters/storefront/1'}, secondary: {label: 'See the trace', href: '/traces/trace/5d75'}, concept: 'dead-letter'} %}");

        $problem = $page->filter('[data-problem]');
        self::assertSame('dead-letters', $problem->attr('data-problem'));
        self::assertSame('critical', $problem->attr('data-problem-severity'));
        self::assertSame('1 ChargeCard message failed after all retries and is kept in dead letters.', $problem->filter('[data-problem-text]')->text());
        self::assertSame(['storefront', 'storefront_async', 'Checkout::charge'], $problem->filter('[data-problem-where] li')->each(static fn (Crawler $place): string => $place->text()));
        self::assertSame('App\Payment\Checkout::charge', $problem->filter('[data-problem-where] li code')->last()->attr('title'));
        self::assertSame('PaymentProviderUnavailable at PaymentProvider.php:24', $problem->filter('[data-problem-evidence]')->text());
        self::assertCount(1, $problem->filter('.pri'));
        self::assertSame('Open the failure', $problem->filter('.pri')->text());
        self::assertSame('/traces/trace/5d75', $problem->filter('[data-problem-secondary]')->attr('href'));
        self::assertCount(1, $problem->filter('[data-concept="dead-letter"]'));
    }

    public function test_a_problem_card_can_say_what_to_change(): void
    {
        $with = $this->rendered("{% include '_problem.html.twig' with {status: 'warning', text: 'orders publishes order.placed, but no service subscribes to it.', fix: 'Add a withEventMapping() entry whose keys match order.placed.', action: {label: 'Find order.placed', href: '/flow?find=order.placed'}} %}");
        $without = $this->rendered("{% include '_problem.html.twig' with {status: 'warning', text: 'x', action: {label: 'y', href: '/'}} %}");

        self::assertSame('What to change: Add a withEventMapping() entry whose keys match order.placed.', $with->filter('[data-problem-fix]')->text());
        self::assertCount(0, $without->filter('[data-problem-fix]'));
    }

    public function test_a_problem_card_accepts_the_severity_of_a_home_problem(): void
    {
        $page = $this->rendered("{% include '_problem.html.twig' with {status: severity, kind: 'unreachable', text: 'storefront did not answer within 5 s', action: {label: 'Diagnose', href: '/services/storefront'}} %}", ['severity' => Severity::Warning]);

        self::assertSame('warning', $page->filter('[data-problem]')->attr('data-problem-severity'));
        self::assertSame('Warning', $page->filter('[data-status-pill]')->text());
        self::assertCount(0, $page->filter('[data-problem-where]'));
    }

    public function test_a_summary_tile_is_one_link_to_the_filtered_list_with_number_noun_and_state(): void
    {
        $page = $this->rendered("{% include '_tile.html.twig' with {kind: 'dead-letters', href: '/dead-letters?service=storefront', label: 'Dead letters', count: 1, noun: 'failed message', state: '1 failure group', status: 'critical', concept: 'dead-letter'} %}");

        $tile = $page->filter('[data-tile-kind="dead-letters"]');
        self::assertSame('critical', $tile->attr('data-tile-status'));
        self::assertSame('/dead-letters?service=storefront', $tile->filter('a.tile-link')->attr('href'));
        self::assertSame('1', $tile->filter('a.tile-link b')->text());
        self::assertSame('failed message', $tile->filter('a.tile-link .tile-noun')->text());
        self::assertSame('1 failure group', $tile->filter('[data-tile-state]')->text());
        self::assertCount(0, $tile->filter('a [data-concept]'));
        self::assertCount(1, $tile->filter('[data-concept="dead-letter"]'));
    }

    public function test_a_section_summary_carries_a_count_and_a_state_and_opens_only_when_something_needs_attention(): void
    {
        $quiet = $this->rendered("{% embed '_section.html.twig' with {key: 'hub.orders.channels', title: 'Channels', summary: '2 · all draining', status: 'ok'} %}{% block body %}<p data-body>rows</p>{% endblock %}{% endembed %}");
        $loud = $this->rendered("{% embed '_section.html.twig' with {key: 'hub.orders.channels', title: 'Channels', summary: '2 · 1 not draining', status: 'warning', concept: 'channel'} %}{% block body %}<p data-body>rows</p>{% endblock %}{% endembed %}");

        $section = $quiet->filter('details[data-disclosure="hub.orders.channels"]');
        self::assertNull($section->attr('open'));
        self::assertSame('Channels', $section->filter('summary h2')->text());
        self::assertSame('2 · all draining', $section->filter('summary [data-summary-state]')->text());
        self::assertCount(0, $section->filter('summary [data-status-pill]'));
        self::assertSame('rows', $section->filter('[data-body]')->text());
        $open = $loud->filter('details[data-disclosure="hub.orders.channels"]');
        self::assertNotNull($open->attr('open'));
        self::assertSame('Warning', $open->filter('summary [data-status-pill]')->text());
        self::assertCount(0, $open->filter('summary [data-concept]'));
        self::assertCount(1, $open->filter('[data-concept="channel"]'));
    }

    public function test_a_section_can_be_opened_or_closed_explicitly_by_the_page(): void
    {
        $page = $this->rendered("{% embed '_section.html.twig' with {key: 'k', title: 'Consumers', summary: '5 · all running', status: 'warning', open: false, level: 3} %}{% block body %}x{% endblock %}{% endembed %}");

        self::assertNull($page->filter('details[data-disclosure="k"]')->attr('open'));
        self::assertCount(1, $page->filter('summary h3'));
    }

    public function test_an_unknown_status_shows_its_reason(): void
    {
        $page = $this->rendered("{% include '_status.html.twig' with {status: 'unknown', reason: 'the broker did not report the depth'} %}");

        self::assertSame('Unknown', $page->filter('[data-status-pill="unknown"]')->text());
        self::assertSame('the broker did not report the depth', $page->filter('[data-status-reason]')->text());
    }

    public function test_an_unknown_status_without_a_reason_is_refused(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('reason');

        $this->rendered("{% include '_tile.html.twig' with {kind: 'channels', href: '/', label: 'Channels', count: 2, noun: 'channels', status: 'unknown'} %}");
    }

    public function test_one_severity_vocabulary_names_every_status_and_its_meaning(): void
    {
        $titles = [];
        foreach (['critical', 'warning', 'info', 'ok'] as $status) {
            $pill = $this->rendered("{% include '_status.html.twig' with {status: status} %}", ['status' => $status])->filter('[data-status-pill]');
            $titles[$pill->attr('data-status-pill')] = $pill->attr('title');
        }

        self::assertSame([
            'critical' => 'Critical: needs action now',
            'warning' => 'Warning: watch or plan',
            'info' => 'Info: running',
            'ok' => 'OK: nothing to do',
        ], $titles);
    }

    public function test_the_raw_data_block_pretty_prints_json_collapses_long_content_and_offers_a_copy_per_item(): void
    {
        $stack = implode("\n", array_map(static fn (int $frame): string => sprintf('#%d vendor/ecotone/Frame.php(%d)', $frame, $frame), range(0, 24)));
        $page = $this->rendered("{% include '_raw.html.twig' with {key: 'dead-letter', items: [{name: 'payload', label: 'Payload', content: payload, contentType: 'application/json'}, {name: 'stack', label: 'Stack trace', content: stack}]} %}", ['payload' => '{"orderId":7}', 'stack' => $stack]);

        $payload = $page->filter('[data-raw-data] details[data-raw="payload"]');
        self::assertNotNull($payload->attr('open'));
        self::assertSame("{\n    \"orderId\": 7\n}", $payload->filter('[data-raw-content]')->text('', false));
        self::assertSame('3 lines · application/json', $payload->filter('summary [data-summary-state]')->text());
        self::assertSame('#' . $payload->filter('[data-raw-content]')->attr('id'), $payload->filter('button[data-copy]')->attr('data-copy'));
        $trace = $page->filter('[data-raw-data] details[data-raw="stack"]');
        self::assertNull($trace->attr('open'));
        self::assertSame('25 lines', $trace->filter('summary [data-summary-state]')->text());
        self::assertSame($stack, $trace->filter('[data-raw-content]')->text('', false));
    }

    public function test_the_raw_data_block_keeps_content_that_is_not_json_as_stored(): void
    {
        $page = $this->rendered("{% include '_raw.html.twig' with {key: 'k', items: [{name: 'payload', label: 'Payload', content: '<order id=\"7\"/>'}]} %}");

        self::assertSame('<order id="7"/>', $page->filter('[data-raw="payload"] [data-raw-content]')->text('', false));
        self::assertStringContainsString('&lt;order', $page->filter('[data-raw="payload"] [data-raw-content]')->html());
    }

    public function test_an_empty_state_names_its_cause_and_the_way_forward(): void
    {
        $page = $this->rendered("{% include '_empty.html.twig' with {cause: 'not-enabled', hook: 'no-dead-letters', hook_value: 'orders', sentence: 'orders does not store dead letters.', action: {label: 'How dead letters work', href: 'https://docs.ecotone.tech'}, snippet: {label: 'Show how to enable it', code: 'DbalConfiguration::createWithDefaults()->withDeadLetter(true)'}} %}");

        $empty = $page->filter('[data-empty="not-enabled"]');
        self::assertSame('orders', $empty->attr('data-no-dead-letters'));
        self::assertSame('orders does not store dead letters.', $empty->filter('.empty-sentence')->text());
        self::assertSame('https://docs.ecotone.tech', $empty->filter('a')->attr('href'));
        self::assertSame('Show how to enable it', $empty->filter('[data-empty-snippet] summary')->text());
        self::assertSame('DbalConfiguration::createWithDefaults()->withDeadLetter(true)', $empty->filter('[data-empty-snippet] code')->text());
    }

    public function test_a_concept_hint_explains_what_it_is_why_it_matters_and_what_to_do(): void
    {
        $page = $this->rendered("{% include '_concept.html.twig' with {term: 'dead-letter', text: 'Failed messages'} %}");

        $concept = $page->filter('[data-concept="dead-letter"]');
        self::assertSame('Failed messages', $concept->filter('.concept-term')->text());
        $toggle = $concept->filter('button.concept-toggle');
        self::assertSame('What is a failed message?', $toggle->attr('aria-label'));
        self::assertStringContainsString('dead letter', $concept->filter('[data-concept-what]')->text());
        self::assertSame($toggle->attr('popovertarget'), $concept->filter('[popover]')->attr('id'));
        self::assertStringStartsWith('What it is: ', $concept->filter('[data-concept-what]')->text());
        self::assertStringStartsWith('Why it matters: ', $concept->filter('[data-concept-why]')->text());
        self::assertStringStartsWith('What to do: ', $concept->filter('[data-concept-do]')->text());
        self::assertStringStartsWith('https://docs.ecotone.tech/', $concept->filter('a.concept-docs')->attr('href'));
    }

    public function test_a_page_can_specialize_what_to_do_but_never_what_a_concept_is(): void
    {
        $general = $this->rendered("{% include '_concept.html.twig' with {term: 'projection'} %}")->filter('[data-concept="projection"]');
        $specialized = $this->rendered("{% include '_concept.html.twig' with {term: 'projection', todo: 'Initialize it from the console once.'} %}")->filter('[data-concept="projection"]');

        self::assertSame($general->filter('[data-concept-what]')->text(), $specialized->filter('[data-concept-what]')->text());
        self::assertSame($general->filter('[data-concept-why]')->text(), $specialized->filter('[data-concept-why]')->text());
        self::assertSame('What to do: Initialize it from the console once.', $specialized->filter('[data-concept-do]')->text());
    }

    public function test_an_unknown_concept_term_is_refused_with_the_known_terms(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('dead-letter');

        $this->rendered("{% include '_concept.html.twig' with {term: 'dead-letters'} %}");
    }

    public function test_every_glossary_term_renders_with_all_three_parts(): void
    {
        $terms = $this->rendered('{{ concept_terms()|join(",") }}')->text();

        foreach (explode(',', $terms) as $term) {
            $concept = $this->rendered("{% include '_concept.html.twig' with {term: term} %}", ['term' => $term])->filter('[data-concept]');
            self::assertSame($term, $concept->attr('data-concept'));
            self::assertMatchesRegularExpression('/^What it is: \S.{10,}$/', $concept->filter('[data-concept-what]')->text(), $term);
            self::assertMatchesRegularExpression('/^Why it matters: \S.{10,}$/', $concept->filter('[data-concept-why]')->text(), $term);
            self::assertMatchesRegularExpression('/^What to do: \S.{10,}$/', $concept->filter('[data-concept-do]')->text(), $term);
        }
        self::assertGreaterThanOrEqual(20, count(explode(',', $terms)));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function rendered(string $template, array $context = []): Crawler
    {
        $this->configurePulse();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return new Crawler('<!DOCTYPE html><html><body>' . $twig->createTemplate($template)->render($context) . '</body></html>');
    }
}
