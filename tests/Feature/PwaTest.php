<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Installable web app: manifest, home-screen icons from the uploaded favicon, and the <head> tags. */
class PwaTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
        Storage::fake('public');
    }

    public function test_manifest_uses_company_name_theme_colour_and_icons(): void
    {
        AppSetting::put('company_name', 'Kemmy Construction Ltd');
        AppSetting::put('theme_primary', '#223344');

        $res = $this->get(route('pwa.manifest'))->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');
        $m = $res->json();

        $this->assertSame('Kemmy Construction Ltd', $m['name']);
        $this->assertSame('Kemmy Constr', $m['short_name']);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame('#223344', $m['theme_color']);
        $this->assertCount(4, $m['icons']);
        $this->assertContains('maskable', array_column($m['icons'], 'purpose'));
        $this->assertContains('512x512', array_column($m['icons'], 'sizes'));
    }

    public function test_icons_are_square_pngs_without_signing_in(): void
    {
        foreach (['pwa.icon' => [192, 512], 'pwa.maskable' => [192, 512]] as $route => $sizes) {
            foreach ($sizes as $size) {
                $png = $this->get(route($route, ['size' => $size]))->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
                [$w, $h] = getimagesizefromstring($png);
                $this->assertSame([$size, $size], [$w, $h]);
            }
        }
        [$w] = getimagesizefromstring($this->get('/apple-touch-icon.png')->assertOk()->getContent());
        $this->assertSame(180, $w);
        $this->get(route('pwa.icon', ['size' => 64]))->assertNotFound();
    }

    public function test_icons_follow_the_uploaded_favicon(): void
    {
        $before = $this->get(route('pwa.icon', ['size' => 192]))->getContent();

        $this->actingAs($this->admin)->put(route('settings.update'), [
            'company_name' => 'Kemmy Ltd',
            'favicon' => UploadedFile::fake()->image('icon.png', 64, 64),
        ])->assertSessionHasNoErrors();

        $after = $this->get(route('pwa.icon', ['size' => 192]))->assertOk()
            ->assertHeader('Cache-Control', 'max-age=86400, public')->getContent();
        $this->assertNotSame($before, $after);
    }

    public function test_pages_link_the_manifest_and_apple_icon(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('<link rel="manifest" href="'.route('pwa.manifest').'">', false)
            ->assertSee('apple-mobile-web-app-capable', false)
            ->assertSee('rel="apple-touch-icon"', false);

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee('<link rel="manifest"', false);
    }
}
