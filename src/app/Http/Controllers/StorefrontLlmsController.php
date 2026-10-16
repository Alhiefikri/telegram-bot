<?php

namespace App\Http\Controllers;

use App\ApiDocs\DocumentationSet;
use Illuminate\Http\Response;

/**
 * Root `/llms.txt` index. It merges no earlier index because none existed; it
 * only links public documentation and store surfaces, never private data.
 */
final class StorefrontLlmsController extends Controller
{
    public function __invoke(DocumentationSet $docs): Response
    {
        return response($docs->llms->render())
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
