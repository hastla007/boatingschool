import Alpine from 'alpinejs';
import tenantPicker from './tenant-picker';

window.Alpine = Alpine;

Alpine.data('tenantPicker', tenantPicker);

Alpine.start();
