<?php

namespace App\Modules\Institution\Http\Controllers;

use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Institution\Actions\ManageInstitutions;
use App\Modules\Institution\Http\Requests\InstitutionAttributes;
use App\Modules\Institution\Http\Resources\InstitutionResource;
use App\Modules\Institution\Models\Institution;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Platform administration of institutions (tenants).
 *
 * @tags Platform
 */
class PlatformInstitutionController extends Controller
{
    public function __construct(private ManageInstitutions $institutions) {}

    /**
     * All institutions. Filters: `filter[search]`, `filter[status]`, `filter[type]`, `filter[parent]` (id).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $institutions = QueryBuilder::for(Institution::query()->with('parent')->withCount(['users', 'children']))
            ->allowedFilters(...[
                AllowedFilter::callback('search', fn ($query, $value) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$value.'%')->orWhere('code', 'like', '%'.$value.'%'))),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('type'),
                AllowedFilter::callback('parent', fn ($query, $value) => $query->whereHas('parent', fn ($query) => $query->where('ulid', $value))),
            ])
            ->allowedSorts('name', 'code', 'created_at')
            ->defaultSort('name')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->withQueryString();

        return InstitutionResource::collection($institutions);
    }

    /**
     * Provision an institution with its first administrator (invited by email).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge(InstitutionAttributes::rules(true), [
            /** Parent university id, for constituent or affiliated colleges. */
            'parent_id' => ['nullable', 'string', 'size:26', 'exists:institutions,ulid'],
            'admin' => ['required', 'array'],
            'admin.name' => ['required', 'string', 'max:150'],
            'admin.email' => ['required', 'email:rfc', 'max:255'],
            'admin.mobile' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
        ]));

        $attributes = collect($validated)->except(['admin', 'parent_id'])->all();

        if (! empty($validated['parent_id'])) {
            $attributes['parent_id'] = Institution::query()->where('ulid', $validated['parent_id'])->value('id');
        }

        $result = $this->institutions->provision($attributes, $validated['admin'], $request->user());

        return (new InstitutionResource($result['institution']->load('parent')))
            ->additional(['meta' => ['admin' => (new UserResource($result['admin']))->resolve($request)]])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Institution $institution): InstitutionResource
    {
        return new InstitutionResource($institution->load('parent')->loadCount(['users', 'children']));
    }

    public function update(Request $request, Institution $institution): InstitutionResource
    {
        $validated = $request->validate(InstitutionAttributes::rules(false, $institution->id));

        return new InstitutionResource(
            app(CurrentInstitution::class)->runAs($institution, fn () => $this->institutions->update($institution, $validated))
        );
    }

    /**
     * Suspend an institution. Every member is signed out.
     */
    public function suspend(Request $request, Institution $institution): InstitutionResource
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new InstitutionResource($this->institutions->suspend($institution, $validated['reason']));
    }

    public function reactivate(Institution $institution): InstitutionResource
    {
        return new InstitutionResource($this->institutions->reactivate($institution));
    }
}
