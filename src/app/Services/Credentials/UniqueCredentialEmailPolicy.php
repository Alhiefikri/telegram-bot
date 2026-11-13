<?php

namespace App\Services\Credentials;

use App\Enums\CredentialFormat;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\CredentialDeliveryFormatter;
use Illuminate\Validation\ValidationException;
use Throwable;

final class UniqueCredentialEmailPolicy
{
    public function __construct(
        private readonly CredentialDeliveryFormatter $formatter,
        private readonly CredentialIdentityNormalizer $normalizer,
    ) {}

    public function enabled(OrderItem $item): bool
    {
        return (bool) $item->unique_credential_email
            || (bool) Product::withTrashed()->whereKey($item->product_id)->value('unique_credential_email');
    }

    public function email(ProductStockItem $stock): ?string
    {
        try {
            $email = match ($stock->safeCredentialFormat()) {
                CredentialFormat::StructuredAccount => StructuredCredentialPayload::fromArray($stock->credential_payload)->email,
                CredentialFormat::Legacy => $this->formatter->parts((string) $stock->secret)['email'] ?? null,
                default => null,
            };

            return $email === null ? null : $this->normalizer->normalizeEmail($email);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array{email: string, invoice_number: string, order_id: string}> */
    public function history(OrderItem $item): array
    {
        $customerId = $item->order()->value('customer_id');
        $history = [];
        foreach (ProductStockItem::query()
            ->where('product_id', $item->product_id)
            ->whereHas('orderItem.order', fn ($query) => $query->where('customer_id', $customerId))
            ->with('orderItem.order:id,invoice_number')
            ->lazyById(100) as $stock) {
            $email = $this->email($stock);
            if ($email === null) {
                throw ValidationException::withMessages(['credentials' => 'Riwayat email akun belum dapat diverifikasi. Periksa format kredensial pada pesanan '.$stock->orderItem->order->invoice_number.'.']);
            }
            $history[] = ['email' => $email, 'invoice_number' => $stock->orderItem->order->invoice_number, 'order_id' => (string) $stock->orderItem->order_id];
        }

        return $history;
    }

    /** Call inside the allocation transaction, after locking Product. */
    public function assertCanAssign(OrderItem $item, array $stockItems): void
    {
        if (! $this->enabled($item)) {
            return;
        }
        $seen = [];
        foreach ($this->history($item) as $entry) {
            $seen[$entry['email']] = $entry['invoice_number'];
        }
        $invoiceNumber = $item->order()->value('invoice_number');
        foreach ($stockItems as $stock) {
            $email = $this->email($stock);
            if ($email === null) {
                throw ValidationException::withMessages(['credentials' => 'Email akun harus terbaca. Gunakan akun terstruktur atau format email | password.']);
            }
            if (isset($seen[$email])) {
                throw ValidationException::withMessages(['credentials' => 'Email '.$email.' sudah digunakan untuk buyer ini pada pesanan '.$seen[$email].'.']);
            }
            $seen[$email] = $invoiceNumber;
        }
    }

    /** @return list<ProductStockItem> */
    public function selectAvailable(OrderItem $item, int $quantity): array
    {
        try {
            $seen = array_fill_keys(array_column($this->history($item), 'email'), true);
        } catch (ValidationException) {
            return [];
        }
        $selected = [];
        foreach (ProductStockItem::query()->where('product_id', $item->product_id)
            ->whereNull('supplier_purchase_id')->whereNull('order_item_id')->whereNull('retired_at')
            ->orderBy('id')->lazyById(100) as $stock) {
            $email = $this->email($stock);
            if ($email === null || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $selected[] = $stock;
            if (count($selected) === $quantity) {
                break;
            }
        }

        return $selected;
    }
}
