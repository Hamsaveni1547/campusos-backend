# M06 Timetable — API notes for the frontend

All routes are under `/api/v1` with a bearer token and `X-Institution`. Days are ISO numbers: 1 = Monday … 7 = Sunday.

## Who can do what

| Role | Permissions |
|---|---|
| Institution admin | `timetable.*` |
| Head of department | view, manage (periods, build), publish, adjust |
| Admin staff | view, manage, adjust |
| Institution head, Faculty | view |
| Student, teacher | `me/timetable` |

## Periods (bell schedule)

- `GET slot-templates` (anyone signed in) · `POST slot-templates` `{name, working_days[], is_default, slots[]: {label, starts_at "HH:MM", ends_at, is_break}}` — periods must be in order without overlap.
- `PATCH slot-templates/{id}` (slots can be replaced only while no timetable uses the template) · `DELETE` when unused.

## Building a section timetable

- `GET timetables` (filters `term` default current, `status`, `programme`) · `POST timetables` `{term_id, section_id, slot_template_id}` (one per section per term).
- `GET timetables/{id}` → `slots`, `working_days`, `entries[]` (`day_of_week, slot_id, class_id, course, component, teacher, room`), `classes[]` (courses of the section with allocated teachers), `planned_hours[]` (periods per week vs allocated hours), `clashes[]`.
- `POST timetables/{id}/entries` `{day_of_week, time_slot_id, offering_section_id, employee_id?, room_id?, component?}` — teacher defaults to the class's allocated teacher. `PATCH|DELETE timetable-entries/{id}`. Draft only.
  Refused with `SECTION_BUSY`, `TEACHER_BUSY`, `ROOM_BUSY` (overlapping time on the same day in the same term), `SLOT_INVALID` (break / other template), `DAY_INVALID`, `CLASS_INVALID`, `TEACHER_NOT_ALLOCATED`.
- `POST timetables/{id}/publish` (refused with `CLASHES` + `context.clashes`, or `TIMETABLE_EMPTY`) · `POST timetables/{id}/unpublish` (back to draft; republishing raises `version`) · `DELETE` drafts.

## Views (published only)

- `GET timetable-view/week?filter[section|teacher|room]=id&filter[term]=` → `entries[]` with `slot, starts_at, ends_at`.
- `GET timetable-view/day?filter[...]=id&date=` → `periods[]` for the date with `status`: `scheduled`, `cancelled`, `substituted`, `extra`, `handed_over` (teacher view when a substitute took the period), `reason`.
- `GET me/timetable?date=` → `as` (student | teacher), `today[]`, `week`.

## Day-to-day changes (`timetable.adjust`)

- `GET timetable-adjustments?filter[from]&filter[to]` (default this week).
- `POST timetable-adjustments` `{entry_id, date, type: cancelled|substituted, substitute_employee_id?, reason?}` — date must fall on that weekday within the term; substitute must be free then (`TEACHER_BUSY`); one change per period per date (`ALREADY_ADJUSTED`).
- `POST timetable-adjustments/extra` `{timetable_id, date, time_slot_id, offering_section_id, employee_id?, room_id?, reason?}` — the section must be free then.
- `DELETE timetable-adjustments/{id}` restores the regular period.

## Later

Automatic generation, teacher availability constraints, lab batches, iCal export, utilisation reports.
