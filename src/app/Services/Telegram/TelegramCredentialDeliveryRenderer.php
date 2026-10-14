<?php

namespace App\Services\Telegram;

use App\Enums\CredentialAccessMethod;
use App\Models\Customer;
use App\Services\Credentials\CredentialDelivery;
use App\Services\Credentials\CredentialDeliveryAccount;
use App\Services\Credentials\CredentialDeliveryProductGroup;
use InvalidArgumentException;

/**
 * Turns the shared paid credential presentation into ordered, in-memory
 * Telegram units. It owns only channel rendering; delivery state remains in
 * TelegramOutboundEffectLedgerProtocol.
 */
final class TelegramCredentialDeliveryRenderer
{
    public const MESSAGE_LIMIT = 3900;

    public function __construct(
        private readonly TelegramLocale $locale,
        private readonly TelegramMessageChunker $messageChunker,
        private readonly TelegramUiIcons $uiIcons,
        private readonly TelegramProductNameRenderer $productNames,
    ) {}

    /**
     * @return list<TelegramMessageUnit>
     */
    public function accountUnits(
        Customer $customer,
        CredentialDelivery $delivery,
        string $otpButtonTranslationKey,
        string $deliveryNoteTranslationKey,
    ): array {
        $units = [];

        foreach ($delivery->groups() as $group) {
            foreach ($group->accounts() as $account) {
                array_push(
                    $units,
                    ...$this->accountUnitsFor($customer, $group, $account, $otpButtonTranslationKey, $deliveryNoteTranslationKey),
                );
            }
        }

        return $this->messageChunker->fromAtomicUnits($units, self::MESSAGE_LIMIT);
    }

    /**
     * Render compact Product Detail groups without merging account boundaries.
     *
     * @return list<TelegramMessageUnit>
     */
    public function groupedAccountUnits(
        Customer $customer,
        CredentialDelivery $delivery,
        string $accountLabelTranslationKey,
        string $otpButtonTranslationKey,
        string $deliveryNoteTranslationKey,
    ): array {
        $units = [];
        $emittedGuides = [];

        foreach ($delivery->groups() as $group) {
            $firstAccount = true;
            foreach ($group->accounts() as $account) {
                array_push(
                    $units,
                    ...$this->groupedAccountUnitsFor(
                        $customer,
                        $group,
                        $account,
                        $accountLabelTranslationKey,
                        $otpButtonTranslationKey,
                        $deliveryNoteTranslationKey,
                        $firstAccount,
                    ),
                );
                $firstAccount = false;
            }

            if ($group->defaultTutorial() !== null) {
                $units[] = new TelegramMessageUnit(implode("\n", [
                    $this->productName($group),
                    '',
                    $this->locale->translate($customer, $deliveryNoteTranslationKey),
                    $group->defaultTutorial(),
                ]));
            }

            $guide = $group->usageGuide();
            if ($guide !== null) {
                $guideKey = $guide['title']."\n".$guide['url'];
                if (! isset($emittedGuides[$guideKey])) {
                    $units[] = new TelegramMessageUnit(implode("\n", [
                        $this->uiIcons->renderLegacyIcons('📘 ').$guide['title'],
                        $guide['url'],
                    ]));
                    $emittedGuides[$guideKey] = true;
                }
            }
        }

        return $this->messageChunker->fromAtomicUnits($units, self::MESSAGE_LIMIT);
    }

    /**
     * Render receipt-specific atomic blocks. Each returned unit is safe to
     * move between chunks without splitting a credential field.
     *
     * @return list<TelegramMessageUnit>
     */
    public function receiptBlocks(Customer $customer, CredentialDelivery $delivery): array
    {
        $blocks = [];

        foreach ($this->mergedReceiptGroups($delivery) as $entry) {
            $group = $entry['group'];
            $firstAccount = true;
            $accountNumber = 1;
            foreach ($entry['groups'] as $sourceGroup) {
                foreach ($sourceGroup->accounts() as $account) {
                    array_push(
                        $blocks,
                        ...$this->receiptAccountBlocksFor(
                            $customer,
                            $group,
                            $account,
                            $firstAccount,
                            $accountNumber,
                        ),
                    );
                    $firstAccount = false;
                    $accountNumber++;
                }
            }

            $defaultTutorial = $group->defaultTutorial();
            if ($defaultTutorial !== null && trim($defaultTutorial) !== '') {
                array_push(
                    $blocks,
                    ...$this->nonSecretBlocks([
                        $this->productName($group),
                        '',
                        $this->locale->translate($customer, 'receipt.delivery_note'),
                        $defaultTutorial,
                    ]),
                );
            }

            $guide = $group->usageGuide();
            if ($guide !== null) {
                $blocks[] = new TelegramMessageUnit(
                    text: $this->uiIcons->renderLegacyIcons('📘 ').$guide['title'],
                    replyMarkup: [
                        'inline_keyboard' => [[[
                            'text' => $guide['title'],
                            'url' => $guide['url'],
                        ]]],
                    ],
                );
            }
        }

        return $blocks;
    }

