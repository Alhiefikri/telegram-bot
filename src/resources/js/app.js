import Alpine from 'alpinejs';
import adminDashboard from './admin-dashboard';
import initStorefront, { registerStorefrontAlpine } from './storefront';

window.Alpine = Alpine;
Alpine.data('adminDashboard', adminDashboard);
registerStorefrontAlpine(Alpine);

Alpine.start();
initStorefront();
