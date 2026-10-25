<?php

namespace App\Shared\Imports;

use App\Models\User;
use App\Shared\Files\StoredFile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Bulk imports: upload a CSV (purpose "import"), start an import (validation runs
 * first, nothing is written), review errors, then commit.
 *
 * @tags Imports
 */
class ImportController extends Controller
{
    public function __construct(private ImportManager $imports) {}

    /**
     * Imports I can see (my own, or all of a type I may run).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $allowedTypes = collect(array_keys((array) config('campusos.importers')))
            ->filter(fn (string $type): bool => $user->can($this->imports->importer($type)->permission()))
            ->values()
            ->all();

        $imports = Import::query()
            ->whereIn('type', $allowedTypes)
            ->when($request->filled('filter.type'), fn ($query) => $query->where('type', $request->input('filter.type')))
            ->with('creator')
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return ImportResource::collection($imports);
    }

    /**
     * Columns for an import type (to build a template file).
     */
    public function template(Request $request, string $type): JsonResponse
    {
        $this->authorizeType($request, $type);

        return response()->json(['data' => $this->imports->template($type)]);
    }

    /**
     * Start an import. Validation runs in the background; poll the import for status.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(array_keys((array) config('campusos.importers')))],
            'file_id' => ['required', 'string', 'size:26'],
            'options' => ['sometimes', 'array'],
        ]);

        $file = StoredFile::query()->where('ulid', $validated['file_id'])->firstOrFail();
        $import = $this->imports->start($validated['type'], $file, $this->user($request), $validated['options'] ?? []);

        return (new ImportResource($import))->response()->setStatusCode(202);
    }

    public function show(Request $request, Import $import): ImportResource
    {
        $this->authorizeType($request, $import->type);

        return new ImportResource($import->load('creator'));
    }

    /**
     * Commit a validated import.
     */
    public function commit(Request $request, Import $import): JsonResponse
    {
        $this->authorizeType($request, $import->type);

        return (new ImportResource($this->imports->commit($import)))->response()->setStatusCode(202);
    }

    public function cancel(Request $request, Import $import): ImportResource
    {
        $this->authorizeType($request, $import->type);

        return new ImportResource($this->imports->cancel($import));
    }

    private function authorizeType(Request $request, string $type): void
    {
        if (! $this->user($request)->can($this->imports->importer($type)->permission())) {
            throw new AuthorizationException;
        }
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
