import 'leaflet/dist/leaflet.css';
import L from 'leaflet';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

// Bug-Review M9 (Folgefehler): vorher per <script src="https://unpkg.com/...">
// geladen -- die CSP musste dafür die GANZE unpkg.com-Domain erlauben
// (CSP kann Quellen nur pro Origin, nicht pro Pfad einschränken), nicht
// nur dieses eine Paket. Jetzt per npm/Vite gebündelt und als globales L
// bereitgestellt, exakt wie zuvor vom CDN -- das bestehende Inline-Script
// in praxis-pruefung/index.blade.php greift unverändert auf window.L zu.
window.L = L;

// Bug-Review R4-C: L.Icon.Default ermittelt den Bildpfad standardmäßig per
// DOM-Sondierung (liest die CSS-background-image-URL einer unsichtbaren
// .leaflet-default-icon-path-Probe und schneidet davon den Dateinamen ab,
// siehe _detectIconPath() in leaflet-src.js) -- das setzt voraus, dass die
// Marker-Bilder unter ihrem URSPRÜNGLICHEN Dateinamen neben der
// leaflet.css liegen. Vite hasht/verschiebt Bild-Assets beim Bundling
// (public/build/assets/marker-icon-<hash>.png) und kopiert dabei nur
// Bilder, die tatsächlich per CSS url() referenziert werden --
// marker-icon-2x.png und marker-shadow.png werden von Leaflet aber NUR
// per JS-String-Konkatenation (imagePath + 'marker-icon-2x.png' usw.)
// angefordert, landen also nie im Build; selbst marker-icon.png würde
// unter dem FALSCHEN (unverhashten) Dateinamen angefragt. Ohne explizite
// URLs zeigt jeder Marker auf der Karte deshalb ein kaputtes Bild statt
// des Pin-Icons. Der explizite Import oben gibt Vite die Chance, jedes
// Bild selbst zu hashen, und liefert im Gegenzug die tatsächliche,
// korrekte URL zurück -- mergeOptions() ersetzt damit Leaflets fragile
// Auto-Erkennung, bevor die erste Karte/der erste Marker erzeugt wird.
L.Icon.Default.mergeOptions({
    iconUrl: markerIcon,
    iconRetinaUrl: markerIcon2x,
    shadowUrl: markerShadow,
});
