<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Staff\StaffActivationTokenService;
use App\Services\Staff\StaffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function __construct(
        private readonly StaffService $staffService,
        private readonly StaffActivationTokenService $tokenService,
    ) {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $staff = Staff::withTrashed()
            ->with('roles')
            ->when(
                $search !== '',
                fn ($query) => $query->where(function ($builder) use ($search) {
                    $builder
                        ->where('full_name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('telegram_chat_id', 'like', "%{$search}%");
                })
            )
            ->latest()
            ->paginate(20)
            ->appends($search !== '' ? ['search' => $search] : []);

        $availableRoles = Role::query()
            ->orderBy('name')
            ->get();

        return view(
            'staff.index',
            compact(
                'staff',
                'availableRoles',
                'search'
            )
        );
    }

    public function create(): View
    {
        $availableRoles = Role::query()
            ->orderBy('name')
            ->get();

        return view(
            'staff.create',
            compact('availableRoles')
        );
    }

    public function store(
        StoreStaffRequest $request
    ): View {
        $result = $this->staffService->create(
            $request->validated()
        );

        return view(
            'staff.activation-token',
            [
                'staff' => $result['staff'],
                'activationToken' => $result['activation_token'],
            ]
        );
    }

    public function show(Staff $staff): View
    {
        $staff->load('roles');

        $tasks = Task::query()
            ->where('assignee_id', $staff->id)
            ->with([
                'author',
                'assignor',
                'assignee',
                'comments' => fn ($query) => $query->with('staff')->orderBy('created_at'),
                'logs' => fn ($query) => $query->with(['actor', 'fromAssignee', 'toAssignee'])->latest('created_at'),
                'sprints',
            ])
            ->latest('created_at')
            ->get();

        return view('staff.show', [
            'staff' => $staff,
            'tasks' => $tasks,
            'page' => 'people',
            'title' => "IMV IB Support — Xodim ma'lumotlari",
        ]);
    }

    public function edit(Staff $staff): View
    {
        $staff->load('roles');

        $availableRoles = Role::query()
            ->orderBy('name')
            ->get();

        return view(
            'staff.edit',
            compact('staff', 'availableRoles')
        );
    }

    public function update(
        Request $request,
        Staff $staff
    ): RedirectResponse {

        $validated = $request->validate([
            'full_name' => [
                'required',
                'string',
                'max:255',
            ],

            'username' => [
                'nullable',
                'string',
                'max:255',
            ],

            'login' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('staff', 'login')
                    ->ignore($request->input('person_id')),
            ],

            'lavozim' => [
                'nullable',
                'string',
                'max:255',
            ],

            'status' => [
                'required',
                'in:active,inactive,blocked',
            ],

            'telegram_chat_id' => [
                'nullable',
                'string',
                'max:255',
            ],

            /*
            |--------------------------------------------------------------------------
            | Telegram group
            |--------------------------------------------------------------------------
            |
            | Groups are now stored directly on staff.
            |
            */

            'group_chat_id' => [
                'nullable',
                'string',
                'max:255',
            ],

            'group_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'role' => [
                'required',
                'string',
                'exists:roles,name',
            ],

            'password' => [
                'nullable',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Update staff
        |--------------------------------------------------------------------------
        */

        $staff->update([
            'full_name' => $validated['full_name'],

            /*
             * The edit form has no username field (it's set elsewhere, e.g.
             * via Telegram activation), so only overwrite it when a value
             * is actually passed — otherwise keep whatever is already
             * stored instead of wiping it to null on every save.
             */
            'username' => $request->filled('username')
                ? $validated['username']
                : $staff->username,

            'login' => $validated['login'] ?? null,

            'lavozim' => $validated['lavozim'] ?? null,

            'status' => $validated['status'],

            'telegram_chat_id' =>
                $validated['telegram_chat_id'] ?? null,

            /*
             * New group structure.
             */
            'group_chat_id' =>
                $validated['group_chat_id'] ?? null,

            'group_name' =>
                $validated['group_name'] ?? null,

        ]);

        /*
        |--------------------------------------------------------------------------
        | Password
        |--------------------------------------------------------------------------
        */

        if (!empty($validated['password'])) {
            $staff->update([
                'password' => Hash::make(
                    $validated['password']
                ),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Role
        |--------------------------------------------------------------------------
        |
        | A staff member has a normal role relationship.
        | Group membership no longer controls the role.
        |
        */

        $staff->syncRoles([
            $validated['role'],
        ]);

        return redirect()
            ->route('staff.index')
            ->with(
                'success',
                'Xodim maʼlumotlari yangilandi.'
            );
    }

    public function destroy(
        Staff $staff
    ): RedirectResponse {
        /*
         * Same treatment as TelegramMemberSynchronizer::remove(): soft
         * delete only (history stays intact), roles stripped, and status
         * set explicitly so the profile/list pages read correctly even if
         * something loads this record with withTrashed().
         */
        $staff->syncRoles([]);
        $staff->update([
            'status' => 'deleted',
            'group_chat_id' => null,
            'group_name' => null,
        ]);
        $staff->delete();

        return redirect()
            ->route('staff.index')
            ->with(
                'success',
                'Staff o‘chirildi.'
            );
    }

    public function regenerateToken(
        Staff $staff
    ): View {
        $token = $this->staffService
            ->regenerateToken($staff);

        return view(
            'staff.activation-token',
            [
                'staff' => $staff,
                'activationToken' => $token,
            ]
        );
    }

    private function mapRoleForFrontend(
    ?string $role
): string {

    return match ($role) {

        'super_admin',
        'admin',
        'manager',
        'head'
            => 'HEAD',

        'observer',
        'viewer'
            => 'OBSERVER',

        default
            => 'EXECUTOR',

    };
}
}
