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
                    $group->productName(),
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
                        '📘 '.$guide['title'],
                        $guide['url'],
                    ]));
                    $emittedGuides[$guideKey] = true;
                }
            }
        }

        return $this->messageChunker->fromAtomicUnits($units, self::MESSAGE_LIMIT);
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
            $lines[] = $group->productName();
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
                ...($account->number() === 1 && $firstAccount ? [$group->productName(), ''] : []),
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
            $group->productName(),
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
                $group->productName(),
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
                $group->productName(),
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
                'text' => $this->locale->translate($customer, $translationKey, ['number' => $account->number()]),
                'url' => $url,
            ]]],
        ];
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
