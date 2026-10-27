<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as BaseTrustHosts;
use Illuminate\Http\Request;

/**
 * Bug-Review Betrieb: Load-Balancer-/Orchestrator-Health-Probes (AWS
 * ALB/ELB, GCP LB, Kubernetes-Liveness-Probes, ...) senden den Host-
 * Header häufig als rohe IP-Adresse (die eigene Ziel-IP) statt eines zur
 * zentralen Domain passenden Hostnamens -- App\Support\TrustedHosts::
 * patterns() kann eine solche IP naturgemäß nie im Voraus kennen, die
 * reine Host-Validierung (Sicherheitsreview R3-2/R4-A) lehnte den
 * eingebauten /up-Healthcheck (siehe bootstrap/app.php) deshalb pauschal
 * mit 400 ab, noch bevor er überhaupt den eigentlichen Health-Check-Code
 * erreichte -- Monitoring/Load-Balancer, die genau über diesen Endpoint
 * die Erreichbarkeit prüfen sollen, sahen die App dadurch fälschlich als
 * down. /up generiert selbst keine absoluten URLs und ist für den Zweck
 * von R3-2/R4-A (Host-Header-Poisoning über route()/url()) irrelevant,
 * daher hier gezielt von der Host-Validierung ausgenommen statt diese
 * generell für beliebige IP-Hosts zu öffnen (was den eigentlichen Schutz
 * wieder aushebeln würde).
 */
class TrustHosts extends BaseTrustHosts
{
    public function handle(Request $request, $next)
    {
        if ($request->is('up')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
