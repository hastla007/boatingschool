<?php

namespace App\Workflow;

use App\Api\ApiProblem;
use App\Models\CommerceShopAccount;
use App\Models\CouponBatch;
use App\Models\LearningGroup;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ausführung eines Befehls des Workflow Connectors (WF-CUST1-06, -07).
 *
 * - Die Schule bestimmt die App selbst: aus dem Gegenstand des Befehls
 *   (Shopkonto, Code-Batch, Lerngruppe), gelesen auf der Owner-Verbindung.
 *   Die tenant_id im Befehl ist nur eine Referenz; passt sie nicht, wird
 *   abgelehnt (TENANT_MISMATCH). Ein unbekannter Gegenstand ist 404.
 * - Plattformweite Befehle sind ein eigener Scope des Schlüssels. Version 1
 *   kennt keinen: "scope": "platform" wird immer abgelehnt.
 * - Ausgeführt wird auf der App-Verbindung mit dem RLS-Kontext genau dieser
 *   Schule (TenantContext::set, danach clear) -- auch ein fehlerhafter
 *   Befehl sieht keine Daten einer anderen Schule.
 * - Idempotenz je Schlüssel-ID und Idempotenzschlüssel (workflow_command):
 *   dieselbe Anfrage liefert dasselbe Ergebnis ohne zweite Ausführung, eine
 *   abweichende Anfrage mit demselben Schlüssel ist ein Konflikt.
 * - Tagesgrenze je Schule und Befehl (Kalendertag Europe/Berlin).
 */
