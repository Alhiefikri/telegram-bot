import Alpine from 'alpinejs';
import adminDashboard from './admin-dashboard';
import initStorefront from './storefront';

window.Alpine = Alpine;
Alpine.data('adminDashboard', adminDashboard);

Alpine.start();
initStorefront();
