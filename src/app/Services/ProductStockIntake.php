<?php

namespace App\Services;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\InventoryMovementType;
use App\Exceptions\StructuredStockInputDisabled;
use App\Jobs\SendProductStockNotifications;
use App\Models\CredentialIdentity;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductStockIntakeOperation;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\Credentials\CredentialIdentityBackfillReadinessService;
use App\Services\Credentials\CredentialIdentityFingerprint;
use App\Services\Credentials\CredentialIdentityKind;
use App\Services\Credentials\LegacyCredentialIdentityParser;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\Telegram\StockNotificationDeliveryPipeline;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ProductStockIntake
{
    private const MAX_STRUCTURED_ITEMS = 20;

    private const MAX_LEGACY_CHARACTERS = 5000;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly StockNotificationDeliveryPipeline $stockNotifications,
        private readonly ProductWaitlistNotificationService $waitlistNotifications,
        private readonly CredentialDeliveryFormatter $credentialFormatter,
        private readonly CredentialIdentityFingerprint $fingerprints,
        private readonly CredentialIdentityBackfillReadinessService $identityBackfillReadiness,
        private readonly LegacyCredentialIdentityParser $legacyIdentityParser,
        private readonly AccountInputReceiptPreview $receiptPreview,
    ) {}

    /**
     * Add one all-or-nothing positive stock submission.
     *
     * @throws ValidationException when the complete submission is invalid.
     * @throws ProductStockIntakeIdempotencyConflict when a key is reused with
     *                                               a different Product or payload.
     */
    public function submit(
        Product $product,
        ProductStockIntakeSubmission|array $submission,
        ?User $actor = null,
        ?string $ip = null,
        bool $dispatchNotifications = true,
    ): ProductStockIntakeResult {
        $submission = is_array($submission)
            ? ProductStockIntakeSubmission::fromArray($submission)
            : $submission;
        $this->assertActorMayAdjustStock($actor);

        if ($submission->usesStructuredCredentials()) {
            $this->assertStructuredInputEnabled();
        }

        $normalized = $this->normalizeAndValidate($submission);
        $payloadHash = $this->payloadHash($submission, $normalized['canonical_items']);

        try {
            return DB::transaction(function () use ($product, $submission, $actor, $ip, $dispatchNotifications, $normalized, $payloadHash): ProductStockIntakeResult {
                $existing = ProductStockIntakeOperation::query()
                    ->where('operation_key', $submission->operationKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $this->assertSameOperation($existing, $product, $submission, $payloadHash);

                    return $this->resultFromOperation($existing, true);
                }

                if ($submission->usesStructuredCredentials()) {
                    $this->assertFingerprintReady();
                }

                /** @var Product $lockedProduct */
                $lockedProduct = Product::query()
                    ->whereKey($product->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $itemCount = count($normalized['items']);
                $availableBefore = $lockedProduct->availableStock();
                $stockBefore = (int) $lockedProduct->stock;
                $stockAfter = $stockBefore + $itemCount;

                if ($stockAfter < $lockedProduct->reserved_stock) {
                    throw new \InvalidArgumentException('Resulting stock must be non-negative and at least reserved stock.');
                }

                $operation = ProductStockIntakeOperation::query()->create([
                    'operation_key' => $submission->operationKey,
                    'product_id' => $lockedProduct->id,
                    'actor_id' => $actor?->getKey(),
                    'payload_hash' => $payloadHash,
                    'mode' => $submission->mode,
                    'item_count' => $itemCount,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                    'available_before' => $availableBefore,
                    'available_after' => 0,
                    'ip_address' => $ip,
                ]);

                foreach ($normalized['items'] as $item) {
                    $stockItem = $this->createStockItem($lockedProduct, $item);
                    $this->claimIdentities($lockedProduct, $stockItem, $item);
                }

                $lockedProduct->forceFill(['stock' => $stockAfter])->save();
                $availableAfter = $lockedProduct->refresh()->availableStock();

                $movement = InventoryMovement::query()->create([
                    'product_id' => $lockedProduct->id,
                    'type' => InventoryMovementType::Adjustment,
                    'quantity' => $itemCount,
                    'stock_before' => $stockBefore,
                    'available_before' => $availableBefore,
                    'available_after' => $availableAfter,
                    'notes' => $this->movementNotes($submission, $itemCount),
                    'created_at' => now(),
                ]);

                $this->auditLogger->log(
                    'product.stock_adjusted',
                    $lockedProduct,
                    [
                        'stock' => $stockBefore,
                        'reserved_stock' => $lockedProduct->reserved_stock,
                    ],
                    [
                        'stock' => $stockAfter,
                        'reserved_stock' => $lockedProduct->reserved_stock,
                        'delta' => $itemCount,
                        'item_count' => $itemCount,
                        'credential_format' => $submission->isStructuredAccount()
                            ? CredentialFormat::StructuredAccount->value
                            : CredentialFormat::Legacy->value,
                    ],
                    $actor,
                    $ip,
                );

                $operation->forceFill([
                    'inventory_movement_id' => $movement->id,
                    'available_after' => $availableAfter,
                ])->save();

                if ($itemCount > 0) {
                    $this->stockNotifications->materialize($movement->id);
                    $this->waitlistNotifications->scheduleForMovement($movement->id);
                    if ($dispatchNotifications) {
                        DB::afterCommit(fn (): mixed => SendProductStockNotifications::dispatch($movement->id)
                            ->onQueue(StockNotificationDeliveryPipeline::QUEUE));
                    }
                }

                return new ProductStockIntakeResult(
                    productId: (int) $lockedProduct->id,
                    movementId: (int) $movement->id,
                    itemCount: $itemCount,
                    stockBefore: $stockBefore,
                    stockAfter: $stockAfter,
                    availableBefore: $availableBefore,
                    availableAfter: $availableAfter,
                );
            }, attempts: 5);
        } catch (ProductStockIntakeIdentityConflict $conflict) {
            throw $this->validationForIdentityConflict($submission, $conflict);
        } catch (QueryException $exception) {
            $existing = ProductStockIntakeOperation::query()
                ->where('operation_key', $submission->operationKey)
                ->first();
            if ($existing !== null) {
                $this->assertSameOperation($existing, $product, $submission, $payloadHash);

                return $this->resultFromOperation($existing, true);
            }

            $conflict = $this->findRegisteredIdentityConflict($normalized['items']);
            if ($conflict !== null) {
                throw $this->validationForIdentityConflict($submission, $conflict);
            }

            throw $exception;
        }
    }

    public function intake(
        Product $product,
        ProductStockIntakeSubmission|array $submission,
        ?User $actor = null,
        ?string $ip = null,
        bool $dispatchNotifications = true,
    ): ProductStockIntakeResult {
        return $this->submit($product, $submission, $actor, $ip, $dispatchNotifications);
    }

    /**
     * Perform the non-mutating Account Input review used by the admin UI.
     *
     * This intentionally does not reserve identities or create an operation
     * row. It reports the current registry view, while submit() repeats the
     * same checks and unique claims authoritatively inside its transaction.
     *
     * @throws ValidationException when the submission is structurally invalid.
     */
    public function review(
        Product $product,
        ProductStockIntakeSubmission|array $submission,
        ?User $actor = null,
        ?string $buyerLocale = null,
    ): ProductStockIntakeReview {
        $submission = is_array($submission)
            ? ProductStockIntakeSubmission::fromArray($submission)
            : $submission;

        $this->assertActorMayAdjustStock($actor);

        if (! $submission->isStructuredAccount()) {
            throw ValidationException::withMessages([
                'accounts' => ['Input review hanya tersedia untuk akun terstruktur.'],
            ]);
        }

        $this->assertStructuredInputEnabled();
        $normalized = $this->normalizeAndValidate($submission, rejectSubmissionDuplicates: false);
        $registeredDuplicates = $this->registeredIdentityKinds($normalized['items']);
        $accounts = [];
        $duplicateCount = 0;

        foreach ($normalized['items'] as $position => $item) {
            /** @var StructuredCredentialPayload $payload */
            $payload = $item['payload'];
            $index = $item['index'];
            $submissionKinds = array_keys($normalized['submission_duplicates'][$index] ?? []);
            $registryKinds = array_keys($registeredDuplicates[$index] ?? []);
            $duplicateKinds = array_values(array_unique([...$submissionKinds, ...$registryKinds]));
            $duplicateFields = array_values(array_unique(array_map(
                fn (string $kind): string => $this->identityField($kind),
                $duplicateKinds,
            )));
            $duplicateSources = [];
            if ($submissionKinds !== []) {
                $duplicateSources[] = 'submission';
            }
            if ($registryKinds !== []) {
                $duplicateSources[] = 'registry';
            }
            $hasDuplicate = $duplicateKinds !== [];
            if ($hasDuplicate) {
                $duplicateCount++;
            }

            $accounts[] = [
                'index' => $index,
                'account_number' => $position + 1,
                'email_masked' => $this->maskEmail($payload->email),
                'access_method' => $payload->accessMethod->value,
                'otp_domain' => $payload->otpMailDomain(),
                'duplicate_status' => $hasDuplicate ? 'duplicate' : 'clear',
                'duplicate_fields' => $duplicateFields,
                'duplicate_sources' => $duplicateSources,
            ];
        }

        return new ProductStockIntakeReview(
            operationKey: $submission->operationKey,
            status: $duplicateCount > 0 ? 'duplicate' : 'ready',
            accountCount: count($accounts),
            duplicateCount: $duplicateCount,
            accounts: $accounts,
            receiptPreview: $this->receiptPreview->metadata(
                $product,
                array_map(
                    static fn (array $item): StructuredCredentialPayload => $item['payload'],
                    $normalized['items'],
                ),
                $buyerLocale,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function submitStructured(
        Product $product,
        array $input,
        ?User $actor = null,
        ?string $ip = null,
    ): ProductStockIntakeResult {
        return $this->submit(
            $product,
            ProductStockIntakeSubmission::structured(
                $input['accounts'] ?? ($input['items'] ?? []),
                isset($input['operation_key']) ? (string) $input['operation_key'] : null,
            ),
            $actor,
            $ip,
        );
    }

    public function structuredInputEnabled(): bool
    {
        return $this->identityBackfillReadiness->isReady();
    }

    /**
     * The persisted identity registry readiness is checked before credential-
     * bearing normalization so an incomplete backfill cannot accept new input.
     */
    public function assertStructuredInputEnabled(): void
    {
        if (! $this->structuredInputEnabled()) {
            throw new StructuredStockInputDisabled;
        }
    }

    /**
     * @return array{
     *     items:list<array{index:int, format:CredentialFormat, secret:?string, payload:?StructuredCredentialPayload, identities:list<array{kind:string,value:string}>}>,
     *     canonical_items:list<array<string,mixed>>,
     *     submission_duplicates:array<int,array<string,bool>>
     * }
     */
    private function normalizeAndValidate(ProductStockIntakeSubmission $submission, bool $rejectSubmissionDuplicates = true): array
    {
        return match (true) {
            $submission->isBulkMfaSecret() => $this->normalizeBulkMfaSecret($submission, $rejectSubmissionDuplicates),
            $submission->isBulkAccessToken() => $this->normalizeBulkAccessToken($submission, $rejectSubmissionDuplicates),
            $submission->isBulkReaderMailLink() => $this->normalizeBulkReaderMailLink($submission, $rejectSubmissionDuplicates),
            $submission->isLegacy() => $this->normalizeLegacy($submission, $rejectSubmissionDuplicates),
            default => $this->normalizeStructured($submission, $rejectSubmissionDuplicates),
        };
    }

    /**
     * @return array{
     *     items:list<array{index:int, format:CredentialFormat, secret:?string, payload:?StructuredCredentialPayload, identities:list<array{kind:string,value:string}>}>,
     *     canonical_items:list<array<string,mixed>>,
     *     submission_duplicates:array<int,array<string,bool>>
     * }
     */
    private function normalizeLegacy(ProductStockIntakeSubmission $submission, bool $rejectSubmissionDuplicates = true): array
    {
        $errors = [];
        $items = [];
        $canonicalItems = [];
        $rawLength = 0;

        foreach ($submission->items() as $index => $value) {
            if (! is_string($value)) {
                $errors["stock_items.{$index}"][] = 'Isi stok harus berupa teks.';

                continue;
            }

            $secret = trim($value);
            if ($secret === '') {
                continue;
            }
            $rawLength += strlen($secret) + ($rawLength === 0 ? 0 : 1);
            $identities = $this->legacyIdentities($secret);
            $items[] = [
                'index' => (int) $index,
                'format' => CredentialFormat::Legacy,
                'secret' => $secret,
                'payload' => null,
                'identities' => $identities,
            ];
            $canonicalItems[] = ['secret' => $secret];
        }

        if ($rawLength > self::MAX_LEGACY_CHARACTERS) {
            $errors['stock_items'][] = 'Catatan stok maksimal 5000 karakter.';
        }
        if ($items === []) {
            $errors['stock_items'][] = 'Isi minimal satu baris stok.';
        }

        $submissionDuplicates = $this->submissionDuplicateKinds($items);
        if ($rejectSubmissionDuplicates) {
            $this->appendSubmissionDuplicateErrors($errors, $submissionDuplicates, structured: false);
        }
        $this->throwIfInvalid($errors);

        return [
            'items' => $items,
            'canonical_items' => $canonicalItems,
            'submission_duplicates' => $submissionDuplicates,
        ];
    }

    /**
     * The selected MFA bulk format keeps the existing legacy storage
     * contract, but rejects malformed triplets before they can become stock
     * rows.
     *
     * @return array{
     *     items:list<array{index:int, format:CredentialFormat, secret:?string, payload:?StructuredCredentialPayload, identities:list<array{kind:string,value:string}>}>,
     *     canonical_items:list<array<string,mixed>>,
     *     submission_duplicates:array<int,array<string,bool>>
     * }
     */
    private function normalizeBulkMfaSecret(ProductStockIntakeSubmission $submission, bool $rejectSubmissionDuplicates = true): array
    {
        $errors = [];
        $items = [];
        $canonicalItems = [];
        $rawLength = 0;

        foreach ($submission->items() as $index => $value) {
            if (! is_string($value)) {
                $errors["stock_items.{$index}"][] = 'Isi stok harus berupa teks.';

                continue;
            }

            $secret = trim($value);
            if ($secret === '') {
                continue;
            }

            $rawLength += strlen($secret) + ($rawLength === 0 ? 0 : 1);
            $parts = $this->bulkTripletParts($secret);
            if ($parts === null) {
                $errors["stock_items.{$index}"][] = 'Format baris harus email|password|MFA secret.';

                continue;
            }

            $items[] = [
                'index' => (int) $index,
                'format' => CredentialFormat::Legacy,
                'secret' => $secret,
                'payload' => null,
                'identities' => $this->legacyIdentities($secret),
            ];
            $canonicalItems[] = ['secret' => $secret];
        }

        if ($rawLength > self::MAX_LEGACY_CHARACTERS) {
            $errors['stock_items'][] = 'Catatan stok maksimal 5000 karakter.';
        }
        if ($items === []) {
            $errors['stock_items'][] = 'Isi minimal satu baris stok.';
        }

        $submissionDuplicates = $this->submissionDuplicateKinds($items);
        if ($rejectSubmissionDuplicates) {
            $this->appendSubmissionDuplicateErrors($errors, $submissionDuplicates, structured: false);
        }
        $this->throwIfInvalid($errors);

        return [
            'items' => $items,
            'canonical_items' => $canonicalItems,
            'submission_duplicates' => $submissionDuplicates,
        ];
    }

    /**
     * The reader-mail-link bulk format creates structured OTP Mail Link
     * credentials while keeping the URL as the customer-delivered value.
     *
     * @return array{
     *     items:list<array{index:int, format:CredentialFormat, secret:?string, payload:?StructuredCredentialPayload, identities:list<array{kind:string,value:string}>}>,
     *     canonical_items:list<array<string,mixed>>,
     *     submission_duplicates:array<int,array<string,bool>>
     * }
     */
    private function normalizeBulkReaderMailLink(ProductStockIntakeSubmission $submission, bool $rejectSubmissionDuplicates = true): array
    {
        $errors = [];
        $items = [];
        $canonicalItems = [];
        $rawLength = 0;

        foreach ($submission->items() as $index => $value) {
            if (! is_string($value)) {
                $errors["stock_items.{$index}"][] = 'Isi stok harus berupa teks.';

                continue;
            }

            $secret = trim($value);
            if ($secret === '') {
                continue;
            }

            $rawLength += strlen($secret) + ($rawLength === 0 ? 0 : 1);
            $parts = $this->bulkReaderMailLinkParts($secret);
            if ($parts === null) {
                $errors["stock_items.{$index}"][] = 'Format baris harus email|password|token|link atau email|password|email|password|token|UUID|link.';

                continue;
            }

            try {
                $payload = StructuredCredentialPayload::fromValues(
                    $parts['email'],
                    $parts['password'],
                    CredentialAccessMethod::AuthToken,
                    $parts['access_token'],
                    null,
                    $parts['reader_mail_link'],
                );
            } catch (Throwable) {
                $errors["stock_items.{$index}"][] = 'Akses token atau link reader mail pada baris tidak valid.';

                continue;
            }

            $items[] = [
                'index' => (int) $index,
                'format' => CredentialFormat::StructuredAccount,
                'secret' => null,
                'payload' => $payload,
                'identities' => $payload->identityValues(),
            ];
            $canonicalItems[] = $payload->toArray();
        }

        if ($rawLength > self::MAX_LEGACY_CHARACTERS) {
            $errors['stock_items'][] = 'Catatan stok maksimal 5000 karakter.';
        }
        if ($items === []) {
            $errors['stock_items'][] = 'Isi minimal satu baris stok.';
        }

        $submissionDuplicates = $this->submissionDuplicateKinds($items);
        if ($rejectSubmissionDuplicates) {
            $this->appendSubmissionDuplicateErrors($errors, $submissionDuplicates, structured: false);
        }
        $this->throwIfInvalid($errors);

        return [
            'items' => $items,
            'canonical_items' => $canonicalItems,
            'submission_duplicates' => $submissionDuplicates,
        ];
    }

    /**
     * The access-token bulk format is promoted to a structured credential so
     * delivery can label and mark the token separately from an MFA secret.
     *
     * @return array{
     *     items:list<array{index:int, format:CredentialFormat, secret:?string, payload:?StructuredCredentialPayload, identities:list<array{kind:string,value:string}>}>,
     *     canonical_items:list<array<string,mixed>>,
     *     submission_duplicates:array<int,array<string,bool>>
     * }
     */
    private function normalizeBulkAccessToken(ProductStockIntakeSubmission $submission, bool $rejectSubmissionDuplicates = true): array
    {
        $errors = [];
        $items = [];
        $canonicalItems = [];
        $rawLength = 0;

        foreach ($submission->items() as $index => $value) {
            if (! is_string($value)) {
                $errors["stock_items.{$index}"][] = 'Isi stok harus berupa teks.';

                continue;
            }

            $secret = trim($value);
            if ($secret === '') {
                continue;
            }

            $rawLength += strlen($secret) + ($rawLength === 0 ? 0 : 1);
            $parts = $this->bulkAccessTokenParts($secret);
            if ($parts === null) {
                $errors["stock_items.{$index}"][] = 'Format baris harus email|password|akses token.';

                continue;
            }

            try {
                $payload = StructuredCredentialPayload::fromValues(
                    $parts['email'],
                    $parts['password'],
                    CredentialAccessMethod::AuthToken,
                    $parts['access_token'],
                );
            } catch (Throwable) {
                $errors["stock_items.{$index}"][] = 'Nilai akses token pada baris tidak valid.';

                continue;
            }

            $items[] = [
                'index' => (int) $index,
                'format' => CredentialFormat::StructuredAccount,
                'secret' => null,
                'payload' => $payload,
                'identities' => $payload->identityValues(),
            ];
            $canonicalItems[] = $payload->toArray();
        }

        if ($rawLength > self::MAX_LEGACY_CHARACTERS) {
            $errors['stock_items'][] = 'Catatan stok maksimal 5000 karakter.';
        }
        if ($items === []) {
            $errors['stock_items'][] = 'Isi minimal satu baris stok.';
        }

        $submissionDuplicates = $this->submissionDuplicateKinds($items);
        if ($rejectSubmissionDuplicates) {
            $this->appendSubmissionDuplicateErrors($errors, $submissionDuplicates, structured: false);
        }
        $this->throwIfInvalid($errors);

        return [
            'items' => $items,
            'canonical_items' => $canonicalItems,
            'submission_duplicates' => $submissionDuplicates,
        ];
    }

    /**
     * @return array{email:string,password:string,mfa:string}|null
     */
    private function bulkTripletParts(string $secret): ?array
    {
        return $this->credentialFormatter->parts($secret);
    }

    /**
     * Access-token values may themselves be followed by pipe-delimited
     * provider context. Only the first two separators belong to the bulk
     * envelope; the complete remainder stays copyable as one value.
     *
     * @return array{email:string,password:string,access_token:string}|null
     */
    private function bulkAccessTokenParts(string $secret): ?array
    {
        $parts = explode('|', $secret, 3);
        $email = trim((string) ($parts[0] ?? ''));
        $password = (string) ($parts[1] ?? '');
        $accessToken = (string) ($parts[2] ?? '');

        if (count($parts) !== 3
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || trim($password) === ''
            || trim($accessToken) === ''
            || strpbrk($email.$password.$accessToken, "\r\n") !== false) {
            return null;
        }

        return [
            'email' => $email,
            'password' => $password,
            'access_token' => $accessToken,
        ];
    }

    /**
     * Accept both the compact four-field format and the common exported
     * seven-field format:
     * email|password|email|password|token|uuid|reader-link.
     *
     * @return array{email:string,password:string,access_token:string,reader_mail_link:string}|null
     */
    private function bulkReaderMailLinkParts(string $secret): ?array
    {
        $parts = explode('|', $secret);
        if (count($parts) === 4) {
            [$email, $password, $accessToken, $readerMailLink] = $parts;
        } elseif (count($parts) === 7
            && trim((string) $parts[0]) === trim((string) $parts[2])
            && (string) $parts[1] === (string) $parts[3]
            && Str::isUuid(trim((string) $parts[5]))) {
            [$email, $password, $accessToken, $readerMailLink] = [
                $parts[0],
                $parts[1],
                $parts[4],
                $parts[6],
            ];
        } else {
            return null;
        }

        $email = trim((string) $email);
        $password = (string) $password;
        $accessToken = (string) $accessToken;
        $readerMailLink = trim((string) $readerMailLink);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || trim($password) === ''
            || trim($accessToken) === ''
            || $readerMailLink === ''
            || strpbrk($email.$password.$accessToken.$readerMailLink, "\r\n") !== false) {
            return null;
        }

        return [
            'email' => $email,
            'password' => $password,
            'access_token' => $accessToken,
            'reader_mail_link' => $readerMailLink,
        ];
    }

    /**
     * @return array{
     *     items:list<array{index:int, format:CredentialFormat, secret:?string, payload:?StructuredCredentialPayload, identities:list<array{kind:string,value:string}>}>,
     *     canonical_items:list<array<string,mixed>>,
     *     submission_duplicates:array<int,array<string,bool>>
     * }
     */
    private function normalizeStructured(ProductStockIntakeSubmission $submission, bool $rejectSubmissionDuplicates = true): array
    {
        $errors = [];
        $items = [];
        $canonicalItems = [];

        if (count($submission->items()) < 1 || count($submission->items()) > self::MAX_STRUCTURED_ITEMS) {
            $errors['accounts'][] = 'Input Akun harus berisi 1 sampai 20 akun.';
        }

        foreach ($submission->items() as $index => $input) {
            $fieldPrefix = "accounts.{$index}";
            if (! is_array($input)) {
                $errors[$fieldPrefix][] = 'Data akun tidak valid.';

                continue;
            }

            $email = $input['email'] ?? null;
            $password = $input['password'] ?? null;
            $accessMethod = $input['access_method'] ?? null;
            $accessValue = $input['access_value'] ?? null;
            $tutorialOverride = $input['tutorial_override'] ?? null;
            $accessMethodValue = $accessMethod instanceof CredentialAccessMethod
                ? $accessMethod->value
                : $accessMethod;

            if (! is_string($email) || trim($email) === '') {
                $errors["{$fieldPrefix}.email"][] = 'Email wajib diisi.';
            } elseif (strlen($email) > 254) {
                $errors["{$fieldPrefix}.email"][] = 'Email maksimal 254 karakter.';
            } elseif (filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
                $errors["{$fieldPrefix}.email"][] = 'Email tidak valid.';
            }
            if (! is_string($password) || trim($password) === '') {
                $errors["{$fieldPrefix}.password"][] = 'Password wajib diisi.';
            } elseif (mb_strlen($password) > 1024) {
                $errors["{$fieldPrefix}.password"][] = 'Password maksimal 1024 karakter.';
            }
            if (! is_string($accessMethodValue) || ! in_array($accessMethodValue, ['none', 'otp_mail_link', 'auth_token'], true)) {
                $errors["{$fieldPrefix}.access_method"][] = 'Metode akses tidak valid.';
            }
            if ($accessValue !== null && ! is_string($accessValue)) {
                $errors["{$fieldPrefix}.access_value"][] = 'Nilai metode akses tidak valid.';
            }
            if (! is_string($tutorialOverride) && $tutorialOverride !== null) {
                $errors["{$fieldPrefix}.tutorial_override"][] = 'Tutorial tambahan tidak valid.';
            } elseif (is_string($tutorialOverride) && trim($tutorialOverride) !== '') {
                $errors["{$fieldPrefix}.tutorial_override"][] = 'Tutorial akun sekarang mengikuti panduan Product.';
            }

            if (isset($accessMethodValue) && is_string($accessMethodValue)) {
                $this->validateAccessFields($errors, $fieldPrefix, $accessMethodValue, $accessValue);
            }

            if (isset($errors[$fieldPrefix.'.email'])
                || isset($errors[$fieldPrefix.'.password'])
                || isset($errors[$fieldPrefix.'.access_method'])
                || isset($errors[$fieldPrefix.'.access_value'])
                || isset($errors[$fieldPrefix.'.tutorial_override'])) {
                continue;
            }

            try {
                $payload = StructuredCredentialPayload::fromValues(
                    $email,
                    $password,
                    $accessMethod instanceof CredentialAccessMethod ? $accessMethod : (string) $accessMethodValue,
                    $accessValue,
                    null,
                );
            } catch (Throwable) {
                $this->appendStructuredPayloadErrors($errors, $fieldPrefix, $email, $accessMethodValue, $accessValue, null);

                continue;
            }

            $identities = $payload->identityValues();
            $items[] = [
                'index' => (int) $index,
                'format' => CredentialFormat::StructuredAccount,
                'secret' => null,
                'payload' => $payload,
                'identities' => $identities,
            ];
            $canonicalItems[] = $payload->toArray();
        }

        $submissionDuplicates = $this->submissionDuplicateKinds($items);
        if ($rejectSubmissionDuplicates) {
            $this->appendSubmissionDuplicateErrors($errors, $submissionDuplicates, structured: true);
        }
        $this->throwIfInvalid($errors);

        return [
            'items' => $items,
            'canonical_items' => $canonicalItems,
            'submission_duplicates' => $submissionDuplicates,
        ];
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validateAccessFields(array &$errors, string $fieldPrefix, string $accessMethod, mixed $accessValue): void
    {
        if ($accessMethod === 'none') {
            if ($accessValue !== null && (! is_string($accessValue) || trim($accessValue) !== '')) {
                $errors["{$fieldPrefix}.access_value"][] = 'Nilai metode akses harus kosong saat memilih Tidak ada.';
            }

            return;
        }

        if (! is_string($accessValue) || trim($accessValue) === '') {
            $errors["{$fieldPrefix}.access_value"][] = 'Nilai metode akses wajib diisi.';

            return;
        }

        if ($accessMethod === 'otp_mail_link' && mb_strlen($accessValue) > 2048) {
            $errors["{$fieldPrefix}.access_value"][] = 'OTP Mail Link maksimal 2048 karakter.';
        }
        if ($accessMethod === 'auth_token' && mb_strlen($accessValue) > 3500) {
            $errors["{$fieldPrefix}.access_value"][] = 'Auth Token maksimal 3500 karakter.';
        }
    }

    /**
     * Keep this mapping deliberately generic. StructuredCredentialPayload
     * validates the exact rule, while this layer owns item-indexed errors.
     *
     * @param  array<string, list<string>>  $errors
     */
    private function appendStructuredPayloadErrors(array &$errors, string $fieldPrefix, mixed $email, mixed $accessMethod, mixed $accessValue, mixed $tutorialOverride): void
    {
        if (! is_string($email) || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            $errors["{$fieldPrefix}.email"][] = 'Email tidak valid.';
        }
        if (! is_string($accessMethod) || ! in_array($accessMethod, ['none', 'otp_mail_link', 'auth_token'], true)) {
            $errors["{$fieldPrefix}.access_method"][] = 'Metode akses tidak valid.';
        }
        if ($accessMethod === 'otp_mail_link') {
            $errors["{$fieldPrefix}.access_value"][] = 'OTP Mail Link harus berupa URL HTTPS yang aman.';
        } elseif ($accessMethod === 'auth_token' && (! is_string($accessValue) || trim($accessValue) === '')) {
            $errors["{$fieldPrefix}.access_value"][] = 'Auth Token wajib diisi.';
        }
        if ($tutorialOverride !== null && (! is_string($tutorialOverride) || trim($tutorialOverride) !== '')) {
            $errors["{$fieldPrefix}.tutorial_override"][] = 'Tutorial akun sekarang mengikuti panduan Product.';
        }
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<int,array<string,bool>>  $duplicates
     */
    private function appendSubmissionDuplicateErrors(array &$errors, array $duplicates, bool $structured): void
    {
        foreach ($duplicates as $index => $kinds) {
            foreach (array_keys($kinds) as $kind) {
                $this->addDuplicateError($errors, (int) $index, $kind, $structured);
            }
        }
    }

    /** @return array<int,array<string,bool>> */
    private function submissionDuplicateKinds(array $items): array
    {
        /** @var array<string, array{index:int, kind:string}> $seen */
        $seen = [];
        /** @var array<int,array<string,bool>> $duplicates */
        $duplicates = [];

        foreach ($items as $item) {
            foreach ($item['identities'] as $identity) {
                $claimKey = $identity['kind'].':'.$identity['value'];
                if (! isset($seen[$claimKey])) {
                    $seen[$claimKey] = ['index' => $item['index'], 'kind' => $identity['kind']];

                    continue;
                }

                $previous = $seen[$claimKey];
                $duplicates[$previous['index']][$previous['kind']] = true;
                $duplicates[$item['index']][$identity['kind']] = true;
            }
        }

        return $duplicates;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function addDuplicateError(array &$errors, int $index, string $kind, bool $structured): void
    {
        $field = $kind === CredentialIdentityKind::AuthToken ? 'access_value' : 'email';
        $prefix = $structured ? 'accounts' : 'stock_items';
        $errors["{$prefix}.{$index}.{$field}"][] = 'Identitas kredensial duplikat dalam pengajuan.';
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function throwIfInvalid(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $canonicalItems
     */
    private function payloadHash(ProductStockIntakeSubmission $submission, array $canonicalItems): string
    {
        $encoded = json_encode([
            'mode' => $submission->mode,
            'items' => $canonicalItems,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $key = (string) config('app.key');

        return hash_hmac('sha256', $encoded, $key !== '' ? $key : 'product-stock-intake-operation');
    }

    /**
     * @param  array{index:int, format:CredentialFormat, secret:?string, payload:?StructuredCredentialPayload, identities:list<array{kind:string,value:string}>}  $item
     */
    private function createStockItem(Product $product, array $item): ProductStockItem
    {
        if ($item['format'] === CredentialFormat::StructuredAccount) {
            return ProductStockItem::query()->create([
                'product_id' => $product->id,
                'credential_format' => CredentialFormat::StructuredAccount,
                'credential_payload' => $item['payload']?->toArray(),
                'secret' => null,
            ]);
        }

        return ProductStockItem::query()->create([
            'product_id' => $product->id,
            'credential_format' => CredentialFormat::Legacy,
            'secret' => $item['secret'],
            'credential_payload' => null,
        ]);
    }

    /**
     * @param  array{index:int, format:CredentialFormat, secret:?string, payload:?StructuredCredentialPayload, identities:list<array{kind:string,value:string}>}  $item
     */
    private function claimIdentities(Product $product, ProductStockItem $stockItem, array $item): void
    {
        if ($item['identities'] === []) {
            return;
        }
        if (! $this->fingerprints->isReady()) {
            // Legacy Quick Input predates the identity registry. It remains
            // compatible when the rollout key is not configured; structured
            // input is rejected earlier by assertFingerprintReady().
            return;
        }

        foreach ($item['identities'] as $identity) {
            $fingerprint = $this->fingerprints->forIdentity($identity['kind'], $identity['value']);
            $existing = CredentialIdentity::query()
                ->where('identity_kind', $identity['kind'])
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new ProductStockIntakeIdentityConflict($item['index'], $identity['kind']);
            }

            CredentialIdentity::query()->create([
                'identity_kind' => $identity['kind'],
                'fingerprint' => $fingerprint,
                'product_id' => $product->id,
                'product_stock_item_id' => $stockItem->id,
                'first_seen_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<array{index:int, format:CredentialFormat, secret:?string, payload:?StructuredCredentialPayload, identities:list<array{kind:string,value:string}>}>  $items
     */
    private function findRegisteredIdentityConflict(array $items): ?ProductStockIntakeIdentityConflict
    {
        foreach ($this->registeredIdentityKinds($items) as $itemIndex => $kinds) {
            if ($kinds !== []) {
                return new ProductStockIntakeIdentityConflict((int) $itemIndex, (string) array_key_first($kinds));
            }
        }

        return null;
    }

    /**
     * @param  list<array{index:int, identities:list<array{kind:string,value:string}>}>  $items
     * @return array<int,array<string,bool>>
     */
    private function registeredIdentityKinds(array $items): array
    {
        if (! $this->fingerprints->isReady()) {
            return [];
        }

        /** @var array<int,array<string,bool>> $duplicates */
        $duplicates = [];
        foreach ($items as $item) {
            foreach ($item['identities'] as $identity) {
                $fingerprint = $this->fingerprints->forIdentity($identity['kind'], $identity['value']);
                if (CredentialIdentity::query()
                    ->where('identity_kind', $identity['kind'])
                    ->where('fingerprint', $fingerprint)
                    ->exists()) {
                    $duplicates[$item['index']][$identity['kind']] = true;
                }
            }
        }

        return $duplicates;
    }

    private function identityField(string $kind): string
    {
        return $kind === CredentialIdentityKind::AuthToken ? 'access_value' : 'email';
    }

    /** @return list<array{kind:string,value:string}> */
    private function legacyIdentities(string $secret): array
    {
        try {
            return $this->legacyIdentityParser->extract($secret);
        } catch (Throwable) {
            return [];
        }
    }

    private function assertFingerprintReady(): void
    {
        if (! $this->fingerprints->isReady()) {
            throw ValidationException::withMessages([
                'accounts' => ['Input Akun sedang tidak tersedia. Silakan hubungi administrator.'],
            ]);
        }
    }

    private function assertActorMayAdjustStock(?User $actor): void
    {
        if ($actor !== null && $actor->role !== null && ! $actor->role->canMutateProducts()) {
            abort(403);
        }
    }

    private function assertSameOperation(ProductStockIntakeOperation $operation, Product $product, ProductStockIntakeSubmission $submission, string $payloadHash): void
    {
        if ((int) $operation->product_id !== (int) $product->getKey()
            || $operation->payload_hash !== $payloadHash
            || $operation->mode !== $submission->mode
            || $operation->inventory_movement_id === null) {
            throw new ProductStockIntakeIdempotencyConflict;
        }
    }

    private function resultFromOperation(ProductStockIntakeOperation $operation, bool $replay): ProductStockIntakeResult
    {
        return new ProductStockIntakeResult(
            productId: (int) $operation->product_id,
            movementId: (int) $operation->inventory_movement_id,
            itemCount: (int) $operation->item_count,
            stockBefore: (int) $operation->stock_before,
            stockAfter: (int) $operation->stock_after,
            availableBefore: (int) $operation->available_before,
            availableAfter: (int) $operation->available_after,
            idempotentReplay: $replay,
        );
    }

    private function movementNotes(ProductStockIntakeSubmission $submission, int $itemCount): string
    {
        if ($submission->isBulkMfaSecret()) {
            return "Tambah stok bulk email/password/MFA secret dashboard ({$itemCount} item).";
        }
        if ($submission->isBulkAccessToken()) {
            return "Tambah stok bulk email/password/akses token dashboard ({$itemCount} item).";
        }
        if ($submission->isBulkReaderMailLink()) {
            return "Tambah stok bulk email/password/link reader mail dashboard ({$itemCount} item).";
        }

        return $submission->usesStructuredCredentials()
            ? "Tambah stok akun terstruktur dashboard ({$itemCount} item)."
            : "Tambah stok manual dashboard ({$itemCount} item).";
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validationForIdentityConflict(ProductStockIntakeSubmission $submission, ProductStockIntakeIdentityConflict $conflict): ValidationException
    {
        $prefix = $submission->isStructuredAccount() ? 'accounts' : 'stock_items';
        $field = $conflict->identityKind === CredentialIdentityKind::AuthToken ? 'access_value' : 'email';

        return ValidationException::withMessages([
            "{$prefix}.{$conflict->itemIndex}.{$field}" => ['Identitas kredensial sudah pernah digunakan.'],
        ]);
    }
}
