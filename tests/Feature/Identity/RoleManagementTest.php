<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Institution\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_built_in_and_own_custom_roles_only(): void
    {
        $institution = $this->institution();
        $other = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        Role::query()->create(['name' => 'other_custom', 'guard_name' => 'web', 'institution_id' => $other->id, 'is_system' => false]);

        $names = collect($this->api('GET', 'roles', $admin)->assertOk()->json('data'))->pluck('name');

        $this->assertContains('institution_admin', $names);
        $this->assertContains('librarian', $names);
        $this->assertNotContains('other_custom', $names);
        $this->assertCount(12, $names);
    }

    public function test_permissions_catalogue_is_grouped_by_module(): void
    {
        $admin = $this->member($this->institution(), ['institution_admin']);

        $modules = collect($this->api('GET', 'permissions', $admin)->assertOk()->json('data'))->pluck('module');

        $this->assertEqualsCanonicalizing(['academics', 'admin', 'admissions', 'assessment', 'attendance', 'exams', 'qbank', 'communication', 'hr', 'identity', 'institution', 'students', 'timetable'], $modules->all());
    }

    public function test_create_update_and_delete_custom_role(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        $role = $this->api('POST', 'roles', $admin, [
            'display_name' => 'Exam Cell Clerk',
            'description' => 'Handles exam paperwork',
            'permissions' => ['identity.users.view'],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'exam_cell_clerk')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.permissions', ['identity.users.view'])
            ->json('data');

        $this->api('POST', 'roles', $admin, ['display_name' => 'Exam Cell Clerk', 'permissions' => []])->assertStatus(409);
        $this->api('POST', 'roles', $admin, ['display_name' => 'Faculty', 'permissions' => []])->assertStatus(422)->assertJsonPath('code', 'ROLE_NAME_RESERVED');

        $this->api('PATCH', 'roles/'.$role['id'], $admin, ['permissions' => ['identity.users.view', 'identity.roles.view']])
            ->assertOk()->assertJsonPath('data.permissions', ['identity.roles.view', 'identity.users.view']);

        $this->assertDatabaseHas('security_events', ['event' => 'role_permissions_changed']);
        $this->api('DELETE', 'roles/'.$role['id'], $admin)->assertOk();
    }

    public function test_built_in_roles_are_read_only(): void
    {
        $admin = $this->member($this->institution(), ['institution_admin']);
        $faculty = Role::query()->where('name', 'faculty')->firstOrFail();

        $this->api('PATCH', 'roles/'.$faculty->ulid, $admin, ['display_name' => 'Teacher'])->assertForbidden()->assertJsonPath('code', 'SYSTEM_ROLE_READ_ONLY');
        $this->api('DELETE', 'roles/'.$faculty->ulid, $admin)->assertForbidden();
    }

    public function test_cannot_create_role_with_permissions_you_lack(): void
    {
        $institution = $this->institution();
        $hr = $this->member($institution, ['hr_officer']);
        $role = Role::query()->where('name', 'hr_officer')->firstOrFail();
        $role->givePermissionTo('identity.roles.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->api('POST', 'roles', $hr, ['display_name' => 'Super', 'permissions' => ['admin.settings.manage']])
            ->assertForbidden()->assertJsonPath('code', 'PRIVILEGE_ESCALATION')
            ->assertJsonPath('context.missing_permissions', ['admin.settings.manage']);
    }

    public function test_assign_and_revoke_role_changes_permissions(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $person = $this->member($institution, ['staff']);
        $hrRole = Role::query()->where('name', 'hr_officer')->firstOrFail();

        $this->api('GET', 'users', $person)->assertForbidden();

        $assignment = $this->api('POST', "users/{$person->ulid}/role-assignments", $admin, ['role_id' => $hrRole->ulid, 'reason' => 'Joined HR'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->json('data');

        $this->api('POST', "users/{$person->ulid}/role-assignments", $admin, ['role_id' => $hrRole->ulid])->assertStatus(409);
        $this->api('GET', 'users', $person)->assertOk();

        $this->api('DELETE', 'role-assignments/'.$assignment['id'], $admin, ['reason' => 'Moved'])
            ->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->api('GET', 'users', $person)->assertForbidden();
    }

    public function test_cannot_change_own_roles(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $role = Role::query()->where('name', 'faculty')->firstOrFail();

        $this->api('POST', "users/{$admin->ulid}/role-assignments", $admin, ['role_id' => $role->ulid])
            ->assertForbidden()->assertJsonPath('code', 'SELF_ROLE_CHANGE');
    }

    public function test_department_scope_only_for_department_roles(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $person = $this->member($institution, ['faculty']);
        $dept = $this->api('POST', 'departments', $admin, ['code' => 'CSE', 'name' => 'CSE'])->json('data.id');
        $deptId = Department::query()->withoutGlobalScopes()->where('ulid', $dept)->value('id');

        $this->api('POST', "users/{$person->ulid}/role-assignments", $admin, ['role_id' => Role::query()->where('name', 'librarian')->value('ulid'), 'department_id' => $dept])
            ->assertStatus(422)->assertJsonPath('code', 'ROLE_NOT_DEPARTMENT_SCOPED');

        $this->api('POST', "users/{$person->ulid}/role-assignments", $admin, ['role_id' => Role::query()->where('name', 'hod')->value('ulid'), 'department_id' => $dept])
            ->assertCreated()->assertJsonPath('data.department.code', 'CSE');

        $this->assertSame([$deptId], $person->departmentIdsForRole('hod', $institution->id));
    }

    public function test_time_limited_role_expires(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $person = $this->member($institution, ['faculty']);
        $role = Role::query()->where('name', 'hr_officer')->firstOrFail();

        $this->api('POST', "users/{$person->ulid}/role-assignments", $admin, [
            'role_id' => $role->ulid,
            'valid_until' => now()->addDays(2)->toIso8601String(),
        ])->assertCreated();

        $this->api('GET', 'users', $person)->assertOk();

        $this->travel(2)->days();
        $this->travel(1)->minutes();
        $this->artisan('campusos:sync-role-assignments')->assertSuccessful();

        $this->assertSame('expired', RoleAssignment::query()->withoutGlobalScopes()->where('role_id', $role->id)->first()->status());
        $this->tokens = [];
        $this->api('GET', 'users', $person)->assertForbidden();
    }

    public function test_role_assignments_of_another_institution_are_hidden(): void
    {
        $institution = $this->institution();
        $other = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $outsider = $this->member($other, ['faculty']);
        $foreign = $outsider->roleAssignments()->withoutGlobalScopes()->firstOrFail();

        $this->api('DELETE', 'role-assignments/'.$foreign->ulid, $admin)->assertNotFound();
        $this->assertCount(1, $this->api('GET', 'role-assignments', $admin)->assertOk()->json('data'));
    }
}
