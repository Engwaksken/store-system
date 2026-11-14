<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Admin branding: logo, favicon and the sidebar subtitle. */
class BrandingSettingsTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
        Storage::fake('public');
    }

    private function save(array $extra = [])
    {
        return $this->actingAs($this->admin)->put(route('settings.update'), ['company_name' => 'Kemmy Ltd'] + $extra);
    }

    public function test_admin_sets_subtitle_logo_and_favicon_and_they_show_in_the_layout(): void
    {
        $this->save([
            'app_subtitle' => 'Site Stores',
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
            'favicon' => UploadedFile::fake()->image('icon.png', 64, 64),
        ])->assertSessionHasNoErrors();

        $logo = AppSetting::get('logo_path');
        $favicon = AppSetting::get('favicon_path');
        Storage::disk('public')->assertExists([$logo, $favicon]);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Site Stores')
            ->assertSee('storage/'.$logo, false)
            ->assertSee('<link rel="icon" href="'.asset('storage/'.$favicon).'">', false);
    }

    public function test_a_new_upload_replaces_the_old_file_and_remove_clears_it(): void
    {
        $this->save(['logo' => UploadedFile::fake()->image('a.png')]);
        $first = AppSetting::get('logo_path');
        $this->travel(2)->seconds();
        $this->save(['logo' => UploadedFile::fake()->image('b.png')]);
        $second = AppSetting::get('logo_path');

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);

        $this->save(['remove_logo' => '1']);
        $this->assertNull(AppSetting::get('logo_path'));
        Storage::disk('public')->assertMissing($second);
    }

    public function test_admin_chooses_the_currency_and_amounts_follow_it(): void
    {
        $this->assertSame('UGX', currency());
        $this->assertSame('1,250,000', money('1250000.4'));

        $this->save(['currency' => 'USD'])->assertSessionHasNoErrors();
        $this->assertSame('USD', currency());
        $this->assertSame('1,250,000.40', money('1250000.4'));
        $this->get(route('expenses.index'))->assertOk()->assertSee('Amount (USD)')->assertDontSee('(UGX)');

        // Leaving the field out keeps the chosen currency; an unknown code is rejected.
        $this->save()->assertSessionHasNoErrors();
        $this->assertSame('USD', currency());
        $this->save(['currency' => 'XXX'])->assertSessionHasErrors('currency');
    }

    public function test_admin_changes_colours_font_and_style_and_can_restore_the_default(): void
    {
        $this->get(route('login'))->assertDontSee('<style id="theme">', false);   // default look: no override

        $this->save([
            'theme_primary' => '#7b1e1e', 'theme_accent' => '#f5f5f5', 'theme_action' => '#1d4ed8',
            'theme_font' => 'verdana', 'theme_size' => 'large', 'theme_corners' => 'square', 'theme_sidebar' => 'light',
        ])->assertSessionHasNoErrors();

        $css = \App\Support\Theme::css();
        $this->assertStringContainsString('--navy: #7B1E1E;', $css);
        $this->assertStringContainsString('--on-gold: #7B1E1E;', $css);   // light accent gets dark text
        $this->assertStringContainsString('--on-teal: #FFFFFF;', $css);   // dark button gets white text
        $this->assertStringContainsString('Verdana', $css);
        $this->assertStringContainsString('--base-size: 16px;', $css);
        $this->assertStringContainsString('--side-bg: #FFFFFF;', $css);
        $this->get(route('dashboard'))->assertSee('<style id="theme">', false);

        $this->save(['theme_primary' => 'red', 'theme_font' => 'comic'])->assertSessionHasErrors(['theme_primary', 'theme_font']);

        $this->save(['reset_theme' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(\App\Support\Theme::isDefault());
        $this->assertSame('', \App\Support\Theme::css());
    }

    public function test_favicon_must_be_png_or_ico_and_non_admins_cannot_change_branding(): void
    {
        $this->save(['favicon' => UploadedFile::fake()->create('icon.pdf', 10, 'application/pdf')])->assertSessionHasErrors('favicon');
        $this->actingAs($this->staff)->put(route('settings.update'), ['company_name' => 'X'])->assertForbidden();
    }
}
