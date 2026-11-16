<?php

use App\Providers\AppServiceProvider;
use App\Providers\WorkflowServiceProvider;

return [
    AppServiceProvider::class,
    // Workflow-Vertrag mit dem BoatingSchool Workflow Connector (docs/workflow/README.md).
    WorkflowServiceProvider::class,
];
