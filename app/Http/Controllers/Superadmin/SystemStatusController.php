<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Support\ProductionReadiness;
use Illuminate\View\View;

/** Systemstatus: derselbe Go-live-Check wie app:check-production, im Browser. */
class SystemStatusController extends Controller
{
    public function __invoke(): View
    {
        $checks = collect(ProductionReadiness::checks());

        return view('superadmin.system.index', [
            'groups' => $checks->groupBy('group'),
            'counts' => $checks->countBy('status'),
        ]);
    }
}