class CommandHandler
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const IDEMPOTENCY = '/^[A-Za-z0-9_.:-]{16,128}$/';

    public function __construct(private ActionRegistry $actions, private TenantContext $context) {}

    /**
     * @param  list<string>  $scopes  Rechte des Schlüssels
     * @return array{status:int, body:array}
     */
    public function handle(string $keyId, array $scopes, array $body): array
    {
        $name = is_string($body['action'] ?? null) ? $body['action'] : '';
        if (ActionRegistry::forbidden($name)) {
            throw new ApiProblem(403, 'ACTION_FORBIDDEN', 'Befehl nicht erlaubt',
                'Diesen Vorgang führt nur der zuständige Dienst aus (WooCommerce-Connector bzw. Superadmin), nie ein Workflow.');
        }
        $scope = $body['scope'] ?? null;
        if ($scope === WorkflowKeys::SCOPE_PLATFORM) {
            throw in_array(WorkflowKeys::SCOPE_PLATFORM, $scopes, true)
                ? new ApiProblem(403, 'PLATFORM_ACTION_UNAVAILABLE', 'Kein plattformweiter Befehl', 'Version 1 des Vertrags kennt keine plattformweiten Befehle.')
                : new ApiProblem(403, 'SCOPE_NOT_GRANTED', 'Scope nicht erteilt', 'Dieser Schlüssel hat keinen plattformweiten Scope.');
        }
        if ($scope !== WorkflowKeys::SCOPE_TENANT || ! in_array(WorkflowKeys::SCOPE_TENANT, $scopes, true)) {
            throw new ApiProblem(403, 'SCOPE_NOT_GRANTED', 'Scope nicht erteilt', 'Befehle gelten nur im Rahmen einer Schule (scope "tenant").');
        }
        $action = $this->actions->get($name);
        if (! $action) {
            throw new ApiProblem(404, 'ACTION_UNKNOWN', 'Befehl unbekannt');
        }
        if (! $this->actions->enabled($name)) {
            throw new ApiProblem(403, 'ACTION_DISABLED', 'Befehl abgeschaltet');
        }

        $key = $body['idempotency_key'] ?? null;
        $tenantRef = $body['tenant_id'] ?? null;
        $subject = $body['subject'] ?? null;
        $params = $body['params'] ?? [];
        $codes = [];
        if (! is_string($key) || ! preg_match(self::IDEMPOTENCY, $key)) {
            $codes[] = 'idempotency_key:invalid';
        }
        if (! is_string($tenantRef) || ! preg_match(self::UUID, $tenantRef)) {
            $codes[] = 'tenant_id:invalid';
        }
        if (! is_array($subject) || ! in_array($subject['type'] ?? null, $action->subjectTypes(), true)
            || ! is_string($subject['id'] ?? null) || ! preg_match(self::UUID, $subject['id'])) {
            $codes[] = 'subject:invalid';
        }
        if (! is_array($params) || ($params !== [] && array_is_list($params))) {
            $codes[] = 'params:invalid';
        } else {
            $codes = array_merge($codes, $action->validate($params));
        }
        if ($codes !== []) {
            throw new ApiProblem(422, 'PARAMS_INVALID', 'Befehl ungültig', implode(', ', $codes));
        }
        $subject = ['type' => $subject['type'], 'id' => $subject['id']];

        $tenant = $this->resolveTenant($subject);
        if ($tenant->id !== $tenantRef) {
            throw new ApiProblem(403, 'TENANT_MISMATCH', 'Falsche Schule', 'Der Gegenstand gehört nicht zur angegebenen Schule.');
        }
        if (! in_array($tenant->status, ['active', 'trial'], true)) {
            throw new ApiProblem(409, 'TENANT_INACTIVE', 'Schule nicht aktiv');
        }

        $hash = hash('sha256', json_encode([$name, $tenant->id, $subject, $params]));
        $admin = DB::connection('pgsql_admin');
        $inserted = $admin->table('workflow_command')->insertOrIgnore([
            'key_id' => $keyId, 'idempotency_key' => $key, 'action' => $name, 'tenant_ref' => $tenant->id,
            'request_hash' => $hash, 'status' => 'running', 'created_at' => now(),
        ]);
        if ($inserted !== 1) {
            $previous = $admin->table('workflow_command')->where('key_id', $keyId)->where('idempotency_key', $key)->first();
            if (! $previous || ! hash_equals($previous->request_hash, $hash)) {
                throw new ApiProblem(409, 'IDEMPOTENCY_CONFLICT', 'Idempotenzschlüssel schon anders verwendet');
            }
            if ($previous->status !== 'done') {
                throw new ApiProblem(409, 'COMMAND_IN_PROGRESS', 'Befehl läuft noch', null, [], ['Retry-After' => '5']);
            }

            return ['status' => 200, 'body' => ['result' => json_decode((string) $previous->result, true), 'repeated' => true]];
        }

        try {
            $this->assertWithinDailyLimit($name, $tenant);
            $result = $this->runInTenant($tenant, fn (Tenant $scoped) => $action->execute($scoped, $subject, $params));
        } catch (Throwable $e) {
            // Nicht ausgeführt: derselbe Schlüssel darf es später erneut versuchen.
            $admin->table('workflow_command')->where('key_id', $keyId)->where('idempotency_key', $key)->where('status', 'running')->delete();
            if ($e instanceof ApiProblem) {
                throw $e;
            }
            report($e);
            throw new ApiProblem(503, 'ACTION_FAILED', 'Befehl vorübergehend nicht ausführbar', null, [], ['Retry-After' => '60']);
        }
        $admin->table('workflow_command')->where('key_id', $keyId)->where('idempotency_key', $key)
            ->update(['status' => 'done', 'result' => json_encode($result), 'finished_at' => now()]);

        return ['status' => 200, 'body' => ['result' => $result, 'repeated' => false]];
    }

    /** Schule aus dem Gegenstand, nie aus der Anfrage. */
    private function resolveTenant(array $subject): Tenant
    {
        $tenantId = match ($subject['type']) {
            'shop_account' => CommerceShopAccount::on('pgsql_admin')->whereKey($subject['id'])->where('status', CommerceShopAccount::LINKED)->value('tenant_id'),
            'code_batch' => CouponBatch::on('pgsql_admin')->withoutGlobalScopes()->whereKey($subject['id'])->value('tenant_id'),
            'learning_group' => LearningGroup::on('pgsql_admin')->withoutGlobalScopes()->whereKey($subject['id'])->value('tenant_id'),
            'tenant' => Tenant::on('pgsql_admin')->whereKey($subject['id'])->value('id'),
            default => null,
        };
        $tenant = $tenantId ? Tenant::on('pgsql_admin')->find($tenantId) : null;
        if (! $tenant) {
            throw new ApiProblem(404, 'SUBJECT_NOT_FOUND', 'Gegenstand nicht gefunden');
        }

        return $tenant;
    }

    private function assertWithinDailyLimit(string $name, Tenant $tenant): void
    {
        $limit = $this->actions->dailyLimit($name);
        if ($limit <= 0) {
            return;
        }
        $start = CarbonImmutable::now('Europe/Berlin')->startOfDay()->utc();
        $used = DB::connection('pgsql_admin')->table('workflow_command')->where('tenant_ref', $tenant->id)->where('action', $name)
            ->where('status', 'done')->where('created_at', '>=', $start)->count();
        if ($used >= $limit) {
            throw new ApiProblem(429, 'DAILY_LIMIT_REACHED', 'Tagesgrenze erreicht', 'Für diese Schule ist die Tagesgrenze dieses Befehls erreicht.', [], ['Retry-After' => '3600']);
        }
    }

    /**
     * RLS-Kontext der Schule auf der App-Verbindung, danach zuverlässig
     * zurücksetzen. Der Befehl bekommt die Schule als Modell der App-
     * Verbindung: auch ihre Relationen (z. B. admins()) laufen unter RLS.
     */
    private function runInTenant(Tenant $tenant, callable $callback): mixed
    {
        $previous = config('database.default');
        config(['database.default' => 'pgsql']);
        try {
            $this->context->set($tenant);
            $scoped = Tenant::on('pgsql')->findOrFail($tenant->id);

            return $callback($scoped);
        } finally {
            $this->context->clear();
            config(['database.default' => $previous]);
        }
    }
}
