<?php

namespace App\Services\Group;

use App\Models\Group;
use Illuminate\Support\Str;

class GroupService
{
    public function create(array $data): Group
    {
        return Group::create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);
    }

    public function update(Group $group, array $data): Group
    {
        $group->update([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? $group->status,
        ]);

        return $group->refresh();
    }

    public function delete(Group $group): void
    {
        $group->delete();
    }
}
