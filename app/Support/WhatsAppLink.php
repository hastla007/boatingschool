<?php

namespace App\Support;

use App\Models\CourseDefinition;
use App\Models\TenantBranding;
use App\Models\User;

/**
 * Baut den wa.me-"Click-to-Chat"-Link aus den WhatsApp-Einstellungen einer
 * Bootsschule: Begrüßungstext mit {name}/{kurs}-Platzhaltern, ersetzt durch
 * den eingeloggten Nutzer und -- wenn bekannt -- den gerade angesehenen Kurs.
 */
class WhatsAppLink
{
    public static function for(TenantBranding $branding, User $user, ?CourseDefinition $course = null): ?string
    {
        if (! $branding->whatsapp_enabled || ! $branding->whatsapp_phone) {
            return null;
        }

        $greeting = $branding->whatsapp_greeting ?: 'Hallo {name}, ich habe eine Frage zu {kurs}.';
        $greeting = strtr($greeting, [
            '{name}' => $user->name,
            '{kurs}' => $course->name ?? 'meinem Kurs',
        ]);

        $phone = preg_replace('/\D+/', '', $branding->whatsapp_phone);

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($greeting);
    }
}
