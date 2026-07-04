const COPY_RESET_DELAY_MS = 1600;
const EMPTY_IMAGE_FILE_NAME = 'Tidak ada file yang dipilih';

const emptyProductForm = () => ({
    productModalMode: 'create',
    productFormMethod: '',
    productId: null,
    productName: '',
    productPrice: '',
    productFakeDiscountPrice: '',
    productCategoryId: '',
    productDescription: '',
    productFeatures: '',
    productSpecifications: '',
    productIsActive: true,
    productImageUrl: '',
    productImageFileName: EMPTY_IMAGE_FILE_NAME,
    productFeaturesOpen: false,
    productSpecificationsOpen: false,
});

const editableStockItems = (items = []) => items.map((item) => ({ ...item, editing: false }));

export default function adminDashboard(initialState = {}) {
    return {
        activeTab: initialState.activeTab ?? 'stats',
        copied: false,
        storeLink: initialState.storeLink ?? '',
        copyState: 'Salin',
        productModalOpen: Boolean(initialState.productModalOpen),
        productFormSubmitting: false,
        productFormAction: initialState.productStoreUrl ?? '',
        ...emptyProductForm(),
        ...(initialState.productForm ?? {}),
        productFeaturesOpen: Boolean(initialState.productForm?.productFeaturesOpen),
        productSpecificationsOpen: Boolean(initialState.productForm?.productSpecificationsOpen),
        stockModalOpen: Boolean(initialState.stockModalOpen),
        stockFormAction: initialState.stockForm?.stockFormAction ?? '',
        stockProductId: initialState.stockForm?.stockProductId ?? null,
        stockProductName: initialState.stockForm?.stockProductName ?? '',
        stockProductStock: initialState.stockForm?.stockProductStock ?? 0,
        stockItems: initialState.stockForm?.stockItems ?? '',
        stockFormSubmitting: false,
        manageStockModalOpen: false,
        manageStockProductName: '',
        manageStockProductStock: 0,
        manageStockItems: [],
        manageStockAvailableCount: 0,
        manageStockSummary: { available: 0, reserved: 0, paid: 0 },
        manageStockTruncated: false,
        manageStockAddPayload: null,

        stockItemCount() {
            return this.stockItems
                .split(/\r?\n/)
                .map((row) => row.trim())
                .filter(Boolean)
                .length;
        },

        openProductForm() {
            Object.assign(this, {
                activeTab: 'produk',
                productFormAction: initialState.productStoreUrl ?? '',
                productFormSubmitting: false,
                ...emptyProductForm(),
                productModalOpen: true,
            });
            this.$nextTick(() => this.$refs.productName?.focus());
        },

        openProductEditModal(product) {
            Object.assign(this, {
                activeTab: 'produk',
                productModalMode: 'edit',
                productFormAction: product.updateUrl,
                productFormSubmitting: false,
                productFormMethod: 'PATCH',
                productId: product.id,
                productName: product.name,
                productPrice: product.priceAmount,
                productFakeDiscountPrice: product.fakeDiscountPrice ?? '',
                productCategoryId: product.categoryId ?? '',
                productDescription: product.description,
                productFeatures: product.features,
                productSpecifications: product.specifications,
                productIsActive: product.isActive,
                productImageUrl: product.imageUrl ?? '',
                productImageFileName: EMPTY_IMAGE_FILE_NAME,
                productFeaturesOpen: String(product.features || '').trim().length > 0,
                productSpecificationsOpen: String(product.specifications || '').trim().length > 0,
                productModalOpen: true,
            });
            this.$nextTick(() => this.$refs.productName?.focus());
        },

        closeProductModal() {
            this.productFormSubmitting = false;
            this.productModalOpen = false;
        },

        submitProductForm(event) {
            if (! event.target.checkValidity()) {
                return;
            }

            this.productFormSubmitting = true;
            this.productModalOpen = false;
        },

        openManageStockModal(product) {
            Object.assign(this, {
                activeTab: 'produk',
                manageStockProductName: product.name,
                manageStockProductStock: product.stock,
                manageStockItems: editableStockItems(product.stockItems),
                manageStockAvailableCount: product.availableStockItemCount || 0,
                manageStockSummary: product.stockSummary || { available: product.availableStockItemCount || 0, reserved: 0, paid: 0 },
                manageStockTruncated: Boolean(product.stockItemsTruncated),
                manageStockAddPayload: {
                    id: product.id,
                    name: product.name,
                    stock: product.stock,
                    stockUrl: product.stockUrl,
                },
                manageStockModalOpen: true,
            });
        },

        closeManageStockModal() {
            this.manageStockModalOpen = false;
            this.manageStockItems = [];
            this.manageStockSummary = { available: 0, reserved: 0, paid: 0 };
            this.manageStockAddPayload = null;
        },

        openStockModalFromManage() {
            if (! this.manageStockAddPayload) {
                return;
            }

            const product = this.manageStockAddPayload;
            this.closeManageStockModal();
            this.openStockModal(product);
        },

        openStockModal(product) {
            Object.assign(this, {
                activeTab: 'produk',
                stockFormAction: product.stockUrl,
                stockProductId: product.id,
                stockProductName: product.name,
                stockProductStock: product.stock,
                stockItems: '',
                stockFormSubmitting: false,
                stockModalOpen: true,
            });
            this.$nextTick(() => this.$refs.stockItems?.focus());
        },

        closeStockModal() {
            this.stockFormSubmitting = false;
            this.stockModalOpen = false;
            this.stockItems = '';
        },

        submitStockForm(event) {
            if (! event.target.checkValidity()) {
                return;
            }

            this.stockFormSubmitting = true;
            this.stockModalOpen = false;
        },

        handleCloseModal(event) {
            if (event.detail?.modal === 'stock-modal') {
                this.closeStockModal();
            }

            if (event.detail?.modal === 'product-modal') {
                this.closeProductModal();
            }

            if (event.detail?.modal === 'manage-stock-modal') {
                this.closeManageStockModal();
            }
        },

        markStoreLinkCopied() {
            this.copied = true;
            this.copyState = 'Tersalin';
            setTimeout(() => {
                this.copied = false;
                this.copyState = 'Salin';
            }, COPY_RESET_DELAY_MS);
        },

        async copyText(text) {
            if (! navigator.clipboard?.writeText) {
                throw new Error('clipboard_unavailable');
            }

            await navigator.clipboard.writeText(text);
            this.markStoreLinkCopied();
        },

        async copyStoreLink() {
            try {
                await this.copyText(this.storeLink);
            } catch (error) {
                window.prompt('Salin link toko', this.storeLink);
            }
        },

        async copyProductShareLink(url) {
            try {
                await this.copyText(url);
            } catch (error) {
                window.prompt('Salin link produk', url);
            }
        },

        async shareStore() {
            if (navigator.share) {
                try {
                    await navigator.share({ title: 'LHIE STORE', url: this.storeLink });
                    return;
                } catch (error) {
                    if (error?.name === 'AbortError') {
                        return;
                    }
                }
            }

            await this.copyStoreLink();
        },
    };
}
