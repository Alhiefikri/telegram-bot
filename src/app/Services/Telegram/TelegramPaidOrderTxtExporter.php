<?php

namespace App\Services\Telegram;

use App\Enums\CredentialAccessMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Credentials\CredentialDelivery;
use App\Services\Credentials\CredentialDeliveryAccount;
use App\Services\Credentials\CredentialDeliveryPresentation;
use App\Services\Credentials\CredentialDeliveryProductGroup;
use InvalidArgumentException;
use Throwable;

/**
 * Builds the explicitly requested full credential export in memory.
 *
 * The paid credential projection is the only source of credential values. The
 * resulting payload is intentionally usable only during the current transport
 * attempt and is never suitable for a queue, cache, or effect row.
 */
final class TelegramPaidOrderTxtExporter
{
    public const MAX_OUTPUT_BYTES = TelegramDocumentPayload::MAX_BYTES;

    public function __construct(
        private readonly CredentialDeliveryPresentation $credentialPresentation,
    ) {}

    /**
     * @return ?TelegramDocumentPayload null when the paid Order has no allocated
     *                                  credentials to download
     */
    public function export(Order $order, Customer $customer): ?TelegramDocumentPayload
    {
        try {
            $order->loadMissing([
                'customer',
                'payments',
                'orderItems.stockItems',
                'orderItems.product',
            ]);

            // This is the sole credential authorization/projection call. Do not
            // accept credential data supplied by a callback or queue payload.
            $delivery = $this->credentialPresentation->forCustomer($customer, $order);
            $allocatedCredentialCount = $this->allocatedCredentialCount($order);

            if ($delivery->accountCount() === 0) {
                if ($allocatedCredentialCount === 0) {
                    return null;
                }

                throw new InvalidArgumentException('TXT credential projection is unavailable.');
            }

            if ($delivery->accountCount() !== $allocatedCredentialCount) {
                throw new InvalidArgumentException('TXT credential projection is incomplete.');
            }

            $contents = $this->contents($order, $delivery);
            if ($contents === ''
                || ! mb_check_encoding($contents, 'UTF-8')
                || str_starts_with($contents, "\xEF\xBB\xBF")
                || str_contains($contents, "\r")
                || strlen($contents) > self::MAX_OUTPUT_BYTES) {
                throw new InvalidArgumentException('TXT credential export is outside the configured bounds.');
            }

            return new TelegramDocumentPayload(
                documentContents: $contents,
                filename: $this->filename($order),
            );
        } catch (Throwable) {
            // Never let malformed persisted credential data or a renderer
            // detail become an exception/log value containing a secret.
            throw new InvalidArgumentException('TXT credential export is unavailable.');
        }
    }

    private function contents(Order $order, CredentialDelivery $delivery): string
    {
        $header = [
            'LHIE STORE',
            'Order : '.$this->displayLine($this->orderReference($order), 'Order reference'),
            'Items : '.$this->totalQuantity($order),
        ];

        $sections = [];
        $emittedGuides = [];
        $knownGuides = [];
        foreach ($delivery->usageGuides() as $guide) {
            if (! is_array($guide)) {
                throw new InvalidArgumentException('TXT usage guide projection is invalid.');
            }

            $title = $this->singleLine($guide['title'] ?? null, 'Usage guide title');
            $url = $this->singleLine($guide['url'] ?? null, 'Usage guide URL');
            $knownGuides[$title."\n".$url] = ['title' => $title, 'url' => $url];
        }

        foreach ($delivery->groups() as $group) {
            $sections[] = $this->productSection($group, $emittedGuides, $knownGuides);
        }

        // A valid presentation derives these guides from the same groups. Keep
        // the aggregate projection authoritative if a future presentation adds
        // a guide that is not attached directly to a group.
        foreach ($knownGuides as $key => $guide) {
            if (isset($emittedGuides[$key])) {
                continue;
            }

            $sections[] = implode("\n", [
                'Usage guide : '.$guide['title'],
                'URL : '.$guide['url'],
            ]);
            $emittedGuides[$key] = true;
        }

        if ($sections === []) {
            throw new InvalidArgumentException('TXT credential export has no credential sections.');
        }

        return implode("\n\n", [implode("\n", $header), ...$sections]);
    }

