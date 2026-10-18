<?php

namespace Tests\Feature;

use Tests\TestCase;

class MetaTagsTest extends TestCase
{
    public static function pages(): array
    {
        return [
            'dashboard' => ['/'],
            'faq' => ['/faq'],
            'privacy' => ['/privacy'],
            'terms' => ['/terms'],
        ];
    }

    /**
     * @dataProvider pages
     */
    public function test_page_has_title_description_and_canonical(string $path): void
    {
        $html = $this->get($path)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<title>[^<]+ \| '.preg_quote(config('app.name'), '/').'<\/title>/', $html);
        $this->assertMatchesRegularExpression('/<meta name="description" content="[^"]{50,}">/', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url($path === '/' ? '' : $path).'">', $html);
    }

    /**
     * @dataProvider pages
     */
    public function test_page_is_mobile_ready(string $path): void
    {
        $html = $this->get($path)->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#0b1220">', $html);
    }

    public function test_titles_and_descriptions_are_unique(): void
    {
        $titles = [];
        $descriptions = [];

        foreach (['/', '/faq', '/privacy', '/terms'] as $path) {
            $html = $this->get($path)->getContent();
            preg_match('/<title>([^<]+)<\/title>/', $html, $t);
            preg_match('/<meta name="description" content="([^"]+)"/', $html, $d);
            $titles[] = $t[1];
            $descriptions[] = $d[1];
        }

        $this->assertCount(4, array_unique($titles));
        $this->assertCount(4, array_unique($descriptions));
    }
}
