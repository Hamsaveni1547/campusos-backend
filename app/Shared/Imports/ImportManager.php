<?php

namespace App\Shared\Imports;

use App\Models\User;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Files\FileStorage;
use App\Shared\Files\StoredFile;
use App\Shared\Imports\Jobs\ProcessImport;
use App\Shared\Imports\Jobs\ValidateImport;
use App\Shared\Tenancy\CurrentInstitution;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use SplFileObject;
use Throwable;

/**
 * Runs the import lifecycle: create → validate (dry run) → commit.
 */
class ImportManager
{
    private const MAX_REPORTED_ERRORS = 500;

    public function __construct(
        private FileStorage $files,
        private CurrentInstitution $currentInstitution,
    ) {}

    public function importer(string $type): Importer
    {
        $class = config("campusos.importers.{$type}");

        if (! is_string($class)) {
            throw new BusinessRuleException("Unknown import type [{$type}].", 'IMPORT_TYPE_UNKNOWN', 422);
        }

        /** @var Importer */
        return app($class);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function start(string $type, StoredFile $file, User $actor, array $options = []): Import
    {
        $importer = $this->importer($type);

        if (! $actor->can($importer->permission())) {
            throw new BusinessRuleException('You do not have permission to run this import.', 'FORBIDDEN', 403);
        }

        if ($file->purpose !== 'import' || $file->uploaded_by !== $actor->id) {
            throw new BusinessRuleException('Upload the CSV with purpose "import" before starting an import.', 'IMPORT_FILE_INVALID', 422);
        }

        $import = Import::query()->create([
            'institution_id' => $this->currentInstitution->id(),
            'type' => $type,
            'file_id' => $file->id,
            'status' => ImportStatus::Pending,
            'options' => $options,
            'created_by' => $actor->id,
        ]);

        $this->files->attach($file, $import);

        ValidateImport::dispatch($import->id, $this->currentInstitution->id());

        return $import->refresh();
    }

    public function commit(Import $import): Import
    {
        if (! $import->status->canCommit()) {
            throw new BusinessRuleException('Only a validated import without errors can be committed.', 'IMPORT_NOT_COMMITTABLE', 409);
        }

        $import->update(['status' => ImportStatus::Processing, 'started_at' => now()]);

        ProcessImport::dispatch($import->id, $this->currentInstitution->id());

        return $import->refresh();
    }

    public function cancel(Import $import): Import
    {
        if ($import->status->isFinished() || $import->status === ImportStatus::Processing) {
            throw new BusinessRuleException('This import can no longer be cancelled.', 'IMPORT_NOT_CANCELLABLE', 409);
        }

        $import->update(['status' => ImportStatus::Cancelled, 'finished_at' => now()]);

        return $import;
    }

    /**
     * Dry run: checks headers and every row, stores errors. No data is written.
     */
    public function validate(Import $import): void
    {
        $import->update(['status' => ImportStatus::Validating]);
        $importer = $this->importer($import->type);

        try {
            $errors = [];
            $total = 0;
            $valid = 0;
            $context = new ImportRunContext;

            foreach ($this->rows($import, $importer) as $rowNumber => $row) {
                $total++;
                $rowErrors = $this->rowErrors($importer, $row, $context);

                if ($rowErrors === []) {
                    $valid++;

                    continue;
                }

                if (count($errors) < self::MAX_REPORTED_ERRORS) {
                    $errors[] = ['row' => $rowNumber, 'errors' => $rowErrors];
                }
            }

            if ($total === 0) {
                throw new BusinessRuleException('The file has no data rows.', 'IMPORT_EMPTY');
            }

            $import->update([
                'status' => $valid === $total ? ImportStatus::Validated : ImportStatus::Invalid,
                'total_rows' => $total,
                'valid_rows' => $valid,
                'failed_rows' => $total - $valid,
                'errors' => $errors,
                'validated_at' => now(),
                'finished_at' => $valid === $total ? null : now(),
            ]);
        } catch (Throwable $exception) {
            $import->update([
                'status' => ImportStatus::Failed,
                'failure_message' => $exception instanceof BusinessRuleException ? $exception->getMessage() : 'The file could not be read.',
                'finished_at' => now(),
            ]);

            if (! $exception instanceof BusinessRuleException) {
                report($exception);
            }
        }
    }

    /**
     * Commit: re-validates each row and imports it in its own transaction.
     */
    public function process(Import $import): void
    {
        $importer = $this->importer($import->type);
        $actor = User::query()->findOrFail($import->created_by);
        $context = new ImportRunContext;
        $processed = 0;
        $failed = 0;
        $errors = [];

        try {
            foreach ($this->rows($import, $importer) as $rowNumber => $row) {
                $rowErrors = $this->rowErrors($importer, $row, $context);

                if ($rowErrors === []) {
                    try {
                        DB::transaction(fn () => $importer->import($row, $import, $actor));
                        $processed++;

                        continue;
                    } catch (BusinessRuleException $exception) {
                        $rowErrors = [$exception->getMessage()];
                    }
                }

                $failed++;
                if (count($errors) < self::MAX_REPORTED_ERRORS) {
                    $errors[] = ['row' => $rowNumber, 'errors' => $rowErrors];
                }
            }

            $import->update([
                'status' => $failed === 0 ? ImportStatus::Completed : ImportStatus::CompletedWithErrors,
                'processed_rows' => $processed,
                'failed_rows' => $failed,
                'errors' => $errors,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $import->update([
                'status' => ImportStatus::Failed,
                'processed_rows' => $processed,
                'failure_message' => 'The import stopped unexpectedly. Rows already imported were kept.',
                'finished_at' => now(),
            ]);

            report($exception);
        }
    }

    /**
     * @return array{required: array<int, string>, optional: array<int, string>}
     */
    public function template(string $type): array
    {
        $importer = $this->importer($type);

        return ['required' => $importer->requiredColumns(), 'optional' => $importer->optionalColumns()];
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<int, string>
     */
    private function rowErrors(Importer $importer, array $row, ImportRunContext $context): array
    {
        $validator = Validator::make($row, $importer->rules());
        $errors = $validator->fails() ? array_values($validator->errors()->all()) : [];

        return array_merge($errors, $importer->check($row, $context));
    }

    /**
     * Yields rows keyed by their line number in the file (header = line 1).
     *
     * @return Generator<int, array<string, string|null>>
     */
    private function rows(Import $import, Importer $importer): Generator
    {
        $file = new SplFileObject($this->files->absolutePath($import->file()->firstOrFail()));
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);

        $header = null;
        $line = 0;

        foreach ($file as $values) {
            $line++;

            if (! is_array($values) || $values === [null]) {
                continue;
            }

            if ($header === null) {
                $header = array_map(fn ($value): string => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value) ?? '')), $values);
                $missing = array_diff($importer->requiredColumns(), $header);

                if ($missing !== []) {
                    throw new BusinessRuleException('Missing required columns: '.implode(', ', $missing).'.', 'IMPORT_COLUMNS_MISSING');
                }

                continue;
            }

            $row = [];
            foreach ($header as $index => $column) {
                $value = isset($values[$index]) ? trim((string) $values[$index]) : null;
                $row[$column] = $value === '' ? null : $value;
            }

            yield $line => $row;
        }
    }
}
