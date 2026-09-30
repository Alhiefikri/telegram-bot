<?php

namespace App\Services\Telegram;

use App\Enums\CredentialAccessMethod;
use App\Models\Customer;
use App\Services\CredentialDeliveryFormatter;
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
        private readonly CredentialDeliveryFormatter $credentialFormatter,
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
        $lines = $firstAccount
            ? [$this->productName($group), '', $label]
            : [$label];
        $fields = [];

        if ($account->isStructured()) {
            $email = $account->email();
            $password = $account->password();
            if (! is_string($email) || ! is_string($password)) {
                throw new InvalidArgumentException('Structured credential fields are invalid.');
            }

            $lines[] = 'email : '.$email;
            $fields[] = ['label' => 'email', 'value' => $email];
            $lines[] = 'password : '.$password;
            $fields[] = ['label' => 'password', 'value' => $password];
        } else {
            $lines[] = $account->text();
        }

        $replyMarkup = $this->otpReplyMarkup(
            $customer,
            $account,
            'receipt.otp_button',
            displayNumber: $displayNumber,
        );
        if ($account->accessMethod() === CredentialAccessMethod::OtpMailLink) {
            $domain = $this->validatedDomain($account->accessValue());
            if ($domain === null) {
                throw new InvalidArgumentException('Structured OTP Mail Link is invalid.');
            }
            $lines[] = $this->locale->translate($customer, 'receipt.access_otp', ['domain' => $domain]);
        }

        $token = null;
        if ($account->accessMethod() === CredentialAccessMethod::AuthToken) {
            $token = $account->accessValue();
            if (! is_string($token) || $token === '') {
                throw new InvalidArgumentException('Structured Auth Token is invalid.');
            }
        }

        $tokenLines = $token === null
            ? []
            : [
                $this->locale->translate($customer, 'receipt.access_auth_token'),
                'Auth Token : '.$token,
            ];
        $tokenFields = $token === null
            ? []
            : [['label' => 'Auth Token', 'value' => $token]];
        $tokenNeedsOwnUnit = false;

        $withToken = [...$lines, ...$tokenLines];
        if ($token !== null && mb_strlen(implode("\n", $withToken), 'UTF-8') <= self::MESSAGE_LIMIT) {
            $lines = $withToken;
            $fields = [...$fields, ...$tokenFields];
        } elseif ($token !== null) {
            $tokenNeedsOwnUnit = true;
        }

        $accountText = implode("\n", $lines);
        $tutorial = $account->tutorial() !== null
            && $account->tutorial() !== $group->defaultTutorial()
            ? $account->tutorial()
            : null;
        $tutorialSeparate = false;
        if ($tutorial !== null) {
            $withTutorial = implode("\n", [
                $accountText,
                '',
                $this->locale->translate($customer, 'receipt.delivery_note'),
                $tutorial,
            ]);
            if (mb_strlen($withTutorial, 'UTF-8') <= self::MESSAGE_LIMIT) {
                $accountText = $withTutorial;
            } else {
                $tutorialSeparate = true;
            }
        }

        if (mb_strlen($accountText, 'UTF-8') > self::MESSAGE_LIMIT) {
            throw new InvalidArgumentException('Telegram account delivery unit exceeds the configured limit.');
        }

        $entities = $fields === []
            ? ($account->legacyParts() !== null ? $this->credentialFormatter->codeEntities($accountText) : [])
            : $this->credentialFormatter->codeEntitiesForFields($accountText, $fields);
        $blocks = [
            new TelegramMessageUnit(
                text: $accountText,
                entities: $entities,
                replyMarkup: $replyMarkup,
                containsCredentials: true,
            ),
        ];

        if ($tokenNeedsOwnUnit) {
            $tokenText = implode("\n", [
                $label,
                ...$tokenLines,
            ]);
            if (mb_strlen($tokenText, 'UTF-8') > self::MESSAGE_LIMIT) {
                throw new InvalidArgumentException('Telegram Auth Token delivery unit exceeds the configured limit.');
            }

            $blocks[] = new TelegramMessageUnit(
                text: $tokenText,
                entities: $this->credentialFormatter->codeEntitiesForFields($tokenText, $tokenFields),
                containsCredentials: true,
            );
        }

        if ($tutorialSeparate && $tutorial !== null) {
            array_push(
                $blocks,
                ...$this->nonSecretBlocks([
                    $label,
                    $this->locale->translate($customer, 'receipt.delivery_note'),
                    $tutorial,
                ]),
            );
        }

        return $blocks;
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
        $lines = [];
        if ($firstAccount) {
            $lines[] = $this->productName($group);
            $lines[] = '';
        }
        $lines[] = $label;
        $fields = [];

        if ($account->isStructured()) {
            $email = $account->email();
            $password = $account->password();
            if (! is_string($email) || ! is_string($password)) {
                throw new InvalidArgumentException('Structured credential fields are invalid.');
            }

            $lines[] = 'email : '.$email;
            $fields[] = ['label' => 'email', 'value' => $email];
            $lines[] = 'password : '.$password;
            $fields[] = ['label' => 'password', 'value' => $password];
        } else {
            $lines[] = $account->text();
        }

        if ($account->accessMethod() === CredentialAccessMethod::OtpMailLink) {
            $domain = $this->validatedDomain($account->accessValue());
            if ($domain !== null) {
                $lines[] = 'OTP Mail: '.$domain;
            }
        }

        $token = null;
        if ($account->accessMethod() === CredentialAccessMethod::AuthToken) {
            $token = $account->accessValue();
            if (! is_string($token) || $token === '') {
                throw new InvalidArgumentException('Structured Auth Token is invalid.');
            }
        }

        $tokenLines = $token === null
            ? []
            : ['Auth Token Outlook/Hotmail', 'Auth Token : '.$token];
        $tokenFields = $token === null
            ? []
            : [['label' => 'Auth Token', 'value' => $token]];

        $tutorial = $account->tutorial() !== null
            && $account->tutorial() !== $group->defaultTutorial()
            ? $account->tutorial()
            : null;
        $credentialLines = [...$lines, ...$tokenLines];
        $withTutorial = $tutorial === null
            ? $credentialLines
            : [...$credentialLines, '', $this->locale->translate($customer, $deliveryNoteTranslationKey), $tutorial];
        $tokenNeedsOwnUnit = $token !== null
            && mb_strlen(implode("\n", $withTutorial), 'UTF-8') > self::MESSAGE_LIMIT;
        if ($tokenNeedsOwnUnit) {
            $withTutorial = $tutorial === null
                ? $lines
                : [...$lines, '', $this->locale->translate($customer, $deliveryNoteTranslationKey), $tutorial];
        }

        $accountText = implode("\n", $withTutorial);
        if (mb_strlen($accountText, 'UTF-8') > self::MESSAGE_LIMIT) {
            throw new InvalidArgumentException('Telegram account delivery unit exceeds the configured limit.');
        }

        $accountEntities = $this->credentialFormatter->codeEntitiesForFields(
            $accountText,
            [...$fields, ...($tokenNeedsOwnUnit ? [] : $tokenFields)],
        );
        if ($accountEntities === [] && $account->isLegacy()) {
            $accountEntities = $this->credentialFormatter->codeEntities($accountText);
        }

        $units = [
            new TelegramMessageUnit(
                text: $accountText,
                entities: $accountEntities,
                replyMarkup: $this->otpReplyMarkup($customer, $account, $otpButtonTranslationKey),
            ),
        ];

        if ($tokenNeedsOwnUnit) {
            $tokenUnitLines = [
                ...($account->number() === 1 && $firstAccount ? [$this->productName($group), ''] : []),
                $label.' — Auth Token Outlook/Hotmail',
                ...$tokenLines,
            ];
            $tokenText = implode("\n", $tokenUnitLines);
            if (mb_strlen($tokenText, 'UTF-8') > self::MESSAGE_LIMIT) {
                throw new InvalidArgumentException('Telegram Auth Token delivery unit exceeds the configured limit.');
            }

            $units[] = new TelegramMessageUnit(
                text: $tokenText,
                entities: $this->credentialFormatter->codeEntitiesForFields($tokenText, $tokenFields),
            );
        }

        return $units;
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
        $number = $account->number();
        $label = 'Data akun #'.$number;
        $lines = [
            $this->productName($group),
            '',
            $label,
        ];
        $fields = [];

        if ($account->isStructured()) {
            $email = $account->email();
            $password = $account->password();
            if (! is_string($email) || ! is_string($password)) {
                throw new InvalidArgumentException('Structured credential fields are invalid.');
            }

            $lines[] = 'email : '.$email;
            $fields[] = ['label' => 'email', 'value' => $email];
            $lines[] = 'password : '.$password;
            $fields[] = ['label' => 'password', 'value' => $password];
        } else {
            $lines[] = $account->text();
        }

        if ($account->accessMethod() === CredentialAccessMethod::OtpMailLink) {
            $domain = $this->validatedDomain($account->accessValue());
            if ($domain !== null) {
                $lines[] = 'OTP Mail: '.$domain;
            }
        }

        $token = null;
        if ($account->accessMethod() === CredentialAccessMethod::AuthToken) {
            $token = $account->accessValue();
            if (! is_string($token) || $token === '') {
                throw new InvalidArgumentException('Structured Auth Token is invalid.');
            }
        }

        $tokenLines = [];
        $tokenFields = [];
        if ($token !== null) {
            $tokenLines = [
                'Auth Token Outlook/Hotmail',
                'Auth Token : '.$token,
            ];
            $tokenFields[] = ['label' => 'Auth Token', 'value' => $token];
        }

        $baseTextWithToken = implode("\n", [...$lines, ...$tokenLines]);
        $tokenNeedsOwnUnit = $token !== null && mb_strlen($baseTextWithToken, 'UTF-8') > self::MESSAGE_LIMIT;
        if (! $tokenNeedsOwnUnit) {
            $lines = [...$lines, ...$tokenLines];
            $fields = [...$fields, ...$tokenFields];
        }

        $replyMarkup = $this->otpReplyMarkup($customer, $account, $otpButtonTranslationKey);
        $accountUnit = $this->unitWithTutorial(
            customer: $customer,
            group: $group,
            account: $account,
            label: $label,
            lines: $lines,
            fields: $fields,
            replyMarkup: $replyMarkup,
            deliveryNoteTranslationKey: $deliveryNoteTranslationKey,
        );
        $units = [$accountUnit['unit']];

        if ($tokenNeedsOwnUnit) {
            $tokenUnitText = implode("\n", [
                $this->productName($group),
                '',
                $label.' — Auth Token Outlook/Hotmail',
                ...$tokenLines,
            ]);
            $units[] = new TelegramMessageUnit(
                text: $tokenUnitText,
                entities: $this->credentialFormatter->codeEntitiesForFields($tokenUnitText, $tokenFields),
            );
        }

        if ($accountUnit['tutorial'] !== null) {
            $tutorialText = implode("\n", [
                $this->productName($group),
                '',
                $label,
                $this->locale->translate($customer, $deliveryNoteTranslationKey),
                $accountUnit['tutorial'],
            ]);
            $units[] = new TelegramMessageUnit($tutorialText);
        }

        return $units;
    }

    /**
     * @param  list<string>  $lines
     * @param  list<array{label: string, value: string}>  $fields
     * @param  array<string, mixed>  $replyMarkup
     * @return array{unit: TelegramMessageUnit, tutorial: ?string}
     */
    private function unitWithTutorial(
        Customer $customer,
        CredentialDeliveryProductGroup $group,
        CredentialDeliveryAccount $account,
        string $label,
        array $lines,
        array $fields,
        array $replyMarkup,
        string $deliveryNoteTranslationKey,
    ): array {
        $tutorial = $account->tutorial();
        $baseText = implode("\n", $lines);
        $withTutorial = $tutorial === null
            ? $baseText
            : implode("\n", [
                ...$lines,
                '',
                $this->locale->translate($customer, $deliveryNoteTranslationKey),
                $tutorial,
            ]);

        $separateTutorial = null;
        if ($tutorial !== null && mb_strlen($withTutorial, 'UTF-8') > self::MESSAGE_LIMIT) {
            $withTutorial = $baseText;
            $separateTutorial = $tutorial;
        }

        return [
            'unit' => new TelegramMessageUnit(
                text: $withTutorial,
                entities: $this->credentialFormatter->codeEntitiesForFields($withTutorial, $fields)
                    ?: ($account->isLegacy() ? $this->credentialFormatter->codeEntities($withTutorial) : []),
                replyMarkup: $replyMarkup,
            ),
            'tutorial' => $separateTutorial,
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
