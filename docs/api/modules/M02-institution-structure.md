# M02 — Institution structure (API notes)

Every member of the institution can **read** these endpoints. Changes need the permission shown. Lists return `{ data: [...] }`; rooms are paginated (`meta`). Ids are ULIDs.

## Departments [institution.departments.manage]
| Method | Path | Notes |
|--------|------|-------|
| GET | `/departments` | `filter[type]` academic\|administrative, `filter[status]`, `filter[search]`; includes `programmes_count`, `active_staff_count` |
| GET | `/departments/{id}` | adds `hod { user_id, name, since }` |
| POST / PATCH / DELETE | `/departments`, `/departments/{id}` | `code` (unique), `name`, `type`, `status`, `description`, `email`, `phone`, `established_year`. Deactivation is blocked while active programmes/staff exist; delete only when unused (`IN_USE`) |
| POST | `/departments/{id}/hod` | `{ user_id }` — must be active teaching staff of the department (`HOD_NOT_ELIGIBLE`). Replaces the current HOD |
| DELETE | `/departments/{id}/hod` | remove HOD |

## Programmes [institution.programmes.manage]
`GET/POST /programmes`, `GET/PATCH/DELETE /programmes/{id}` — `department_id`, `code`, `name`, `level` (ug\|pg\|diploma\|doctoral\|certificate), `duration_years`, `total_terms` (default ×2), `intake_capacity`, `regulation`, `status`. Filters: `department`, `level`, `status`, `search`.

## Academic calendar [institution.calendar.manage]
| Method | Path | Notes |
|--------|------|-------|
| GET | `/academic-context` | `{ academic_year, term }` currently in force — call once after sign-in |
| GET/POST | `/academic-years` | `name` ("2026-27"), `starts_on`, `ends_on`; years cannot overlap |
| GET/PATCH/DELETE | `/academic-years/{id}` | delete only while `planned` |
| POST | `/academic-years/{id}/make-current` · `/close` | close requires all terms closed |
| GET/POST | `/terms` | `academic_year_id`, `type` (odd\|even\|annual\|summer, one each per year), `name`, `starts_on`, `ends_on` (inside the year, no overlap) |
| PATCH/DELETE | `/terms/{id}` | closed terms are read-only (`PERIOD_CLOSED`) |
| POST | `/terms/{id}/make-current` · `/close` | making a term current also makes its year current |
| GET | `/calendar-events` | `filter[from]`, `filter[to]` (default: this month), `filter[type]`, `filter[audience]` (students\|staff, includes "all"), `filter[holidays]=1` |
| POST/PATCH/DELETE | `/calendar-events`, `/calendar-events/{id}` | `title`, `type` (holiday\|term_start\|term_end\|examination\|deadline\|event\|other), `starts_on`, `ends_on` (default = start), `audience`, `description` |

## Batches & sections [institution.batches.manage]
| Method | Path | Notes |
|--------|------|-------|
| GET | `/batches` | `filter[programme]`, `filter[department]`, `filter[admission_year]`, `filter[status]`; includes `sections` and `graduation_year` |
| POST | `/batches` | `programme_id`, `admission_year`, `name` (default "CODE YEAR"), `sections: ["A","B"]` |
| PATCH/DELETE | `/batches/{id}` | `name`, `status` (active\|graduated\|discontinued) |
| POST | `/batches/{id}/sections` | `name`, `capacity`, `class_advisor_id` (user id) |
| PATCH/DELETE | `/sections/{id}` | |

## Rooms [institution.rooms.manage]
`GET /rooms` (paginated; `filter[type]`, `filter[department]`, `filter[building]`, `filter[status]`, `filter[min_capacity]`, `filter[search]`), `POST/PATCH/DELETE /rooms[/{id}]` — `code`, `name`, `building`, `floor`, `type` (classroom\|laboratory\|seminar_hall\|exam_hall\|library\|staff_room\|office\|other), `capacity`, `department_id`, `status`.
