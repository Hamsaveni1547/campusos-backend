<?php

namespace Tests\Feature\Assets;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_maintenance_flow_and_room_booking(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $office = $this->member($institution, ['admin_staff'], ['email' => 'office@example.edu']);
        $this->api('POST', 'employees', $admin, ['name' => 'Dr. Kavya', 'email' => 'kavya@example.edu', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false]);
        $kavya = User::query()->where('email', 'kavya@example.edu')->firstOrFail();
        $kavya->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();
        $student = $this->member($institution, ['student'], ['email' => 'stu@example.edu']);

        $lab = $this->api('POST', 'rooms', $admin, ['code' => 'CS-LAB1', 'name' => 'Programming Lab', 'type' => 'laboratory', 'capacity' => 36])->assertCreated()->json('data.id');

        $asset = $this->api('POST', 'assets', $office, ['tag' => 'BIT-PC-001', 'name' => 'Desktop computer', 'category' => 'Computers', 'room_id' => $lab, 'make' => 'Dell', 'model' => 'OptiPlex 7010', 'cost' => 52000, 'warranty_until' => now()->addDays(30)->toDateString()])
            ->assertCreated()->assertJsonPath('data.status', 'working')->json('data.id');
        $this->api('POST', 'assets', $office, ['tag' => 'BIT-PC-001', 'name' => 'x', 'category' => 'x'])->assertStatus(422);
        $this->api('POST', 'assets', $kavya, ['tag' => 'X', 'name' => 'x', 'category' => 'x'])->assertForbidden();
        $this->api('GET', 'assets/summary', $office)->assertJsonPath('data.warranty_expiring', 1)->assertJsonPath('data.value', 52000);

        // A teacher reports a fault; the office works on it and resolves it.
        $request = $this->api('POST', 'maintenance-requests', $kavya, ['title' => 'PC does not boot', 'priority' => 'high', 'asset' => 'BIT-PC-001'])->assertCreated()->json('data.id');
        $this->api('POST', 'maintenance-requests', $student, ['title' => 'x', 'priority' => 'low'])->assertForbidden();
        $this->api('GET', 'maintenance-requests', $kavya)->assertJsonCount(1, 'data')->assertJsonPath('data.0.room.code', 'CS-LAB1');
        $this->api('PATCH', 'maintenance-requests/'.$request, $office, ['status' => 'in_progress'])->assertStatus(422)->assertJsonPath('code', 'ASSIGNEE_REQUIRED');
        $this->api('PATCH', 'maintenance-requests/'.$request, $office, ['status' => 'in_progress', 'assigned_to' => 'Ravi (IT support)'])->assertOk();
        $this->api('GET', 'assets/'.$asset, $office)->assertJsonPath('data.status', 'under_repair');
        $this->api('PATCH', 'maintenance-requests/'.$request, $office, ['status' => 'resolved', 'resolution' => 'Replaced the power supply', 'cost' => 1800])->assertOk();
        $this->api('GET', 'assets/'.$asset, $office)->assertJsonPath('data.status', 'working')->assertJsonPath('data.requests.0.cost', 1800);
        $this->api('PATCH', 'maintenance-requests/'.$request, $kavya, ['status' => 'closed'])->assertForbidden();
        $this->api('PATCH', 'maintenance-requests/'.$request, $office, ['status' => 'closed'])->assertOk();

        // Booking the lab: clashes are refused.
        $day = now()->addDays(3)->toDateString();
        $this->api('POST', 'room-bookings', $kavya, ['room_id' => $lab, 'date' => $day, 'starts_at' => '14:00', 'ends_at' => '16:00', 'purpose' => 'Project review'])->assertCreated();
        $this->api('POST', 'room-bookings', $office, ['room_id' => $lab, 'date' => $day, 'starts_at' => '15:00', 'ends_at' => '17:00', 'purpose' => 'x'])->assertStatus(409)->assertJsonPath('code', 'ROOM_BUSY');
        $this->api('POST', 'room-bookings', $student, ['room_id' => $lab, 'date' => $day, 'starts_at' => '09:00', 'ends_at' => '10:00', 'purpose' => 'x'])->assertForbidden();
        $booking = $this->api('GET', 'room-bookings?filter[mine]=1', $kavya)->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_cancel', true)->json('data.0.id');
        $this->api('POST', 'room-bookings/'.$booking.'/cancel', $kavya)->assertOk();
        $this->api('POST', 'room-bookings', $office, ['room_id' => $lab, 'date' => $day, 'starts_at' => '15:00', 'ends_at' => '17:00', 'purpose' => 'Workshop'])->assertCreated();
    }
}
