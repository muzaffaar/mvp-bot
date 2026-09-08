<?php

namespace App\Http\Controllers\Group;

use App\Http\Controllers\Controller;
use App\Http\Requests\Group\StoreGroupRequest;
use App\Http\Requests\Group\UpdateGroupRequest;
use App\Models\Group;
use App\Models\Staff;
use App\Services\Group\GroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\View\View;

class GroupController extends Controller
{
    public function __construct(
        private readonly GroupService $groupService,
    ) {
    }

    public function index(): View
    {
        $groups = Group::query()
            ->withCount('staff')
            ->latest()
            ->paginate(15);

        return view('groups.index', compact('groups'));
    }

    public function create(): View
    {
        return view('groups.create');
    }

    public function store(
        StoreGroupRequest $request
    ): RedirectResponse {
        $this->groupService->create(
            $request->validated()
        );

        return redirect()
            ->route('groups.index')
            ->with('success', 'Group created successfully.');
    }

    public function show(Group $group)
    {
        $group->load('staff');

        $staff = Staff::query()
            ->where('status', 'active')
            ->whereNotIn(
                'id',
                $group->staff->pluck('id')
            )
            ->orderBy('full_name')
            ->get();
            // dd([$group, $staff]);
        return view(
            'groups.show',
            compact('group', 'staff')
        );
    }

    public function edit(Group $group): View
    {
        return view('groups.edit', compact('group'));
    }

    public function update(
        UpdateGroupRequest $request,
        Group $group
    ): RedirectResponse {
        $this->groupService->update(
            $group,
            $request->validated()
        );

        return redirect()
            ->route('groups.index')
            ->with('success', 'Group updated successfully.');
    }

    public function destroy(Group $group): RedirectResponse
    {
        $this->groupService->delete($group);

        return redirect()
            ->route('groups.index')
            ->with('success', 'Group deleted successfully.');
    }

    public function addStaff(
        HttpRequest $request,
        Group $group
    ) {
        $validated = $request->validate([
            'staff_id' => [
                'required',
                'exists:staff,id',
            ],
        ]);

        $staff = Staff::findOrFail(
            $validated['staff_id']
        );

        $group->staff()->syncWithoutDetaching([
            $staff->id => [
                'status' => 'active',
                'joined_at' => now(),
            ],
        ]);

        return redirect()
            ->route('groups.show', $group)
            ->with(
                'success',
                'Staff added to group.'
            );
    }

    public function removeStaff(
        Group $group,
        Staff $staff
    ) {
        $group->staff()->detach($staff->id);

        return redirect()
            ->route('groups.show', $group)
            ->with(
                'success',
                'Staff removed from group.'
            );
    }
}
