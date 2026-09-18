<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CourseProgressService;
use App\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $users = User::with('tenantMemberships.tenant')
            ->when($search !== '', fn ($q) => $q->where(fn ($q2) => $q2
                ->where('display_name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")))
            ->orderBy('display_name')
            ->paginate(30)
            ->withQueryString();

        return view('superadmin.users.index', ['users' => $users, 'search' => $search]);
    }

    public function create(): View
    {
        return view('superadmin.users.create', [
            'tenants' => Tenant::orderBy('name')->get(),
            'countries' => Countries::OPTIONS,
        ]);
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:app_user,email'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenant,id'],
            'role' => ['required_with:tenant_id', 'nullable', 'in:owner,admin,instructor,staff,learner,support'],
        ]);

        $user = User::create([
            'display_name' => trim($validated['first_name'].' '.$validated['last_name']),
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => Hash::make(str()->random(32)),
            'status' => 'invited',
        ]);

        if (! empty($validated['tenant_id'])) {
            TenantUser::create([
                'tenant_id' => $validated['tenant_id'],
                'user_id' => $user->id,
                'role' => $validated['role'] ?? 'learner',
                'status' => 'active',
            ]);
        }

        return redirect()->route('superadmin.users.show', $user)->with('status', 'Nutzer angelegt.');
    }

    public function show(User $user, CourseProgressService $courseProgress): View
    {
        $memberships = TenantUser::where('user_id', $user->id)->with('tenant')->get();

        $entitlements = Entitlement::where('user_id', $user->id)->with('course', 'tenant')->get();

        $results = $entitlements->map(function (Entitlement $entitlement) use ($user, $courseProgress) {
            return [
                'entitlement' => $entitlement,
                'percent' => $courseProgress->overallPercent($entitlement->course, $entitlement->tenant, $user),
            ];
        });

        $examResults = ExamSession::where('user_id', $user->id)->where('status', 'evaluated')
            ->with('course', 'paper')->orderByDesc('submitted_at')->get();

        return view('superadmin.users.show', [
            'user' => $user,
            'memberships' => $memberships,
            'results' => $results,
            'examResults' => $examResults,
            'tenants' => Tenant::orderBy('name')->get(),
            'countries' => Countries::OPTIONS,
        ]);
    }

    public function update(Request $request, User $user): Response
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:app_user,email,'.$user->id],
            'status' => ['required', 'in:invited,active,suspended,deleted'],
            'phone' => ['nullable', 'string', 'max:40'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'in:'.implode(',', Countries::OPTIONS)],
            'is_superadmin' => ['sometimes', 'boolean'],
        ]);

        $validated['display_name'] = trim($validated['first_name'].' '.$validated['last_name']);
        $validated['is_superadmin'] = $request->boolean('is_superadmin');

        if ($user->email !== $validated['email']) {
            $user->email_verified_at = null;
        }

        $user->update($validated);

        return back()->with('status', 'Nutzer aktualisiert.');
    }

    public function addMembership(Request $request, User $user): Response
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid', 'exists:tenant,id'],
            'role' => ['required', 'in:owner,admin,instructor,staff,learner,support'],
        ]);

        TenantUser::updateOrCreate(
            ['tenant_id' => $validated['tenant_id'], 'user_id' => $user->id],
            ['role' => $validated['role'], 'status' => 'active']
        );

        return back()->with('status', 'Bootsschul-Zuordnung gespeichert.');
    }
}
