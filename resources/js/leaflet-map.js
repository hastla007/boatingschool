import 'leaflet/dist/leaflet.css';
import L from 'leaflet';

// Bug-Review M9 (Folgefehler): vorher per <script src="https://unpkg.com/...">
// geladen -- die CSP musste dafür die GANZE unpkg.com-Domain erlauben
// (CSP kann Quellen nur pro Origin, nicht pro Pfad einschränken), nicht
// nur dieses eine Paket. Jetzt per npm/Vite gebündelt und als globales L
// bereitgestellt, exakt wie zuvor vom CDN -- das bestehende Inline-Script
// in praxis-pruefung/index.blade.php greift unverändert auf window.L zu.
window.L = L;
