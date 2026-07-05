import Alpine from 'alpinejs';
import adminDashboard, { salesFilter } from './admin-dashboard';
import initStorefront, { registerStorefrontAlpine } from './storefront';

window.Alpine = Alpine;
Alpine.data('adminDashboard', adminDashboard);
Alpine.data('salesFilter', salesFilter);
Alpine.data('passwordReveal', () => ({
    showPassword: false,
    get inputType() {
        return this.showPassword ? 'text' : 'password';
    },
    get buttonText() {
        return this.showPassword ? 'Sembunyikan' : 'Lihat';
    },
    get buttonLabel() {
        return this.showPassword ? 'Sembunyikan password' : 'Lihat password';
    },
    toggle() {
        this.showPassword = ! this.showPassword;
    },
}));
registerStorefrontAlpine(Alpine);
Alpine.start();
initStorefront();
