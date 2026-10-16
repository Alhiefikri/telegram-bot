<?php

namespace App\ApiDocs;

/**
 * Composition root for the published documentation artifacts. Controllers
 * depend on this one object instead of assembling the contract, guide,
 * examples and renderers themselves.
 */
final class DocumentationSet
{
    public readonly CustomerApiDocumentation $documentation;

    public readonly CustomerApiGuide $guide;

    public readonly CustomerApiExamples $examples;

    public readonly CustomerApiSamples $samples;

    public readonly DocumentationTables $tables;

    public readonly OpenApiDocument $openapi;

    public readonly ReferenceMarkdown $reference;

    public readonly LlmsIndex $llms;

    public function __construct()
    {
        $this->documentation = new CustomerApiDocumentation;
        $this->guide = new CustomerApiGuide;
        $this->examples = new CustomerApiExamples;
        $this->samples = new CustomerApiSamples;
        $this->tables = new DocumentationTables($this->documentation);
        $this->openapi = new OpenApiDocument($this->documentation, $this->samples);
        $this->reference = new ReferenceMarkdown($this->documentation, $this->tables, $this->guide, $this->samples, $this->examples);
        $this->llms = new LlmsIndex($this->documentation);
    }
}
