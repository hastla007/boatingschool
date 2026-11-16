import Alpine from 'alpinejs';
import participantPicker from './participant-picker';
import tenantPicker from './tenant-picker';

window.Alpine = Alpine;

Alpine.data('tenantPicker', tenantPicker);
// Teilnehmer-Suchfeld (admin/_participant-picker), Logik in participant-picker.js.
Alpine.data('participantPicker', participantPicker);

Alpine.start();
