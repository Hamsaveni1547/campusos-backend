Add Phase 1: institution academic structure and staff records.

- API: `/departments` (+ `/hod`), `/programmes`, `/academic-context`, `/academic-years` (+ `make-current`, `close`), `/terms` (+ `make-current`, `close`), `/calendar-events`, `/batches` (+ `/sections`), `/sections/{id}`, `/rooms`, `/designations`, `/employees` (+ `/status`, `/qualifications`), `/employee-qualifications/{id}`, `/me/employee-profile`; import type `employees`.
- Changed: role assignments now take and return the department by public id (`department_id` ULID in requests, `department { id, code, name }` in responses; filter `filter[department]`).
- Rules: no overlapping academic years or terms, one current year and term, closed periods read-only, delete only unused records, HOD must be active teaching staff of the department, staff exit removes institution access.
- DB: `departments`, `programmes`, `academic_years`, `terms`, `batches`, `sections`, `rooms`, `calendar_events`, `designations`, `employees`, `employee_qualifications`; FK `role_assignments.department_id → departments`.
- Permissions: `institution.departments|programmes|calendar|batches|rooms.manage`, `hr.employees.view|manage|import`, `hr.designations.manage`. Run `php artisan campusos:sync-permissions`.
- Settings: group `hr` with `employee_code_format` (default `{INST}EMP{SEQ:4}`).
- Demo data: departments, programmes, batches with sections, 2026-27 with current odd semester, rooms, designations and staff records for the demo accounts.