    public function receiptProductCount(CredentialDelivery $delivery): int
    {
        return count($this->mergedReceiptGroups($delivery));
    }

    /**
     * @return list<array{group: CredentialDeliveryProductGroup, groups: list<CredentialDeliveryProductGroup>}>
     */
    private function mergedReceiptGroups(CredentialDelivery $delivery): array
    {
        $merged = [];

        foreach ($delivery->groups() as $group) {
            foreach ($merged as $index => $entry) {
                if (! $this->sameReceiptProductSnapshot($entry['group'], $group)) {
                    continue;
                }

                $merged[$index]['groups'][] = $group;

                continue 2;
            }

            $merged[] = [
                'group' => $group,
                'groups' => [$group],
            ];
        }

        return $merged;
    }

    private function sameReceiptProductSnapshot(
        CredentialDeliveryProductGroup $left,
        CredentialDeliveryProductGroup $right,
    ): bool {
        return $left->productName() === $right->productName()
            && $left->productIcon() === $right->productIcon()
            && $left->productCustomEmojiId() === $right->productCustomEmojiId()
            && $left->defaultTutorial() === $right->defaultTutorial()
            && $left->usageGuide() === $right->usageGuide();
    }

    /**
     * @return list<TelegramMessageUnit>
     */
    private function receiptAccountBlocksFor(
        Customer $customer,
        CredentialDeliveryProductGroup $group,
        CredentialDeliveryAccount $account,
        bool $firstAccount,
        int $displayNumber,
    ): array {
        $label = $this->locale->translate($customer, 'receipt.account_label', [
            'number' => $displayNumber,
        ]);

        return $this->copyableAccountUnits(
            $customer,
            $group,
            $account,
            $firstAccount ? [$this->productName($group), '', $label] : [$label],
            $label,
            'receipt.otp_button',
            'receipt.delivery_note',
            displayNumber: $displayNumber,
        );
    }

    /**
     * @param  list<string>  $lines
     * @return list<TelegramMessageUnit>
     */
    private function nonSecretBlocks(array $lines): array
    {
        return array_map(
            static fn (string $text): TelegramMessageUnit => new TelegramMessageUnit($text),
            $this->messageChunker->fromLines($lines, self::MESSAGE_LIMIT),
        );
    }

    /**
     * @return list<TelegramMessageUnit>
     */
    private function groupedAccountUnitsFor(
        Customer $customer,
        CredentialDeliveryProductGroup $group,
        CredentialDeliveryAccount $account,
        string $accountLabelTranslationKey,
        string $otpButtonTranslationKey,
        string $deliveryNoteTranslationKey,
        bool $firstAccount,
    ): array {
        $label = $this->locale->translate($customer, $accountLabelTranslationKey, [
            'number' => $account->number(),
        ]);

        return $this->copyableAccountUnits(
            $customer,
            $group,
            $account,
            $firstAccount ? [$this->productName($group), '', $label] : [$label],
            $label,
            $otpButtonTranslationKey,
            $deliveryNoteTranslationKey,
        );
    }

    /**
     * @return list<TelegramMessageUnit>
     */
    private function accountUnitsFor(
        Customer $customer,
        CredentialDeliveryProductGroup $group,
        CredentialDeliveryAccount $account,
        string $otpButtonTranslationKey,
        string $deliveryNoteTranslationKey,
    ): array {
        $label = 'Data akun #'.$account->number();

        return $this->copyableAccountUnits(
            $customer,
            $group,
            $account,
            [$this->productName($group), '', $label],
            $label,
            $otpButtonTranslationKey,
            $deliveryNoteTranslationKey,
            includeDefaultTutorial: true,
        );
    }

