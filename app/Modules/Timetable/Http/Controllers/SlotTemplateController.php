<?php

namespace App\Modules\Timetable\Http\Controllers;

use App\Modules\Timetable\Authorization\TimetablePermissions;
use App\Modules\Timetable\Models\SlotTemplate;
use App\Modules\Timetable\Models\TimeSlot;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Period templates (the bell schedule).
 *
 * @tags Timetable: periods
 */
class SlotTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::list(SlotTemplate::query()->with('slots')->orderByDesc('is_default')->orderBy('name')->get(), fn (SlotTemplate $template): array => self::present($template));
    }

    /**
     * `name, working_days[] (1 = Mon … 7 = Sun), is_default, slots[]: {label, starts_at (HH:MM), ends_at, is_break}`.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize(TimetablePermissions::Manage);
        $validated = $request->validate($this->rules());

        $template = DB::transaction(function () use ($validated): SlotTemplate {
            $template = SlotTemplate::query()->create(['name' => $validated['name'], 'working_days' => $this->days($validated['working_days']), 'is_default' => (bool) ($validated['is_default'] ?? false)]);
            $this->saveSlots($template, $validated['slots']);
            $this->keepOneDefault($template);

            return $template;
        });

        return ApiResponse::data(self::present($template->load('slots')), 201);
    }

    /**
     * Change the template. Periods can only be replaced while no timetable uses it.
     */
    public function update(Request $request, SlotTemplate $slotTemplate): JsonResponse
    {
        Gate::authorize(TimetablePermissions::Manage);
        $validated = $request->validate($this->rules($slotTemplate, false));

        DB::transaction(function () use ($validated, $slotTemplate): void {
            $slotTemplate->update(array_filter([
                'name' => $validated['name'] ?? null,
                'working_days' => isset($validated['working_days']) ? $this->days($validated['working_days']) : null,
                'is_default' => $validated['is_default'] ?? null,
            ], fn ($value) => $value !== null));

            if (isset($validated['slots'])) {
                if ($slotTemplate->timetables()->exists()) {
                    throw new BusinessRuleException('Timetables use these periods. Create a new template instead.', 'TEMPLATE_IN_USE', 409);
                }

                $slotTemplate->slots()->delete();
                $this->saveSlots($slotTemplate, $validated['slots']);
            }

            $this->keepOneDefault($slotTemplate);
        });

        return ApiResponse::data(self::present($slotTemplate->refresh()->load('slots')));
    }

    public function destroy(SlotTemplate $slotTemplate): JsonResponse
    {
        Gate::authorize(TimetablePermissions::Manage);

        return ApiResponse::deleteUnlessUsed($slotTemplate, ['timetables' => 'timetables'], 'period template');
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(SlotTemplate $template): array
    {
        return [
            'id' => $template->ulid,
            'name' => $template->name,
            'working_days' => $template->working_days,
            'is_default' => $template->is_default,
            'slots' => $template->slots->map(fn (TimeSlot $slot): array => [
                'id' => $slot->ulid,
                'position' => $slot->position,
                'label' => $slot->label,
                'starts_at' => substr($slot->starts_at, 0, 5),
                'ends_at' => substr($slot->ends_at, 0, 5),
                'is_break' => $slot->is_break,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?SlotTemplate $template = null, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100', Rule::unique('slot_templates', 'name')->where('institution_id', app(CurrentInstitution::class)->id())->ignore($template?->id)],
            'working_days' => [$required, 'array', 'min:1', 'max:7'],
            'working_days.*' => ['integer', 'between:1,7', 'distinct'],
            'is_default' => ['sometimes', 'boolean'],
            'slots' => [$required, 'array', 'min:1', 'max:16'],
            'slots.*.label' => ['required', 'string', 'max:30'],
            'slots.*.starts_at' => ['required', 'date_format:H:i'],
            'slots.*.ends_at' => ['required', 'date_format:H:i'],
            'slots.*.is_break' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  list<array{label: string, starts_at: string, ends_at: string, is_break?: bool}>  $slots
     */
    private function saveSlots(SlotTemplate $template, array $slots): void
    {
        $previousEnd = null;

        foreach ($slots as $index => $slot) {
            if ($slot['ends_at'] <= $slot['starts_at'] || ($previousEnd !== null && $slot['starts_at'] < $previousEnd)) {
                throw new BusinessRuleException('Periods must be in order and must not overlap ('.$slot['label'].').', 'SLOTS_INVALID', 422);
            }

            $previousEnd = $slot['ends_at'];
            $template->slots()->create([
                'institution_id' => $template->institution_id,
                'position' => $index + 1,
                'label' => $slot['label'],
                'starts_at' => $slot['starts_at'],
                'ends_at' => $slot['ends_at'],
                'is_break' => (bool) ($slot['is_break'] ?? false),
            ]);
        }
    }

    /**
     * @param  list<int>  $days
     * @return list<int>
     */
    private function days(array $days): array
    {
        $days = array_map('intval', $days);
        sort($days);

        return array_values(array_unique($days));
    }

    private function keepOneDefault(SlotTemplate $template): void
    {
        if ($template->is_default) {
            SlotTemplate::query()->whereKeyNot($template->id)->update(['is_default' => false]);
        }
    }
}
