<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Module;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ModuleController extends Controller
{
    public function index(): View
    {
        $modules = Module::withCount('questions')->orderBy('name')->get();

        return view('superadmin.modules.index', ['modules' => $modules]);
    }

    public function update(Request $request, Module $module): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
        $before = $module->only(['name', 'version', 'description']);
        $module->update($validated);
        AuditLog::record(null, $request->user()->id, 'superadmin.module.update', 'module', $module->id, $before, $validated);

        return back()->with('status', 'Modul gespeichert.');
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:80', 'unique:module,code'],
            'name' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:80'],
        ]);

        $module = Module::create($validated + ['status' => 'published']);

        return redirect()->route('superadmin.questions.index', $module)->with('status', 'Modul angelegt.');
    }
}
