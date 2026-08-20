<?php

namespace App\Services;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\InventoryMovementType;
use App\Models\CredentialIdentity;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\Credentials\CredentialIdentityFingerprint;
use App\Services\Credentials\CredentialIdentityKind;
use App\Services\Credentials\StructuredCredentialPayload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductStockItemManager
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CredentialIdentityFingerprint $fingerprints,
    ) {}

    public function updateAvailableSecret(Product $product, ProductStockItem $stockItem, string $secret, User $user, ?string $ip = null): ProductStockItem
    {
        return DB::transaction(function () use ($product, $stockItem, $secret, $user, $ip): ProductStockItem {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /** @var ProductStockItem $lockedItem */
            $lockedItem = ProductStockItem::query()
                ->whereKey($stockItem->getKey())
                ->where('product_id', $lockedProduct->id)
                ->whereNull('order_item_id')
                ->whereNull('retired_at')
                ->lockForUpdate()
                ->firstOrFail();

            $lockedItem->forceFill(['secret' => $secret])->save();

            $this->auditLogger->log(
                'product_stock_item.updated',
                $lockedItem,
                ['product_id' => $lockedProduct->id, 'stock_item_id' => $lockedItem->id, 'secret_present' => true],
                ['product_id' => $lockedProduct->id, 'stock_item_id' => $lockedItem->id, 'secret_changed' => true],
                $user,
                $ip,
            );

            return $lockedItem->refresh();
        });
    }

    /**
     * Update one Available Structured Account Credential without changing
     * numeric stock or creating an inventory movement. New email/Auth Token
     * identities are claimed permanently; old fingerprints are deliberately
     * retained as no-reuse history.
     *
     * @param  array<string, mixed>  $input
     */
    public function updateAvailableStructured(Product $product, ProductStockItem $stockItem, array $input, User $user, ?string $ip = null): ProductStockItem
    {
        $payload = $this->structuredPayload($input);
        $this->fingerprints->assertReady();

        try {
            return DB::transaction(function () use ($product, $stockItem, $payload, $user, $ip): ProductStockItem {
                /** @var Product $lockedProduct */
                $lockedProduct = Product::query()
                    ->whereKey($product->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                /** @var ProductStockItem $lockedItem */
                $lockedItem = ProductStockItem::query()
                    ->whereKey($stockItem->getKey())
                    ->where('product_id', $lockedProduct->id)
                    ->whereNull('order_item_id')
                    ->whereNull('retired_at')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedItem->credential_format !== CredentialFormat::StructuredAccount) {
                    throw ValidationException::withMessages([
                        'credential_format' => 'Format stok tidak cocok dengan editor akun.',
                    ]);
                }

                $this->claimStructuredIdentities($lockedProduct, $lockedItem, $payload);

                $lockedItem->forceFill([
                    'credential_format' => CredentialFormat::StructuredAccount,
                    'credential_payload' => $payload->toArray(),
                    'secret' => null,
                ])->save();

                $this->auditLogger->log(
                    'product_stock_item.updated',
                    $lockedItem,
                    [
                        'product_id' => $lockedProduct->id,
                        'stock_item_id' => $lockedItem->id,
                        'credential_format' => CredentialFormat::StructuredAccount->value,
                        'secret_present' => true,
                    ],
                    [
                        'product_id' => $lockedProduct->id,
                        'stock_item_id' => $lockedItem->id,
                        'credential_format' => CredentialFormat::StructuredAccount->value,
                        'secret_changed' => true,
                        'changed_fields' => ['email', 'password', 'access_method', 'access_value', 'tutorial_override'],
                        'identity_kinds' => array_values(array_unique(array_map(
                            fn (array $identity): string => $identity['kind'],
                            $payload->identityValues(),
                        ))),
                    ],
                    $user,
                    $ip,
                );

                return $lockedItem->refresh();
            }, attempts: 5);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'credential_identities')) {
                throw $this->identityConflictValidation($payload, $this->registeredIdentityKind($payload));
            }

            throw $exception;
        }
    }

    public function deleteAvailable(Product $product, ProductStockItem $stockItem, User $user, ?string $ip = null): Product
    {
        return DB::transaction(function () use ($product, $stockItem, $user, $ip): Product {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /** @var ProductStockItem $lockedItem */
            $lockedItem = ProductStockItem::query()
                ->whereKey($stockItem->getKey())
                ->where('product_id', $lockedProduct->id)
                ->whereNull('order_item_id')
                ->whereNull('retired_at')
                ->lockForUpdate()
                ->firstOrFail();

            $old = ['stock' => $lockedProduct->stock, 'reserved_stock' => $lockedProduct->reserved_stock, 'stock_item_id' => $lockedItem->id];
            $newStock = $lockedProduct->stock - 1;

            if ($newStock < $lockedProduct->reserved_stock) {
                throw ValidationException::withMessages([
                    'stock_item' => 'Stok ini tidak bisa dihapus karena masih ada pesanan yang sedang dipesan.',
                ]);
            }

            $lockedItem->delete();
            $lockedProduct->forceFill(['stock' => $newStock])->save();

            InventoryMovement::create([
                'product_id' => $lockedProduct->id,
                'type' => InventoryMovementType::Adjustment,
                'quantity' => -1,
                'available_before' => $lockedProduct->availableStock(),
                'notes' => 'Hapus stok manual dashboard (1 item).',
                'created_at' => now(),
            ]);

            $this->auditLogger->log(
                'product_stock_item.deleted',
                $lockedProduct,
                $old,
                ['stock' => $newStock, 'reserved_stock' => $lockedProduct->reserved_stock, 'stock_item_id' => $lockedItem->id, 'delta' => -1],
                $user,
                $ip,
            );

            return $lockedProduct->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function structuredPayload(array $input): StructuredCredentialPayload
    {
        $email = $input['email'] ?? null;
        $password = $input['password'] ?? null;
        $accessMethod = $input['access_method'] ?? null;
        $accessValue = $input['access_value'] ?? null;
        $tutorialOverride = $input['tutorial_override'] ?? null;
        $methodValue = $accessMethod instanceof CredentialAccessMethod ? $accessMethod->value : $accessMethod;
        $errors = [];

        if (! is_string($email) || trim($email) === '') {
            $errors['email'][] = 'Email wajib diisi.';
        }
        if (! is_string($password) || trim($password) === '') {
            $errors['password'][] = 'Password wajib diisi.';
        }
        if (! is_string($methodValue) || ! in_array($methodValue, array_map(
            static fn (CredentialAccessMethod $method): string => $method->value,
            CredentialAccessMethod::cases(),
        ), true)) {
            $errors['access_method'][] = 'Metode akses tidak valid.';
        }
        if ($accessValue !== null && ! is_string($accessValue)) {
            $errors['access_value'][] = 'Nilai metode akses tidak valid.';
        }
        if ($tutorialOverride !== null && ! is_string($tutorialOverride)) {
            $errors['tutorial_override'][] = 'Tutorial tambahan tidak valid.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            return StructuredCredentialPayload::fromValues(
                $email,
                $password,
                (string) $methodValue,
                $accessValue,
                $tutorialOverride,
            );
        } catch (Throwable) {
            $field = $methodValue === CredentialAccessMethod::OtpMailLink->value || $methodValue === CredentialAccessMethod::AuthToken->value
                ? 'access_value'
                : 'email';

            throw ValidationException::withMessages([
                $field => [$field === 'access_value' ? 'Nilai metode akses tidak valid.' : 'Email tidak valid.'],
            ]);
        }
    }

    private function claimStructuredIdentities(Product $product, ProductStockItem $stockItem, StructuredCredentialPayload $payload): void
    {
        $activeFingerprints = $this->activeIdentityFingerprints($stockItem);

        foreach ($payload->identityValues() as $identity) {
            $fingerprint = $this->fingerprints->forIdentity($identity['kind'], $identity['value']);
            $existing = CredentialIdentity::query()
                ->where('identity_kind', $identity['kind'])
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ((int) $existing->product_stock_item_id !== (int) $stockItem->id
                    || ! isset($activeFingerprints[$identity['kind'].':'.$fingerprint])) {
                    throw $this->identityConflictValidation($payload, $identity['kind']);
                }

                continue;
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
     * A row's current email/token is reusable during an edit; fingerprints
     * left behind by an earlier version of that row are historical claims and
     * must not be selected again.
     *
     * @return array<string, true>
     */
    private function activeIdentityFingerprints(ProductStockItem $stockItem): array
    {
        try {
            $payload = $stockItem->credential_payload;
            if (! is_array($payload)) {
                return [];
            }

            $active = [];
            foreach (StructuredCredentialPayload::fromArray($payload)->identityValues() as $identity) {
                $active[$identity['kind'].':'.$this->fingerprints->forIdentity($identity['kind'], $identity['value'])] = true;
            }

            return $active;
        } catch (Throwable) {
            return [];
        }
    }

    private function identityConflictValidation(StructuredCredentialPayload $payload, ?string $kind = null): ValidationException
    {
        $kind ??= $payload->identityValues()[0]['kind'] ?? CredentialIdentityKind::Email;

        return ValidationException::withMessages([
            $kind === CredentialIdentityKind::AuthToken ? 'access_value' : 'email' => 'Identitas kredensial sudah terdaftar dan tidak dapat digunakan ulang.',
        ]);
    }

    private function registeredIdentityKind(StructuredCredentialPayload $payload): string
    {
        foreach ($payload->identityValues() as $identity) {
            $fingerprint = $this->fingerprints->forIdentity($identity['kind'], $identity['value']);
            if (CredentialIdentity::query()
                ->where('identity_kind', $identity['kind'])
                ->where('fingerprint', $fingerprint)
                ->exists()) {
                return $identity['kind'];
            }
        }

        return CredentialIdentityKind::Email;
    }
}
