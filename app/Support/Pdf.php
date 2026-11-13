<?php

namespace App\Support;

use App\Models\AppSetting;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Renders a Blade view to PDF with dompdf. Remote resources are disabled; the logo is embedded as a data URI. */
final class Pdf
{
    public static function download(string $view, array $data, string $filename, string $orientation = 'portrait'): Response
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', base_path());
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, $data + ['logoData' => self::logo(), 'company' => self::company()])->render());
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private static function logo(): ?string
    {
        $path = AppSetting::get('logo_path');
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }
        $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($path));
    }

    private static function company(): array
    {
        return [
            'name' => AppSetting::get('company_name') ?: config('app.name'),
            'address' => AppSetting::get('company_address'),
            'phone' => AppSetting::get('company_phone'),
            'email' => AppSetting::get('company_email'),
            'footer' => AppSetting::get('voucher_footer'),
        ];
    }
}
