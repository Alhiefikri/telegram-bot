import Alpine from 'alpinejs';
import adminDashboard from './admin-dashboard';

window.Alpine = Alpine;
Alpine.data('adminDashboard', adminDashboard);

Alpine.start();
