<?php

namespace App\Modules\Institution\Http\Controllers;

use App\Modules\Institution\Actions\ManageInstitutions;
use App\Modules\Institution\Authorization\InstitutionPermissions;
use App\Modules\Institution\Http\Requests\InstitutionAttributes;
use App\Modules\Institution\Http\Resources\InstitutionResource;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Files\StoredFile;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * @tags Institution
 */
class CurrentInstitutionController extends Controller
{
    public function __construct(private CurrentInstitution $currentInstitution) {}

    /**
     * The institution selected for this request.
     */
    public function show(): InstitutionResource
    {
        return new InstitutionResource($this->currentInstitution->require()->load('parent'));
    }

    /**
     * Update the institution profile. The code and type are managed by the platform.
     */
    public function update(Request $request, ManageInstitutions $institutions): InstitutionResource
    {
        Gate::authorize(InstitutionPermissions::ProfileManage);
        $institution = $this->currentInstitution->require();

        $rules = collect(InstitutionAttributes::rules(false, $institution->id))->except(['code', 'type'])->all();
        $rules['logo_file_id'] = ['sometimes', 'nullable', 'string', 'size:26'];

        $validated = $request->validate($rules);

        if (array_key_exists('logo_file_id', $validated)) {
            $validated['logo_file_id'] = $this->resolveLogo($request, $validated['logo_file_id']);
        }

        return new InstitutionResource($institutions->update($institution, $validated)->load('parent'));
    }

    private function resolveLogo(Request $request, ?string $fileUlid): ?int
    {
        if ($fileUlid === null) {
            return null;
        }

        $file = StoredFile::query()
            ->where('ulid', $fileUlid)
            ->where('purpose', 'institution_logo')
            ->where('uploaded_by', $request->user()->id)
            ->where('institution_id', $this->currentInstitution->id())
            ->first();

        if ($file === null) {
            throw new BusinessRuleException('Upload the logo with purpose "institution_logo" first.', 'LOGO_FILE_INVALID', 422);
        }

        $file->attachable()->associate($this->currentInstitution->require())->save();

        return $file->id;
    }
}
