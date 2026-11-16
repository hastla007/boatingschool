<?php

namespace App\Http\Controllers\Workflow;

use App\Api\ApiProblem;
use App\Http\Controllers\Controller;
use App\Workflow\ActionRegistry;
use App\Workflow\CommandHandler;
use App\Workflow\EventCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Eingang des Workflow-Vertrags (/api/integrations/v1/workflow, docs/workflow/README.md).
 * Signatur, Zeitfenster, Nonce und Rate-Limit prüft vorher VerifyWorkflowSignature.
 */
class WorkflowController extends Controller
{
    /**
     * Contract-Handshake (WF-CUST1-05): der Connector meldet seine Vertrags-
     * version, die App antwortet mit ihrer, den Ereignistypen und den
     * freigegebenen Befehlen. Eine andere Hauptversion ist inkompatibel.
     */
    public function handshake(Request $request, ActionRegistry $actions): JsonResponse
    {
        $body = (array) $request->attributes->get('workflow_body');
        $theirs = is_string($body['contract_version'] ?? null) ? $body['contract_version'] : '';
        $ours = (string) config('workflow.contract_version');
        if (! preg_match('/^\d+\.\d+\.\d+$/', $theirs)) {
            throw new ApiProblem(422, 'PARAMS_INVALID', 'Vertragsversion fehlt', 'contract_version:invalid');
        }
        if ((int) strtok($theirs, '.') !== (int) strtok($ours, '.')) {
            throw new ApiProblem(409, 'CONTRACT_INCOMPATIBLE', 'Vertrag inkompatibel', 'Hauptversion der App: '.$ours, [], [], ['contract_version' => $ours]);
        }

        return response()->json([
            'contract_version' => $ours,
            'compatible' => true,
            'key_id' => (string) $request->attributes->get('workflow_key_id'),
            'scopes' => (array) $request->attributes->get('workflow_scopes'),
            'events' => EventCatalog::describe(),
            'actions' => $actions->describeEnabled(),
            'server_time' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function command(Request $request, CommandHandler $handler): JsonResponse
    {
        $result = $handler->handle(
            (string) $request->attributes->get('workflow_key_id'),
            (array) $request->attributes->get('workflow_scopes'),
            (array) $request->attributes->get('workflow_body'),
        );

        return response()->json($result['body'], $result['status']);
    }
}
