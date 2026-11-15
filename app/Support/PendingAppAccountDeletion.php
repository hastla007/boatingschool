<?php

namespace App\Support;

use App\Models\ApiAccountDeletionRequest;
use App\Models\Tenant;
use App\Models\User;

/** Geprüfter, noch offener Löschantrag aus der App (siehe AppAccountDeletion::find()). */
final class PendingAppAccountDeletion
{
    public function __construct(
        public readonly ApiAccountDeletionRequest $deletion,
        public readonly Tenant $tenant,
        public readonly User $user,
        /** Keine weitere Mitgliedschaft: mit der Bestätigung entfällt das ganze Konto. */
        public readonly bool $deletesWholeAccount,
    ) {}
}
