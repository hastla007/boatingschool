<?php

namespace App\Workflow;

use App\Workflow\Actions\LearningGroupInviteAction;
use App\Workflow\Actions\SchoolAdminNoticeAction;
use App\Workflow\Actions\WorkflowAction;

/**
 * Die Befehle, die der Workflow Connector auslösen darf (WF-CUST1-07), und
 * die, die er nie auslösen darf (WF-CUST1-01).
 *
 * Paid-Order-Codezustellung, Idempotenz je Bestellposition, Wiederholung und
 * Sperre bei Rückerstattung gehören dem WooCommerce-Connector (Commerce-API
 * v1, docs/commerce/README.md). Ebenso bleiben Freigabe und Verknüpfung von
 * Shopkonten, Ausgabe und Widerruf von Codes und Kursrechte ausgeschlossen.
 * Solche Befehle werden mit ACTION_FORBIDDEN abgelehnt, nicht nur nicht
 * angeboten -- auch wenn eine spätere Version sie versehentlich registriert.
 */
class ActionRegistry
{
    public const FORBIDDEN = [
        'code_batch.deliver', 'code_batch.void', 'code_batch.revoke', 'code.issue', 'code.revoke', 'coupon.issue', 'coupon.void',
        'order.deliver', 'order.retry', 'order.refund', 'order.void',
        'shop_account.approve', 'shop_account.link', 'shop_account.unlink', 'shop_account.reject', 'shop_account.provision',
        'entitlement.grant', 'entitlement.revoke', 'course_access.grant', 'course_access.revoke',
    ];

    /** @var array<string, WorkflowAction> */
    private array $actions = [];

    public function __construct(SchoolAdminNoticeAction $notice, LearningGroupInviteAction $invite)
    {
        $this->register($notice);
        $this->register($invite);
    }

    public function register(WorkflowAction $action): void
    {
        if (self::forbidden($action->name())) {
            throw new \LogicException('Workflow action not allowed: '.$action->name());
        }
        $this->actions[$action->name()] = $action;
    }

    public static function forbidden(string $name): bool
    {
        return in_array($name, self::FORBIDDEN, true);
    }

    public function get(string $name): ?WorkflowAction
    {
        return $this->actions[$name] ?? null;
    }

    public function enabled(string $name): bool
    {
        return isset($this->actions[$name]) && (bool) config('workflow.actions.'.$name.'.enabled', false);
    }

    public function dailyLimit(string $name): int
    {
        return (int) config('workflow.actions.'.$name.'.daily_limit', 0);
    }

    /** Für den Handshake. @return list<array{action:string, subjects:list<string>}> */
    public function describeEnabled(): array
    {
        $out = [];
        foreach ($this->actions as $name => $action) {
            if ($this->enabled($name)) {
                $out[] = ['action' => $name, 'subjects' => $action->subjectTypes()];
            }
        }

        return $out;
    }
}
