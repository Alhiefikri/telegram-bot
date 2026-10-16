<?php

namespace App\Http\Controllers;

use App\ApiDocs\DocumentationSet;
use App\Storefront\StorefrontPresenter;
use App\Storefront\StorefrontSeo;
use Illuminate\Http\Response;

/**
 * Public supplier API documentation on the existing store origin: the readable
 * page plus the machine files that are rendered from the same canonical
 * contract in {@see DocumentationSet}.
 */
final class StorefrontApiDocsController extends Controller
{
    public function __construct(
        private readonly StorefrontSeo $seo,
        private readonly DocumentationSet $docs,
    ) {}

    public function index(StorefrontPresenter $storefront): Response
    {
        $metadata = $this->seo->apiDocs(route('storefront.api-docs.index'));

        return response()->view('storefront.api-docs', [
            'storefront' => $storefront,
            'brand' => $storefront->brand(),
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'),
            'shopUrl' => route('storefront.shop.index'),
            'pageTitle' => $metadata->title,
            'pageDescription' => $metadata->description,
            'canonicalUrl' => $metadata->canonicalUrl,
            'metadata' => $metadata,
            'documentation' => $this->docs->documentation,
            'guide' => $this->docs->guide,
            'tables' => $this->docs->tables,
            'examples' => $this->docs->examples,
            'samples' => $this->docs->samples,
        ]);
    }

    public function openapi(): Response
    {
        return response($this->docs->openapi->json($this->origin()))
            ->header('Content-Type', 'application/json; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function reference(): Response
    {
        return response($this->docs->reference->render($this->origin()))
            ->header('Content-Type', 'text/markdown; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    private function origin(): string
    {
        return rtrim(url('/'), '/');
    }
}
