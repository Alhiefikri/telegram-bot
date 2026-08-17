<?php

namespace App\Services\Telegram;

use FilesystemIterator;
use Illuminate\Contracts\Translation\Translator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TelegramTranslationCatalog
{
    /** @var list<string> */
    public const SUPPORTED_LOCALES = ['id', 'en', 'ms'];

    private const DEFAULT_LOCALE = 'en';

    private const MISSING_KEY = 'translation.missing';

    /** @var list<string> */
    private const ENGLISH_ONLY_KEYS = [
        'notification.store_channel.cta',
        'notification.store_channel.restock.added',
        'notification.store_channel.restock.available',
        'notification.store_channel.restock.binance',
        'notification.store_channel.restock.explanation',
        'notification.store_channel.restock.follow_up',
        'notification.store_channel.restock.price',
        'notification.store_channel.restock.title',
        'notification.store_channel.sold_out.confirmation',
        'notification.store_channel.sold_out.follow_up',
        'notification.store_channel.sold_out.label',
        'notification.store_channel.sold_out.thanks',
        'notification.store_channel.sold_out.unavailable',
    ];

    /** @var list<string> */
    private const INDONESIAN_STORE_CHANNEL_PHRASES = [
        'Stok masuk',
        'Stok tersedia sekarang',
        'Harga QRIS',
        'Jangan sampai kehabisan lagi',
        'TERJUAL HABIS',
        'Stok terakhir',
    ];

    private const CATALOG_KEY_PATTERN = '/^(?:translation|router|language|welcome|menu|maintenance|support|account|status|community_gate|privacy|callback|catalog|search|cart|checkout|payment|date|order|receipt|notification)\.[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*$/';

    /** @var list<string> */
    private const NON_CATALOG_SOURCE_LITERALS = ['order.customer'];

    public function __construct(private Translator $translator) {}

    public function get(string $key, string $locale): string
    {
        $translation = $this->translator->get($key, [], $locale, false);
        if ($this->isResolved($translation, $key)) {
            return $translation;
        }

        $fallback = $this->translator->get($key, [], self::DEFAULT_LOCALE, false);
        if ($this->isResolved($fallback, $key)) {
            return $fallback;
        }

        $missing = $this->translator->get(self::MISSING_KEY, [], self::DEFAULT_LOCALE, false);

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

    /**
     * Validate the on-disk customer catalog without relying on Laravel's
     * fallback resolution, which would hide missing locale entries.
     *
     * @return list<string>
     */
    public function audit(): array
    {
        $catalogs = [];
        $errors = [];

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $path = lang_path($locale.'.json');
            if (! is_file($path)) {
                $errors[] = "Missing translation catalog: {$path}";

                continue;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                $errors[] = "Unable to read translation catalog: {$path}";

                continue;
            }

            try {
                $catalog = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $errors[] = "Invalid JSON in {$path}: {$exception->getMessage()}";

                continue;
            }

            if (! is_array($catalog)) {
                $errors[] = "Translation catalog must contain an object: {$path}";

                continue;
            }

            $catalogs[$locale] = $catalog;
        }

        if (! isset($catalogs[self::DEFAULT_LOCALE])) {
            return $errors;
        }

        return array_values(array_unique([
            ...$errors,
            ...$this->auditCatalogs($catalogs, $this->referencedKeys()),
        ]));
    }

    /**
     * @param  array<string, array<string, mixed>>  $catalogs
     * @param  list<string>  $referencedKeys
     * @return list<string>
     */
    public function auditCatalogs(array $catalogs, array $referencedKeys = []): array
    {
        $errors = [];

        $keys = [];
        foreach ($catalogs as $catalog) {
            foreach (array_keys($catalog) as $key) {
                $keys[(string) $key] = true;
            }
        }
        foreach ($referencedKeys as $key) {
            if (! isset($keys[$key])) {
                $errors[] = "Referenced translation key {$key} is missing from the English catalog.";
                $keys[$key] = true;
            }
        }
        ksort($keys);

        foreach (array_keys($keys) as $key) {
            if (! array_key_exists($key, $catalogs[self::DEFAULT_LOCALE])) {
                $errors[] = "Key {$key} is not defined in the English catalog.";

                continue;
            }

            $english = $catalogs[self::DEFAULT_LOCALE][$key];
            if (! is_string($english) || trim($english) === '') {
                $errors[] = "Key {$key} must have a non-empty English value.";

                continue;
            }

            foreach (self::SUPPORTED_LOCALES as $locale) {
                if (! array_key_exists($locale, $catalogs)) {
                    continue;
                }

                if (! array_key_exists($key, $catalogs[$locale])) {
                    if ($locale !== self::DEFAULT_LOCALE && in_array($key, self::ENGLISH_ONLY_KEYS, true)) {
                        continue;
                    }

                    $errors[] = "Missing {$locale} translation for key {$key}.";

                    continue;
                }

                $translation = $catalogs[$locale][$key];
                if (! is_string($translation) || trim($translation) === '') {
                    $errors[] = "Key {$key} must have a non-empty {$locale} value.";

                    continue;
                }

                if ($locale !== self::DEFAULT_LOCALE && $this->placeholders($english) !== $this->placeholders($translation)) {
                    $errors[] = "Placeholder mismatch for key {$key} in {$locale}.";
                }

                if ($locale !== 'id' && str_starts_with($key, 'notification.store_channel.')) {
                    foreach (self::INDONESIAN_STORE_CHANNEL_PHRASES as $phrase) {
                        if (str_contains(mb_strtolower($translation), mb_strtolower($phrase))) {
                            $errors[] = "Indonesian Store Channel phrase {$phrase} found in {$locale} key {$key}.";
                        }
                    }
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Find literal catalog keys used by the Telegram presentation layer so a
     * key removed from every JSON file cannot disappear from the audit.
     *
     * @return list<string>
     */
    private function referencedKeys(): array
    {
        $directory = app_path('Services/Telegram');
        if (! is_dir($directory)) {
            return [];
        }

        $keys = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            foreach ($this->sourceCatalogKeys($contents) as $key) {
                $keys[$key] = true;
            }
        }

        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function sourceCatalogKeys(string $contents): array
    {
        $tokens = token_get_all($contents);
        $keys = [];

        foreach ($tokens as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = stripcslashes(substr($token[1], 1, -1));
            if (in_array($literal, self::NON_CATALOG_SOURCE_LITERALS, true)) {
                continue;
            }
            if (preg_match(self::CATALOG_KEY_PATTERN, $literal) === 1) {
                $keys[$literal] = true;
            }
        }

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $keys['language.option.'.$locale] = true;
        }
        for ($month = 1; $month <= 12; $month++) {
            $keys['date.month.'.$month] = true;
        }
        foreach (['expired', 'creating', 'recovery_pending', 'total_pending', 'invalid', 'ready'] as $suffix) {
            $keys['payment.klikqris.retry.'.$suffix.($suffix === 'expired' ? '_title' : '_lead')] = true;
            $keys['payment.klikqris.retry.'.$suffix.'_body'] = true;
        }

        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $value): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $value, $matches);
        $placeholders = $matches[0] ?? [];
        sort($placeholders);

        return $placeholders;
    }

    private function isResolved(mixed $translation, string $key): bool
    {
        return is_string($translation) && $translation !== $key;
    }
}
