<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Home-screen icons for the installable web app (PWA), built from the favicon uploaded in Settings > Branding,
 * the same way mobile/tool/generate_icon.dart builds the Flutter launcher icon:
 * the mark is trimmed of empty margins and centred on a white square.
 *
 *  - "any" icons (Android, desktop) and the Apple touch icon: mark fills 72% of the square.
 *  - "maskable" icons (Android adaptive shapes): mark kept inside the central 56%, so circles/squircles never cut it.
 *
 * Without a usable uploaded PNG (none, or an .ico GD cannot read) public/icons/app-icon.png is used.
 * Results are cached per source file; a new upload has a new file name, so icons refresh by themselves.
 */
class PwaIcon
{
    public const SIZES = [180, 192, 512];

    public const FALLBACK = 'icons/app-icon.png';

    /** PNG bytes of the icon at $size pixels. */
    public static function png(int $size, bool $maskable = false): string
    {
        [$key, $bytes] = self::source();
        $cacheKey = 'pwa-icon:'.md5($key).":{$size}:".($maskable ? 'm' : 'a');

        return base64_decode(Cache::rememberForever($cacheKey, fn () => base64_encode(self::render($bytes, $size, $maskable))));
    }

    /** A short token that changes whenever the source image changes (used to bust browser caches in URLs). */
    public static function version(): string
    {
        return substr(md5(self::source()[0]), 0, 8);
    }

    /** [cache key, image bytes] of the uploaded favicon when GD can read it, else of the bundled icon. */
    private static function source(): array
    {
        $path = rescue(fn () => AppSetting::get('favicon_path'), null, false);
        if ($path && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png' && Storage::disk('public')->exists($path)) {
            $bytes = Storage::disk('public')->get($path);
            if ($bytes && @imagecreatefromstring($bytes) !== false) {
                return ['upload:'.$path, $bytes];
            }
        }
        $fallback = public_path(self::FALLBACK);

        return ['fallback:'.filemtime($fallback), file_get_contents($fallback)];
    }

    private static function render(string $bytes, int $size, bool $maskable): string
    {
        $src = imagecreatefromstring($bytes);
        imagealphablending($src, true);
        [$x, $y, $w, $h] = self::visibleBox($src);

        $canvas = imagecreatetruecolor($size, $size);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);

        $box = (int) round($size * ($maskable ? 0.56 : 0.72));
        $scale = $box / max($w, $h);
        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));
        imagecopyresampled($canvas, $src, intdiv($size - $dw, 2), intdiv($size - $dh, 2), $x, $y, $dw, $dh, $w, $h);

        ob_start();
        imagepng($canvas, null, 9);
        $png = ob_get_clean();
        imagedestroy($src);
        imagedestroy($canvas);

        return $png;
    }

    /** Bounding box of pixels that are neither (nearly) transparent nor (nearly) white. */
    private static function visibleBox(\GdImage $img): array
    {
        $iw = imagesx($img);
        $ih = imagesy($img);
        $step = max(1, intdiv(max($iw, $ih), 512)); // large uploads: sample, the box only needs to be close
        $minX = $iw;
        $minY = $ih;
        $maxX = -1;
        $maxY = -1;
        for ($y = 0; $y < $ih; $y += $step) {
            for ($x = 0; $x < $iw; $x += $step) {
                $c = imagecolorsforindex($img, imagecolorat($img, $x, $y));
                $opaque = $c['alpha'] < 115; // GD alpha: 0 opaque .. 127 transparent
                $white = $c['red'] > 245 && $c['green'] > 245 && $c['blue'] > 245;
                if ($opaque && ! $white) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }
        if ($maxX < 0) {
            return [0, 0, $iw, $ih]; // blank or all-white image: use it whole
        }
        $maxX = min($iw - 1, $maxX + $step - 1);
        $maxY = min($ih - 1, $maxY + $step - 1);

        return [$minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1];
    }
}
