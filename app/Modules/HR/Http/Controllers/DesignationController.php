<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Authorization\HRPermissions;
use App\Modules\HR\Enums\StaffType;
use App\Modules\HR\Http\EmployeePresenter;
use App\Modules\HR\Models\Designation;
use App\Modules\Institution\Enums\RecordStatus;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Staff
 */
class DesignationController extends Controller
{
    /**
     * Designations (job titles). Filter: `filter[category]` (teaching|non_teaching), `filter[status]`.
     */
    public function index(Request $request): JsonResponse
    {
        $designations = Designation::query()
            ->withCount('employees')
            ->when($request->input('filter.category'), fn ($query, $category) => $query->where('category', $category))
            ->when($request->input('filter.status'), fn ($query, $status) => $query->where('status', $status))
            ->orderBy('category')
            ->orderBy('rank')
            ->orderBy('name')
            ->get();

        return ApiResponse::list($designations, fn (Designation $designation): array => EmployeePresenter::designation($designation));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(HRPermissions::DesignationsManage);
        $designation = Designation::query()->create($request->validate($this->rules()));

        return ApiResponse::data(EmployeePresenter::designation($designation), 201);
    }

    public function update(Request $request, Designation $designation): JsonResponse
    {
        Gate::authorize(HRPermissions::DesignationsManage);
        $designation->update($request->validate($this->rules($designation)));

        return ApiResponse::data(EmployeePresenter::designation($designation));
    }

    public function destroy(Designation $designation): JsonResponse
    {
        Gate::authorize(HRPermissions::DesignationsManage);

        return ApiResponse::deleteUnlessUsed($designation, ['employees' => 'staff'], 'designation');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Designation $designation = null): array
    {
        $required = $designation === null ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100', Rule::unique('designations', 'name')->where('institution_id', app(CurrentInstitution::class)->id())->ignore($designation?->id)],
            'category' => [$required, Rule::enum(StaffType::class)],
            /** Sort order; lower = more senior. */
            'rank' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'status' => ['sometimes', Rule::enum(RecordStatus::class)],
        ];
    }
}
