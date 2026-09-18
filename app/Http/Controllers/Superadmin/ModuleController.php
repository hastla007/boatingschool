<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
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
