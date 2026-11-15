<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Audit ARC-V1: der Download im Superadmin-Bereich hängt am Zeitlimit von
 * PHP-FPM (300 s inkl. Übertragung) -- für sehr große Schulen gibt es den
 * gleichen Export auf der Konsole.
 */
class TenantExportCommandTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_console_export_writes_the_same_json_as_the_download(): void
    {
        $tenant = $this->createTestTenant('Export Konsole');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        $path = storage_path('app/arc-v1-export-'.uniqid().'.json');

        try {
            $this->artisan('app:export-tenant', ['tenant' => $tenant->slug, 'path' => $path])
                ->assertSuccessful()->expectsOutputToContain('1 Mitglied(er) exportiert');

            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($tenant->slug, $data['tenant']['slug']);
            $this->assertCount(1, $data['members']);
            $this->assertSame(0600, fileperms($path) & 0777, 'nur für den Betreiber lesbar');
            $this->assertTrue($this->onAdmin(fn () => AuditLog::where('tenant_id', $tenant->id)->where('action', 'superadmin.tenant.export')->exists()));

            // Vorhandene Datei wird nicht überschrieben, unbekannte Schule abgelehnt.
            $this->artisan('app:export-tenant', ['tenant' => $tenant->slug, 'path' => $path])->assertFailed();
            $this->artisan('app:export-tenant', ['tenant' => 'gibt-es-nicht', 'path' => $path.'.2'])->assertFailed();
            $this->assertFileDoesNotExist($path.'.2');
        } finally {
            @unlink($path);
        }
    }
}
