<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * The date on the FAQ, Privacy Policy, Terms and AI page must change whenever
 * the page text changes. Each page keeps a hash of its text in
 * config/prism.php. Change the text and this test fails until you update the
 * hash and set the page's date to the day of the change.
 */
class PolicyDatesTest extends TestCase
{
    private const PAGES = ['faq', 'privacy', 'terms', 'ai'];

    private function textHash(string $page): string
    {
        $source = str_replace("\r\n", "\n", (string) file_get_contents(resource_path("views/{$page}.blade.php")));
        preg_match('#<main.*</main>#s', $source, $match);

        return hash('sha256', $match[0] ?? '');
    }

    public function test_page_text_matches_the_hash_kept_with_its_date(): void
    {
        foreach (self::PAGES as $page) {
            $this->assertSame(
                config("prism.policy_dates.{$page}.hash"),
                $this->textHash($page),
                "The text of the {$page} page changed. In config/prism.php set policy_dates.{$page}.hash to ".$this->textHash($page)." and policy_dates.{$page}.date to today's date."
            );
        }
    }

    public function test_each_date_is_a_real_date_and_not_in_the_future(): void
    {
        foreach (self::PAGES as $page) {
            $date = Carbon::createFromFormat('F j, Y', (string) config("prism.policy_dates.{$page}.date"));

            $this->assertNotFalse($date, "policy_dates.{$page}.date must look like October 21, 2026.");
            $this->assertTrue($date->startOfDay()->lte(now()->endOfDay()->addDay()), "policy_dates.{$page}.date is in the future.");
        }
    }

    public function test_each_page_shows_its_date(): void
    {
        $this->signIn();

        foreach (self::PAGES as $page) {
            $this->get("/{$page}")->assertOk()->assertSee(config("prism.policy_dates.{$page}.date"));
        }
    }
}
