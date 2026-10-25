<?php

namespace App\Modules\Institution\Http\Controllers;

use App\Modules\Institution\Authorization\InstitutionPermissions;
use App\Modules\Institution\Enums\RecordStatus;
use App\Modules\Institution\Enums\RoomType;
use App\Modules\Institution\Http\Resources\AcademicResources;
use App\Modules\Institution\Models\Department;
use App\Modules\Institution\Models\Room;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Rooms
 */
class RoomController extends Controller
{
    /**
     * Rooms. Filters: `filter[type]`, `filter[department]` (id), `filter[building]`, `filter[status]`, `filter[min_capacity]`, `filter[search]`.
     */
    public function index(Request $request): JsonResponse
    {
        $rooms = Room::query()
            ->with('department')
            ->when($request->input('filter.type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->input('filter.department'), fn ($query, $id) => $query->whereHas('department', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.building'), fn ($query, $building) => $query->where('building', $building))
            ->when($request->input('filter.status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->input('filter.min_capacity'), fn ($query, $capacity) => $query->where('capacity', '>=', (int) $capacity))
            ->when($request->input('filter.search'), fn ($query, $search) => $query->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('building')
            ->orderBy('code')
            ->paginate(min((int) $request->integer('per_page', 50), 200));

        return ApiResponse::paginated($rooms, fn (Room $room): array => AcademicResources::room($room));
    }

    public function show(Room $room): JsonResponse
    {
        return ApiResponse::data(AcademicResources::room($room->load('department')));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::RoomsManage);
        $room = Room::query()->create($this->attributes($request));

        return ApiResponse::data(AcademicResources::room($room->load('department')), 201);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::RoomsManage);
        $room->update($this->attributes($request, $room));

        return ApiResponse::data(AcademicResources::room($room->load('department')));
    }

    public function destroy(Room $room): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::RoomsManage);
        $room->delete();

        return ApiResponse::message('Room deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Request $request, ?Room $room = null): array
    {
        $required = $room === null ? 'required' : 'sometimes';

        $validated = $request->validate([
            'code' => [$required, 'string', 'max:30', Rule::unique('rooms', 'code')->where('institution_id', app(CurrentInstitution::class)->id())->ignore($room?->id)],
            'name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'building' => ['sometimes', 'nullable', 'string', 'max:100'],
            'floor' => ['sometimes', 'nullable', 'string', 'max:20'],
            'type' => [$required, Rule::enum(RoomType::class)],
            'capacity' => [$required, 'integer', 'min:0', 'max:5000'],
            'status' => ['sometimes', Rule::enum(RecordStatus::class)],
            /** Owning department (optional; common rooms have none). */
            'department_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        if (array_key_exists('department_id', $validated) && $validated['department_id'] !== null) {
            $validated['department_id'] = Department::query()->where('ulid', $validated['department_id'])->value('id')
                ?? throw new BusinessRuleException('Department not found.', 'DEPARTMENT_INVALID', 422);
        }

        return $validated;
    }
}
