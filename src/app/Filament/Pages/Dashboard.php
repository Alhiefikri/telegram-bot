<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Widgets\LowStockProductsWidget;
use App\Filament\Widgets\OrderStatusWidget;
use App\Filament\Widgets\RecentOrdersWidget;
use App\Filament\Widgets\StoreOverviewWidget;
use App\Filament\Widgets\StoreSupportWidget;
use App\Models\Product;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 1;

    public function getTitle(): string|Htmlable
    {
        return 'Admin LHIE Store';
    }

    public function getHeading(): string|Htmlable|null
    {
        $name = Filament::auth()->user()?->name ?: 'Admin';

        return "Selamat datang, {$name}";
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Pantau pesanan, produk, pembayaran, dan pengiriman digital hari ini.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make('createProduct')
                ->label('Tambah Produk')
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->model(Product::class)
                ->modelLabel('produk')
                ->modalHeading('Tambah produk digital')
                ->modalDescription('Isi detail katalog. Stok awal dibuat 0 dan bisa disesuaikan dari halaman produk.')
                ->modalSubmitActionLabel('Simpan Produk')
                ->modalWidth(Width::ThreeExtraLarge)
                ->successNotificationTitle('Produk ditambahkan')
                ->createAnother(false)
                ->schema(fn (Schema $schema): Schema => ProductForm::configure($schema))
                ->using(fn (array $data): Product => $this->createProductFromModal($data))
                ->after(function (CreateAction $action): void {
                    $record = $action->getRecord(false);

                    if ($record instanceof Product) {
                        $this->auditCreatedProduct($record);
                    }

                    $this->dispatch('$refresh');
                }),
            Action::make('viewOrders')
                ->label('Lihat Pesanan')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->color('gray')
                ->url(OrderResource::getUrl('index')),
        ];
    }

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'lg' => 12,
        ];
    }

    public function getWidgets(): array
    {
        return [
            StoreOverviewWidget::class,
            RecentOrdersWidget::class,
            OrderStatusWidget::class,
            LowStockProductsWidget::class,
            StoreSupportWidget::class,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createProductFromModal(array $data): Product
    {
        unset($data['stock'], $data['reserved_stock']);

        $product = new Product;
        $product->fill($data);
        $product->stock = 0;
        $product->reserved_stock = 0;
        $product->save();

        return $product;
    }

    private function auditCreatedProduct(Product $product): void
    {
        app(AuditLogger::class)->log(
            'product.created',
            $product,
            null,
            $product->only(['category_id', 'name', 'description', 'price_amount', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']),
            auth()->user(),
            request()->ip(),
        );
    }
}
