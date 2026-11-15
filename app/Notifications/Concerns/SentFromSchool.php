<?php

namespace App\Notifications\Concerns;

use App\Jobs\Middleware\WithSchoolContext;
use App\Models\Tenant;
use App\Support\MailSchool;
use App\Support\TenantContext;

/**
 * Mail im Namen einer Schule, die über die Queue geht (Audit ARC-2):
 * gespeichert wird nur die ID der Schule (Modelle mit RLS ließen sich im
 * Worker ohne Mandantenkontext gar nicht wiederherstellen); die Job-
 * Middleware setzt damit den Kontext, bevor die Mail gebaut wird.
 */
trait SentFromSchool
{
    /** Schule, aus deren Bereich die Mail kommt (null = Plattform). */
    public ?string $schoolId = null;

    /** Standard: die Schule des aktuellen Requests (wie MailSchool beim direkten Versand). */
    protected function captureSchool(?string $tenantId = null): void
    {
        $this->schoolId = $tenantId ?? MailSchool::current()?->id;
    }

    /** @return array<int, object> */
    public function middleware(mixed $notifiable, string $channel): array
    {
        return [new WithSchoolContext($this->schoolId)];
    }

    protected function school(): ?Tenant
    {
        if ($this->schoolId === null) {
            return null;
        }
        $current = app(TenantContext::class)->tenant();

        return $current?->id === $this->schoolId ? $current : Tenant::find($this->schoolId);
    }
}
