<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontPresenter;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StorefrontLoginGuideController extends Controller
{
    private const PDF_PATH = '../docs/Tutorial_Login_Outlook_iCloud_Google_Authenticator_LHIESTORE_FINAL.pdf';

    public function __construct(private readonly StorefrontPresenter $storefront) {}

    public function show(): View
    {
        $brand = $this->storefront->brand();

        return view('storefront.login-guide', [
            'brand' => $brand,
            'copy' => config('storefront'),
            'pageTitle' => 'Panduan Login Akun | '.$brand,
            'pageDescription' => 'Panduan masuk dan mengamankan akun digital Lhie Store memakai Outlook, iCloud, dan Google Authenticator.',
            'canonicalUrl' => route('storefront.login-guide'),
            'supportDestination' => $this->storefront->supportDestination(),
            'pdfUrl' => route('storefront.login-guide.pdf'),
        ]);
    }

    public function downloadPdf(): BinaryFileResponse
    {
        $path = base_path(self::PDF_PATH);
        abort_unless(is_file($path), 404);

        return response()->download($path, 'Tutorial_Login_Outlook_iCloud_Google_Authenticator_LHIESTORE.pdf');
    }
}
