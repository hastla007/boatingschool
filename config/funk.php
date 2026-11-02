<?php

return [
    /*
     * Gerätesimulation Funk: "superadmin" zeigt sie nur Superadmins (Beta,
     * bis Herstelleranfrage und anwaltliche Prüfung erledigt sind, siehe
     * Konzept), "all" allen Lernenden der Funkkurse, "off" niemandem.
     */
    'geraetesimulation' => env('FUNK_GERAETESIMULATION', 'superadmin'),
];
