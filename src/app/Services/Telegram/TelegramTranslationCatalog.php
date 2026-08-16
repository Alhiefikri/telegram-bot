<?php

namespace App\Services\Telegram;

use Illuminate\Contracts\Translation\Translator;

final class TelegramTranslationCatalog
{
    private const DEFAULT_LOCALE = 'en';

    private const MISSING_KEY = 'translation.missing';

    public function __construct(private Translator $translator) {}

    public function get(string $key, string $locale): string
    {
        $translation = $this->translator->get($key, [], $locale);
        if ($this->isResolved($translation, $key)) {
            return $translation;
        }

        $fallback = $this->translator->get($key, [], self::DEFAULT_LOCALE);
        if ($this->isResolved($fallback, $key)) {
            return $fallback;
        }

        $missing = $this->translator->get(self::MISSING_KEY, [], self::DEFAULT_LOCALE);

        return is_string($missing) ? $missing : self::MISSING_KEY;
    }

    /**
     * @param  array<string, scalar|null>  $replace
     */
    public function translate(string $key, string $locale, array $replace = []): string
    {
        $translation = $this->get($key, $locale);

        $replacements = [];
        foreach ($replace as $name => $value) {
            $replacements[':'.$name] = (string) $value;
        }

        return strtr($translation, $replacements);
    }

    private function isResolved(mixed $translation, string $key): bool
    {
        return is_string($translation) && $translation !== $key;
    }
}
