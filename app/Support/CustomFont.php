<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A custom font family added by the admin (Settings > Appearance), always served from this site's own storage:
 *  - fetched once from Google Fonts by name (the server downloads the files; browsers never contact Google), or
 *  - uploaded as .woff2 / .woff / .ttf files.
 * Files live on the public disk under fonts/<slug>/ with a font.css of @font-face rules next to them.
 */
class CustomFont
{
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ]{1,39}$/';

    private const MAX_FILE_BYTES = 2 * 1024 * 1024;

    /** @return array{family:string, css:string}|null */
    public static function current(): ?array
    {
        $family = rescue(fn () => AppSetting::get('custom_font_family'), null, false);
        $css = rescue(fn () => AppSetting::get('custom_font_css'), null, false);

        return $family && $css ? ['family' => $family, 'css' => $css] : null;
    }

    public static function cssUrl(): ?string
    {
        $font = self::current();

        return $font ? asset('storage/'.$font['css']) : null;
    }

    /** CSS font-family value for the custom font with a safe fallback. The name is validated by NAME_PATTERN. */
    public static function stack(string $family): string
    {
        return '"'.$family.'", "Segoe UI", Roboto, Arial, sans-serif';
    }

    /** Download regular and bold (when the family has it) from Google Fonts and store them locally. */
    public static function installFromGoogle(string $family): void
    {
        self::assertName($family);
        $css = null;
        foreach (['wght@400;700', null] as $axis) {
            $res = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'])
                ->get('https://fonts.googleapis.com/css2', ['family' => $family.($axis ? ':'.$axis : ''), 'display' => 'swap']);
            if ($res->successful()) {
                $css = $res->body();
                break;
            }
        }
        if ($css === null) {
            throw new RuntimeException("Google Fonts has no family called \"{$family}\". Check the spelling on fonts.google.com.");
        }

        // Keep the Latin subsets only (covers English and most African languages written in Latin script).
        preg_match_all('#/\*\s*([a-z\-]+)\s*\*/\s*(@font-face\s*\{[^}]*\})#', $css, $blocks, PREG_SET_ORDER);
        $faces = array_values(array_filter($blocks, fn ($b) => in_array($b[1], ['latin', 'latin-ext'], true)));
        if (! $faces) {
            throw new RuntimeException("Google Fonts returned no usable files for \"{$family}\".");
        }

        $dir = self::freshDirectory($family);
        $out = [];
        foreach ($faces as $i => [, , $face]) {
            if (! preg_match('#url\((https://fonts\.gstatic\.com/[^)\s]+\.woff2)\)#', $face, $m)) {
                continue;
            }
            $file = Http::timeout(30)->get($m[1]);
            if (! $file->successful() || strlen($file->body()) > self::MAX_FILE_BYTES || ! self::looksLikeFont($file->body())) {
                Storage::disk('public')->deleteDirectory($dir);
                throw new RuntimeException('A font file could not be downloaded. Try again later.');
            }
            $name = "font-{$i}.woff2";
            Storage::disk('public')->put("{$dir}/{$name}", $file->body());
            $out[] = str_replace($m[0], "url('{$name}')", $face);
        }
        self::finish($family, $dir, implode("\n", $out));
    }

    /** Store uploaded regular (and optional bold) files for $family. */
    public static function installFromUpload(string $family, UploadedFile $regular, ?UploadedFile $bold = null): void
    {
        self::assertName($family);
        $dir = self::freshDirectory($family);
        $css = [];
        foreach (['400' => $regular, '700' => $bold] as $weight => $file) {
            if (! $file) {
                continue;
            }
            $bytes = file_get_contents($file->getRealPath());
            $ext = strtolower($file->getClientOriginalExtension());
            if (! self::looksLikeFont($bytes) || ! in_array($ext, ['woff2', 'woff', 'ttf'], true)) {
                Storage::disk('public')->deleteDirectory($dir);
                throw new RuntimeException('That file is not a font. Upload a .woff2, .woff or .ttf file.');
            }
            $name = "{$weight}.{$ext}";
            Storage::disk('public')->put("{$dir}/{$name}", $bytes);
            $format = ['woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype'][$ext];
            $css[] = "@font-face { font-family: '{$family}'; font-style: normal; font-weight: {$weight}; font-display: swap; src: url('{$name}') format('{$format}'); }";
        }
        if (! $bold) {
            // Let headings and buttons reuse the regular file instead of faking bold from an unrelated font.
            $css[] = str_replace('font-weight: 400', 'font-weight: 500 800', $css[0]);
        }
        self::finish($family, $dir, implode("\n", $css));
    }

    public static function remove(): void
    {
        $font = self::current();
        if ($font) {
            Storage::disk('public')->deleteDirectory(dirname($font['css']));
        }
        AppSetting::put('custom_font_family', null);
        AppSetting::put('custom_font_css', null);
        if (rescue(fn () => AppSetting::get('theme_font'), null, false) === 'custom') {
            AppSetting::put('theme_font', null);
        }
    }

    /** Magic numbers of WOFF2, WOFF, TrueType and OpenType files. */
    public static function looksLikeFont(string $bytes): bool
    {
        $head = substr($bytes, 0, 4);

        return in_array($head, ['wOF2', 'wOFF', "\x00\x01\x00\x00", 'true', 'OTTO'], true);
    }

    private static function assertName(string $family): void
    {
        if (! preg_match(self::NAME_PATTERN, $family)) {
            throw new RuntimeException('Use letters, digits and spaces only for the font name (e.g. Lato, Open Sans).');
        }
    }

    private static function freshDirectory(string $family): string
    {
        // A new directory per install so browsers never keep an old cached file.
        return 'fonts/'.Str::slug($family).'-'.now()->format('YmdHis');
    }

    private static function finish(string $family, string $dir, string $css): void
    {
        Storage::disk('public')->put("{$dir}/font.css", "/* {$family} - installed ".now()->toDateTimeString()." */\n".$css."\n");
        $old = self::current();
        AppSetting::put('custom_font_family', $family);
        AppSetting::put('custom_font_css', "{$dir}/font.css");
        if ($old && dirname($old['css']) !== $dir) {
            Storage::disk('public')->deleteDirectory(dirname($old['css']));
        }
    }
}