    /**
     * @param  array<string, bool>  $emittedGuides
     * @param  array<string, array{title: string, url: string}>  $knownGuides
     */
    private function productSection(
        CredentialDeliveryProductGroup $group,
        array &$emittedGuides,
        array $knownGuides,
    ): string {
        $productName = $this->displayLine($group->productName(), 'Product name');
        $lines = ['Product : '.$productName];

        foreach ($group->accounts() as $account) {
            if (! $account instanceof CredentialDeliveryAccount || $account->number() < 1) {
                throw new InvalidArgumentException('TXT credential account projection is invalid.');
            }

            $lines[] = '';
            $lines[] = 'Account #'.$account->number();
            array_push($lines, ...$this->accountLines($account));

            $tutorial = $account->tutorial();
            if ($tutorial !== null && $tutorial !== $group->defaultTutorial()) {
                $lines[] = '';
                $lines[] = 'Delivery note :';
                array_push($lines, ...$this->blockLines($tutorial, 'Delivery note'));
            }
        }

        $defaultTutorial = $group->defaultTutorial();
        if ($defaultTutorial !== null) {
            $lines[] = '';
            $lines[] = 'Delivery note :';
            array_push($lines, ...$this->blockLines($defaultTutorial, 'Delivery note'));
        }

        $guide = $group->usageGuide();
        if ($guide !== null) {
            if (! is_array($guide)) {
                throw new InvalidArgumentException('TXT usage guide projection is invalid.');
            }

            $title = $this->singleLine($guide['title'] ?? null, 'Usage guide title');
            $url = $this->singleLine($guide['url'] ?? null, 'Usage guide URL');
            $key = $title."\n".$url;
            if (isset($knownGuides[$key]) && ! isset($emittedGuides[$key])) {
                $lines[] = '';
                $lines[] = 'Usage guide : '.$title;
                $lines[] = 'URL : '.$url;
                $emittedGuides[$key] = true;
            }
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function accountLines(CredentialDeliveryAccount $account): array
    {
        if ($account->isLegacy()) {
            return $this->legacyLines($account->text());
        }

        $email = $this->credentialField($account->email(), 'email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('TXT structured email is invalid.');
        }
        $password = $this->credentialField($account->password(), 'password');
        $accessMethod = $account->accessMethod();
        if (! $accessMethod instanceof CredentialAccessMethod) {
            throw new InvalidArgumentException('TXT structured access method is invalid.');
        }

        $lines = [
            'email : '.$email,
            'password : '.$password,
        ];

        if ($account->readerMailLink() !== null) {
            $token = $this->credentialField($account->accessValue(), 'Akses Token');
            if (mb_strlen($token, 'UTF-8') > 3500 || ! $this->validOtpMailLink($account->readerMailLink())) {
                throw new InvalidArgumentException('TXT reader mail credential is invalid.');
            }

            $lines[] = 'akses token : '.$token;
            $lines[] = 'link reader mail : '.$account->readerMailLink();

            return $lines;
        }

        match ($accessMethod) {
            CredentialAccessMethod::None => $this->assertNoAccessValue($account->accessValue()),
            CredentialAccessMethod::OtpMailLink => $this->otpLines($lines, $account->accessValue()),
            CredentialAccessMethod::AuthToken => $this->authTokenLines($lines, $account->accessValue()),
        };

        return $lines;
    }

    /** @return list<string> */
    private function legacyLines(string $text): array
    {
        if ($text === '' || ! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('TXT legacy credential is invalid.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            throw new InvalidArgumentException('TXT legacy credential contains unsafe characters.');
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $text);

        return explode("\n", $normalized);
    }

    /** @param list<string> $lines */
    private function otpLines(array &$lines, ?string $url): void
    {
        if (! $this->validOtpMailLink($url)) {
            throw new InvalidArgumentException('TXT OTP Mail Link is invalid.');
        }

        $lines[] = 'access: OTP Mail Link';
        $lines[] = 'OTP Mail Link : '.$url;
    }

    /** @param list<string> $lines */
    private function authTokenLines(array &$lines, ?string $token): void
    {
        $token = $this->credentialField($token, 'Auth Token');
        if (mb_strlen($token, 'UTF-8') > 3500) {
            throw new InvalidArgumentException('TXT Auth Token is too long.');
        }

        $lines[] = 'access: Auth Token';
        $lines[] = 'Auth Token : '.$token;
    }

    private function assertNoAccessValue(?string $accessValue): void
    {
        if ($accessValue !== null) {
            throw new InvalidArgumentException('TXT access value is not allowed for access method none.');
        }
    }

    private function validOtpMailLink(?string $url): bool
    {
        if ($url === null
            || mb_strlen($url, 'UTF-8') > 2048
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        return $scheme === 'https'
            && is_string($host)
            && $host !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function credentialField(?string $value, string $field): string
    {
        if ($value === null
            || $value === ''
            || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('TXT '.$field.' is invalid.');
        }

        return $value;
    }

    /** @return list<string> */
    private function blockLines(?string $value, string $field): array
    {
        if ($value === null || trim($value) === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('TXT '.$field.' is invalid.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('TXT '.$field.' contains unsafe characters.');
        }

        return explode("\n", str_replace(["\r\n", "\r"], "\n", $value));
    }

    private function singleLine(mixed $value, string $field): string
    {
        if (! is_string($value)
            || $value === ''
            || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('TXT '.$field.' is invalid.');
        }

        return $value;
    }

    private function orderReference(Order $order): string
    {
        $invoice = $order->getAttribute('invoice_number');
        if (is_string($invoice) && trim($invoice) !== '') {
            return $invoice;
        }

        $key = $order->getKey();
        if ($key === null || (string) $key === '') {
            throw new InvalidArgumentException('TXT Order reference is unavailable.');
        }

        return (string) $key;
    }

    private function displayLine(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('TXT '.$field.' is invalid.');
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('TXT '.$field.' is invalid.');
        }

        return $value;
    }

    private function totalQuantity(Order $order): int
    {
        $total = 0;
        foreach ($order->orderItems as $item) {
            $quantity = $item->getAttribute('quantity');
            if (! is_int($quantity)) {
                $quantity = (int) $quantity;
            }
            if ($quantity < 0 || $total > PHP_INT_MAX - $quantity) {
                throw new InvalidArgumentException('TXT Order quantity is invalid.');
            }

            $total += $quantity;
        }

        return $total;
    }

    private function allocatedCredentialCount(Order $order): int
    {
        $count = 0;
        foreach ($order->orderItems as $item) {
            $count += $item->stockItems->count();
        }

        return $count;
    }

    private function filename(Order $order): string
    {
        $identifier = $this->orderReference($order);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $identifier) ?? '';
        $safe = preg_replace('/\.{2,}/', '.', $safe) ?? '';
        $safe = preg_replace('/-{2,}/', '-', $safe) ?? '';
        $safe = trim($safe, '.-');
        $safe = substr($safe, 0, 96);
        $safe = trim($safe, '.-');

        return 'lhie-store-'.($safe !== '' ? $safe : 'order').'.txt';
    }
}
