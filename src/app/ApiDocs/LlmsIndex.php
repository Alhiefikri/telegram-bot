<?php

namespace App\ApiDocs;

/**
 * Renders the concise root `/llms.txt` index: a short summary plus canonical
 * links to the Markdown reference, the OpenAPI description and the store
 * surfaces that already exist. No existing index had to be merged; this file
 * owns the root path and stays a plain link list.
 */
final class LlmsIndex
{
    public function __construct(private readonly CustomerApiDocumentation $documentation) {}

    public function render(): string
    {
        $title = $this->documentation->title()['en'];

        return implode("\n", [
            '# Lhie Store',
            '',
            '> Lhie Store is a digital-goods storefront. '.$title.' lets the backend of a registered Customer read the public catalog and its own balances and create balance-funded orders on this origin. API keys are issued from the private Store Bot; there is no partner onboarding queue, browser key console or funding endpoint.',
            '',
            '## API documentation',
            '',
            '- [Supplier API reference (Markdown)]('.route('storefront.api-docs.reference').'): every endpoint, parameter, schema, enum, limit, error code and runnable cURL, Python, JavaScript and PHP example.',
            '- [OpenAPI 3.1 description]('.route('storefront.api-docs.openapi').'): machine contract for tools, linters and client generators.',
            '- [Bilingual documentation site]('.route('storefront.api-docs.index').'): Indonesian and English explanations with endpoint, field, error and rate-limit tables.',
            '',
            '## Store',
            '',
            '- [Storefront]('.route('storefront.home').'): public catalog and Product pages.',
            '- [Order access]('.route('storefront.order-access.create').'): invoice and Recovery Key based self-service order access.',
            '- [Guides]('.route('storefront.guides.index').'): Product login and usage guides.',
            '',
            '## Policy',
            '',
            '- [Refund policy]('.route('storefront.policies.refund').')',
            '- [Terms and conditions]('.route('storefront.policies.terms').')',
            '- [Privacy policy]('.route('storefront.policies.privacy').')',
            '',
        ]);
    }
}
