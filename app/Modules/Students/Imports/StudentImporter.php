<?php

namespace App\Modules\Students\Imports;

use App\Models\User;
use App\Modules\Admissions\Enums\Quota;
use App\Modules\Institution\Models\Batch;
use App\Modules\Institution\Models\Programme;
use App\Modules\Institution\Models\Section;
use App\Modules\Students\Authorization\StudentsPermissions;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Services\StudentService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Imports\Import;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportRunContext;
use Illuminate\Validation\Rule;

/**
 * Bulk import of existing students (legacy data migration).
 */
class StudentImporter implements Importer
{
    public function __construct(private StudentService $students) {}

    public function permission(): string
    {
        return StudentsPermissions::RecordsImport;
    }

    public function requiredColumns(): array
    {
        return ['name', 'email', 'programme_code', 'admission_type', 'admitted_on'];
    }

    public function optionalColumns(): array
    {
        return ['register_no', 'mobile', 'batch', 'section', 'roll_no', 'current_term_number', 'category', 'date_of_birth', 'gender', 'guardian_name', 'guardian_phone'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'programme_code' => ['required', 'string', 'max:20'],
            'admission_type' => ['required', Rule::enum(Quota::class)],
            'admitted_on' => ['required', 'date_format:Y-m-d'],
            'register_no' => ['nullable', 'alpha_dash', 'max:40'],
            'mobile' => ['nullable', 'regex:/^\+?[0-9]{10,15}$/'],
            'batch' => ['nullable', 'string', 'max:150'],
            'section' => ['nullable', 'string', 'max:20'],
            'roll_no' => ['nullable', 'string', 'max:20'],
            'current_term_number' => ['nullable', 'integer', 'min:1', 'max:21'],
            'category' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'gender' => ['nullable', 'in:female,male,other,prefer_not_to_say'],
            'guardian_name' => ['nullable', 'string', 'max:150'],
            'guardian_phone' => ['nullable', 'regex:/^\+?[0-9]{10,15}$/'],
        ];
    }

    public function check(array $row, ImportRunContext $context): array
    {
        $errors = [];

        foreach (['email', 'register_no'] as $column) {
            if ($context->remember($column, $row[$column] ?? null, 0) !== null) {
                $errors[] = "The {$column} appears more than once in the file.";
            }
        }

        $user = User::query()->where('email', mb_strtolower((string) ($row['email'] ?? '')))->first();
        if ($user !== null && Student::query()->where('user_id', $user->id)->exists()) {
            $errors[] = 'This person already has a student record.';
        }

        if (filled($row['register_no'] ?? null) && Student::query()->where('register_no', $row['register_no'])->exists()) {
            $errors[] = 'The register number is already in use.';
        }

        $programme = $this->programme((string) ($row['programme_code'] ?? ''));

        if ($programme === null) {
            $errors[] = "Unknown programme code \"{$row['programme_code']}\".";
        } else {
            [$batch, $section, $placementError] = $this->placement($programme, $row);

            if ($placementError !== null) {
                $errors[] = $placementError;
            }
        }

        return $errors;
    }

    public function import(array $row, Import $import, User $actor): void
    {
        $programme = $this->programme((string) $row['programme_code']) ?? throw new BusinessRuleException('Programme not found.');
        [$batch, $section, $error] = $this->placement($programme, $row);

        if ($error !== null) {
            throw new BusinessRuleException($error);
        }

        $student = $this->students->create(
            ['name' => $row['name'], 'email' => $row['email'], 'mobile' => $row['mobile'] ?? null],
            array_filter([
                'register_no' => $row['register_no'] ?? null,
                'programme_id' => $programme->id,
                'batch_id' => $batch?->id,
                'section_id' => $section?->id,
                'roll_no' => $row['roll_no'] ?? null,
                'current_term_number' => isset($row['current_term_number']) && $row['current_term_number'] !== '' ? (int) $row['current_term_number'] : null,
                'admission_type' => $row['admission_type'],
                'admitted_on' => $row['admitted_on'],
                'category' => $row['category'] ?? null,
                'date_of_birth' => $row['date_of_birth'] ?? null,
                'gender' => $row['gender'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            $actor,
            (bool) ($import->options['send_invite'] ?? true),
            filled($row['guardian_name'] ?? null) ? [['relation' => 'guardian', 'name' => $row['guardian_name'], 'phone' => $row['guardian_phone'] ?? null, 'is_primary' => true]] : [],
        );

        unset($student);
    }

    private function programme(string $code): ?Programme
    {
        return $code === '' ? null : Programme::query()->where('code', $code)->first();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: Batch|null, 1: Section|null, 2: string|null}
     */
    private function placement(Programme $programme, array $row): array
    {
        $batch = null;
        $section = null;

        if (filled($row['batch'] ?? null)) {
            $batch = Batch::query()->where('programme_id', $programme->id)->where('name', $row['batch'])->first();

            if ($batch === null) {
                return [null, null, "Unknown batch \"{$row['batch']}\" for this programme."];
            }
        }

        if (filled($row['section'] ?? null)) {
            $section = $batch !== null ? Section::query()->where('batch_id', $batch->id)->where('name', strtoupper((string) $row['section']))->first() : null;

            if ($section === null) {
                return [$batch, null, "Unknown section \"{$row['section']}\" (give the batch too)."];
            }
        }

        return [$batch, $section, null];
    }
}
