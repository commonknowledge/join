<?php

namespace CommonKnowledge\JoinBlock\Tests;

use Brain\Monkey;
use CommonKnowledge\JoinBlock\Settings;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Settings::computeTagsToRemove(), which feeds the remove_tags
 * list applied in every CRM during the join flow.
 *
 * It must return the tags added by every plan other than the one being
 * joined, plus the configured lapsed/lapsing tags: a member who has just
 * joined successfully is no longer lapsed or lapsing, so any such tag left
 * over from a previous membership must be cleared.
 */
class SettingsComputeTagsToRemoveTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** Values returned by the carbon_get_theme_option stub, keyed by field name. */
    private array $settings = [];

    /** Plan rows returned by the $wpdb stub, as stored in wp_options. */
    private array $storedPlans = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Monkey\Functions\when('sanitize_title')->alias(function ($title) {
            $title = strtolower((string) $title);
            $title = preg_replace('/[^a-z0-9]+/', '-', $title);
            return trim($title, '-');
        });
        Monkey\Functions\when('maybe_unserialize')->returnArg();
        Monkey\Functions\when('carbon_get_theme_option')->alias(
            fn(string $key) => $this->settings[$key] ?? ''
        );

        global $wpdb;
        $wpdb = new class {
            public string $options = 'wp_options';
            public array $rows = [];
            public function prepare(string $query, ...$args): string
            {
                return $query;
            }
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function esc_like(string $text): string
            {
                return $text;
            }
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function get_results(string $query, $output = null): array
            {
                return $this->rows;
            }
        };

        unset($_ENV['LAPSED_TAG'], $_ENV['LAPSING_TAG']);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
        unset($_ENV['LAPSED_TAG'], $_ENV['LAPSING_TAG']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function setStoredPlans(array $plans): void
    {
        global $wpdb;
        $wpdb->rows = array_map(fn($plan) => ['option_value' => $plan], $plans);
    }

    private function plan(string $label, string $addTags = ''): array
    {
        return [
            'label'     => $label,
            'id'        => strtolower($label),
            'frequency' => 'monthly',
            'currency'  => 'GBP',
            'add_tags'  => $addTags,
        ];
    }

    private function tagsFor(array $currentPlan): array
    {
        $tags = array_filter(array_map('trim', explode(',', Settings::computeTagsToRemove($currentPlan))));
        return array_values($tags);
    }

    public function testConfiguredLapsedAndLapsingTagsAreRemovedOnJoin(): void
    {
        $this->settings = [
            'lapsed_tag'  => 'Lapsed - failed payment',
            'lapsing_tag' => 'Lapsing',
        ];
        $this->setStoredPlans([$this->plan('Standard')]);

        $this->assertSame(
            ['Lapsed - failed payment', 'Lapsing'],
            $this->tagsFor($this->plan('Standard'))
        );
    }

    public function testNothingIsRemovedWhenNoTagsAreConfigured(): void
    {
        $this->setStoredPlans([$this->plan('Standard')]);

        $this->assertSame([], $this->tagsFor($this->plan('Standard')));
    }

    public function testLapsedTagsCombineWithOtherPlansTags(): void
    {
        $this->settings = [
            'lapsed_tag'  => 'Lapsed',
            'lapsing_tag' => 'Lapsing',
        ];
        $this->setStoredPlans([
            $this->plan('Standard'),
            $this->plan('Solidarity', 'solidarity-member'),
        ]);

        $this->assertSame(
            ['Lapsed', 'Lapsing', 'solidarity-member'],
            $this->tagsFor($this->plan('Standard'))
        );
    }

    public function testALapsedTagAddedByTheCurrentPlanIsNotRemoved(): void
    {
        // Pathological configuration, but removal must never fight a tag the
        // plan being joined is about to add.
        $this->settings = [
            'lapsed_tag'  => 'Lapsed',
            'lapsing_tag' => 'Lapsing',
        ];
        $this->setStoredPlans([$this->plan('Standard', 'Lapsed')]);

        $this->assertSame(
            ['Lapsing'],
            $this->tagsFor($this->plan('Standard', 'Lapsed'))
        );
    }

    public function testLapsedTagIsNotDuplicatedWhenAnotherPlanAlsoAddsIt(): void
    {
        $this->settings = ['lapsed_tag' => 'Lapsed'];
        $this->setStoredPlans([
            $this->plan('Standard'),
            $this->plan('Legacy', 'Lapsed'),
        ]);

        $this->assertSame(['Lapsed'], $this->tagsFor($this->plan('Standard')));
    }

    public function testWhitespaceOnlyTagSettingsAreIgnored(): void
    {
        $this->settings = [
            'lapsed_tag'  => '   ',
            'lapsing_tag' => '',
        ];
        $this->setStoredPlans([$this->plan('Standard')]);

        $this->assertSame([], $this->tagsFor($this->plan('Standard')));
    }
}