    /**
     * @param  list<string>  $header
     * @return list<TelegramMessageUnit>
     */
    private function copyableAccountUnits(
        Customer $customer,
        CredentialDeliveryProductGroup $group,
        CredentialDeliveryAccount $account,
        array $header,
        string $label,
        string $otpButtonTranslationKey,
        string $deliveryNoteTranslationKey,
        ?int $displayNumber = null,
        bool $includeDefaultTutorial = false,
    ): array {
        $prefix = implode("\n", $header)."\n";
        $credential = $this->copyText($account);
        $token = $account->accessMethod() === CredentialAccessMethod::AuthToken
            ? $account->accessValue() : null;
        if ($account->accessMethod() === CredentialAccessMethod::AuthToken
            && (! is_string($token) || $token === '')) {
            throw new InvalidArgumentException('Structured Auth Token is invalid.');
        }
        $accessNote = '';
        if ($account->accessMethod() === CredentialAccessMethod::OtpMailLink) {
            $domain = $this->validatedDomain($account->accessValue());
            if ($domain === null) {
                throw new InvalidArgumentException('Structured OTP Mail Link is invalid.');
            }
            $accessNote = "\n".$this->locale->translate($customer, 'receipt.access_otp', ['domain' => $domain]);
        } elseif ($token !== null) {
            $accessNote = "\n".$this->locale->translate($customer, 'receipt.access_auth_token');
        }
        $separateToken = $token !== null
            && mb_strlen($prefix.$credential.' | '.$token.$accessNote, 'UTF-8') > self::MESSAGE_LIMIT;
        if ($token !== null && ! $separateToken) {
            $credential .= ' | '.$token;
        }
        $text = $prefix.$credential.$accessNote;
        $entities = [$this->copyEntity($prefix, $credential)];
        if (mb_strlen($text, 'UTF-8') > self::MESSAGE_LIMIT) {
            throw new InvalidArgumentException('Telegram account delivery unit exceeds the configured limit.');
        }

        $tutorial = $account->tutorial();
        if (! $includeDefaultTutorial && $tutorial === $group->defaultTutorial()) {
            $tutorial = null;
        }
        $separateTutorial = null;
        if ($tutorial !== null) {
            $withTutorial = $text."\n\n".$this->locale->translate($customer, $deliveryNoteTranslationKey)."\n".$tutorial;
            if (mb_strlen($withTutorial, 'UTF-8') <= self::MESSAGE_LIMIT) {
                $text = $withTutorial;
            } else {
                $separateTutorial = $tutorial;
            }
        }
        $units = [new TelegramMessageUnit(
            text: $text,
            entities: $entities,
            replyMarkup: $this->otpReplyMarkup($customer, $account, $otpButtonTranslationKey, $displayNumber),
            containsCredentials: true,
        )];
        if ($separateToken) {
            $tokenPrefix = $this->productName($group)."\n\n".$label."\nAuth Token Outlook/Hotmail\n";
            if (mb_strlen($tokenPrefix.$token, 'UTF-8') > self::MESSAGE_LIMIT) {
                throw new InvalidArgumentException('Telegram Auth Token delivery unit exceeds the configured limit.');
            }
            $units[] = new TelegramMessageUnit(
                text: $tokenPrefix.$token,
                entities: [$this->copyEntity($tokenPrefix, $token)],
                containsCredentials: true,
            );
        }
        if ($separateTutorial !== null) {
            array_push($units, ...$this->nonSecretBlocks([
                $this->productName($group),
                '',
                $label,
                $this->locale->translate($customer, $deliveryNoteTranslationKey),
                $separateTutorial,
            ]));
        }

        return $units;
    }

    private function copyText(CredentialDeliveryAccount $account): string
    {
        if ($account->isLegacy()) {
            $parts = $account->legacyParts();

            return $parts === null ? $account->text()
                : $parts['email'].' | '.$parts['password'].($parts['mfa'] === null ? '' : ' | '.$parts['mfa']);
        }
        $email = $account->email();
        $password = $account->password();
        if (! is_string($email) || ! is_string($password)) {
            throw new InvalidArgumentException('Structured credential fields are invalid.');
        }

        return $email.' | '.$password;
    }

    /** @return array{type: string, offset: int, length: int, language: string} */
    private function copyEntity(string $prefix, string $credential): array
    {
        return [
            'type' => 'pre',
            'offset' => intdiv(strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2),
            'length' => intdiv(strlen(mb_convert_encoding($credential, 'UTF-16LE', 'UTF-8')), 2),
            'language' => 'copy',
        ];
    }

    /** @return array<string, mixed> */
    private function otpReplyMarkup(
        Customer $customer,
        CredentialDeliveryAccount $account,
        string $translationKey,
        ?int $displayNumber = null,
    ): array {
        if ($account->accessMethod() !== CredentialAccessMethod::OtpMailLink) {
            return [];
        }

        $url = $account->accessValue();
        $domain = $this->validatedDomain($url);
        if ($url === null || $domain === null) {
            return [];
        }

        return [
            'inline_keyboard' => [[[
                'text' => $this->locale->translate($customer, $translationKey, [
                    'number' => $displayNumber ?? $account->number(),
                ]),
                'url' => $url,
            ]]],
        ];
    }

    private function productName(CredentialDeliveryProductGroup $group): string
    {
        return $this->productNames->render(
            $group->productName(),
            $group->productIcon(),
            $group->productCustomEmojiId(),
        );
    }

    private function validatedDomain(?string $url): ?string
    {
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if ($scheme !== 'https'
            || ! is_string($host)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        return $host;
    }
}
