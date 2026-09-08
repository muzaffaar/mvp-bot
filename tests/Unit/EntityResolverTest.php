<?php

namespace Tests\Unit\AI;

use App\AI\Services\EntityResolver;
use App\Models\Group;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityResolverTest extends TestCase
{
    use RefreshDatabase;

    private EntityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(EntityResolver::class);
    }

    public function test_resolve_group_returns_matching_group(): void
    {
        $group = Group::factory()->create([
            'name' => 'IT',
        ]);

        $result = $this->resolver->resolveGroup('IT');

        $this->assertNotNull($result);
        $this->assertSame($group->id, $result->id);
        $this->assertSame('IT', $result->name);
    }

    public function test_resolve_group_returns_null_when_name_is_empty(): void
    {
        Group::factory()->create([
            'name' => 'IT',
        ]);

        $this->assertNull(
            $this->resolver->resolveGroup(null)
        );

        $this->assertNull(
            $this->resolver->resolveGroup('')
        );
    }

    public function test_resolve_group_returns_null_when_group_does_not_exist(): void
    {
        $this->assertNull(
            $this->resolver->resolveGroup('Nonexistent Group')
        );
    }

    public function test_resolve_staff_by_full_name(): void
    {
        $staff = Staff::factory()->create([
            'full_name' => 'Ali Valiyev',
            'name' => 'Ali',
            'username' => 'ali',
            'login' => 'ali.login',
            'status' => 'active',
        ]);

        $result = $this->resolver->resolveStaff(
            'Ali Valiyev'
        );

        $this->assertNotNull($result);
        $this->assertSame($staff->id, $result->id);
    }

    public function test_resolve_staff_by_name(): void
    {
        $staff = Staff::factory()->create([
            'full_name' => 'Ali Valiyev',
            'name' => 'Ali',
            'username' => 'ali',
            'login' => 'ali.login',
            'status' => 'active',
        ]);

        $result = $this->resolver->resolveStaff('Ali');

        $this->assertNotNull($result);
        $this->assertSame($staff->id, $result->id);
    }

    public function test_resolve_staff_by_username(): void
    {
        $staff = Staff::factory()->create([
            'full_name' => 'Ali Valiyev',
            'username' => 'ali_telegram',
            'status' => 'active',
        ]);

        $result = $this->resolver->resolveStaff(
            'ali_telegram'
        );

        $this->assertNotNull($result);
        $this->assertSame($staff->id, $result->id);
    }

    public function test_resolve_staff_by_login(): void
    {
        $staff = Staff::factory()->create([
            'full_name' => 'Ali Valiyev',
            'login' => 'ali_login',
            'status' => 'active',
        ]);

        $result = $this->resolver->resolveStaff(
            'ali_login'
        );

        $this->assertNotNull($result);
        $this->assertSame($staff->id, $result->id);
    }

    public function test_resolve_staff_returns_null_when_name_is_empty(): void
    {
        Staff::factory()->create([
            'status' => 'active',
        ]);

        $this->assertNull(
            $this->resolver->resolveStaff(null)
        );

        $this->assertNull(
            $this->resolver->resolveStaff('')
        );
    }

    public function test_resolve_staff_ignores_inactive_staff(): void
    {
        $staff = Staff::factory()->create([
            'full_name' => 'Ali Valiyev',
            'status' => 'inactive',
        ]);

        $result = $this->resolver->resolveStaff(
            'Ali Valiyev'
        );

        $this->assertNull($result);
    }

    public function test_resolve_staff_can_be_restricted_to_group(): void
    {
        $group = Group::factory()->create();

        $staff = Staff::factory()->create([
            'full_name' => 'Ali Valiyev',
            'status' => 'active',
        ]);

        $group->staff()->attach($staff->id, [
            'status' => 'active',
        ]);

        $result = $this->resolver->resolveStaff(
            name: 'Ali Valiyev',
            group: $group,
        );

        $this->assertNotNull($result);
        $this->assertSame($staff->id, $result->id);
    }

    public function test_resolve_staff_returns_null_when_staff_is_not_in_group(): void
    {
        $group = Group::factory()->create();

        Staff::factory()->create([
            'full_name' => 'Ali Valiyev',
            'status' => 'active',
        ]);

        $result = $this->resolver->resolveStaff(
            name: 'Ali Valiyev',
            group: $group,
        );

        $this->assertNull($result);
    }

    public function test_resolve_staff_ignores_inactive_group_membership(): void
    {
        $group = Group::factory()->create();

        $staff = Staff::factory()->create([
            'full_name' => 'Ali Valiyev',
            'status' => 'active',
        ]);

        $group->staff()->attach($staff->id, [
            'status' => 'inactive',
        ]);

        $result = $this->resolver->resolveStaff(
            name: 'Ali Valiyev',
            group: $group,
        );

        $this->assertNull($result);
    }

    public function test_resolve_staff_requires_staff_to_be_active_even_when_in_group(): void
    {
        $group = Group::factory()->create();

        $staff = Staff::factory()->create([
            'full_name' => 'Ali Valiyev',
            'status' => 'inactive',
        ]);

        $group->staff()->attach($staff->id, [
            'status' => 'active',
        ]);

        $result = $this->resolver->resolveStaff(
            name: 'Ali Valiyev',
            group: $group,
        );

        $this->assertNull($result);
    }
}
