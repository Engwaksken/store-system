<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Support\CustomFont;
use App\Support\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Settings > Appearance: custom font family fetched from Google Fonts or uploaded, always served locally. */
class CustomFontTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
        Storage::fake('public');
    }

    private function fakeGoogle(): void
    {
        $css = "/* cyrillic */\n@font-face { font-family: 'Lato'; font-weight: 400; src: url(https://fonts.gstatic.com/s/lato/cyr.woff2) format('woff2'); }\n"
            ."/* latin */\n@font-face { font-family: 'Lato'; font-weight: 400; src: url(https://fonts.gstatic.com/s/lato/regular.woff2) format('woff2'); unicode-range: U+0000-00FF; }\n"
            ."/* latin */\n@font-face { font-family: 'Lato'; font-weight: 700; src: url(https://fonts.gstatic.com/s/lato/bold.woff2) format('woff2'); unicode-range: U+0000-00FF; }\n";
        Http::fake([
            'fonts.googleapis.com/*' => Http::response($css),
            'fonts.gstatic.com/*' => Http::response('wOF2'.str_repeat("\0", 100)),
        ]);
    }

    public function test_admin_adds_lato_from_google_fonts_and_it_is_served_locally(): void
    {
        $this->fakeGoogle();
        $this->actingAs($this->admin)->post(route('settings.font.store'), ['source' => 'google', 'family' => 'Lato', 'use_now' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $font = CustomFont::current();
        $this->assertSame('Lato', $font['family']);
        $css = Storage::disk('public')->get($font['css']);
        $this->assertStringContainsString("url('font-1.woff2')", $css);
        $this->assertStringNotContainsString('gstatic', $css);          // no runtime calls to Google
        $this->assertStringNotContainsString('cyr.woff2', $css);        // Latin subsets only
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'cyr.woff2'));

        $this->assertSame('custom', Theme::values()['theme_font']);
        $this->assertStringContainsString('--font: "Lato"', Theme::css());
        $this->get(route('dashboard'))->assertSee(asset('storage/'.$font['css']), false);
    }

    public function test_upload_and_remove_and_bad_input(): void
    {
        $regular = UploadedFile::fake()->createWithContent('Brand-Regular.woff2', 'wOF2'.str_repeat("\0", 50));
        $this->actingAs($this->admin)->post(route('settings.font.store'), ['source' => 'upload', 'family' => 'Brand Sans', 'regular' => $regular, 'use_now' => '0'])
            ->assertSessionHasNoErrors();
        $font = CustomFont::current();
        $this->assertSame('Brand Sans', $font['family']);
        $this->assertSame('system', Theme::values()['theme_font']);    // not applied when "use now" is off
        Storage::disk('public')->assertExists(dirname($font['css']).'/400.woff2');

        // A file that is not a font is refused, even with a font extension.
        $fake = UploadedFile::fake()->createWithContent('evil.woff2', '<?php echo 1;');
        $this->post(route('settings.font.store'), ['source' => 'upload', 'family' => 'Evil', 'regular' => $fake])->assertSessionHasErrors('family');
        $this->post(route('settings.font.store'), ['source' => 'google', 'family' => 'Lato; } body { display:none'])->assertSessionHasErrors('family');
        $this->assertSame('Brand Sans', CustomFont::current()['family']);

        AppSetting::put('theme_font', 'custom');
        $this->delete(route('settings.font.destroy'))->assertRedirect();
        $this->assertNull(CustomFont::current());
        $this->assertSame('system', Theme::values()['theme_font']);
        Storage::disk('public')->assertMissing($font['css']);
    }

    public function test_unknown_google_family_and_non_admin(): void
    {
        Http::fake(['fonts.googleapis.com/*' => Http::response('Not found', 400)]);
        $this->actingAs($this->admin)->post(route('settings.font.store'), ['source' => 'google', 'family' => 'Nosuchfont'])
            ->assertSessionHasErrors('family');
        $this->assertNull(CustomFont::current());

        $this->actingAs($this->staff)->post(route('settings.font.store'), ['source' => 'google', 'family' => 'Lato'])->assertForbidden();
    }
}
