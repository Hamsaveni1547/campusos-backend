# M04 Students — API notes for the frontend

All routes are under `/api/v1` with a bearer token and `X-Institution`.

## Where students come from

- **Admissions**: `POST applications/{id}/confirm` creates the student (register number from the application, guardian, verified documents copied).
- **Manual**: `POST students` (existing students, lateral entry).
- **CSV import**: `POST imports` with `type=students`. Required columns `name, email, programme_code, admission_type, admitted_on`; optional `register_no, mobile, batch (name), section, roll_no, current_term_number, category, date_of_birth, gender, guardian_name, guardian_phone`.

Each student gets the Student role. The batch is picked automatically from programme + admission year when it exists.

## Who sees what

| Role | Access |
|---|---|
| Institution admin, Admin staff | everything (`students.*`) |
| Institution head | view all |
| Head of department | view, verify documents, approve corrections — **own department only** |
| Faculty | view — **own department only** |
| Student | own record via `me/student` |

Department limits come from the role assignment's department. A student outside the scope returns 404.
The identity number is shown in full only to people who can edit records (`students.records.update`); others see it masked.

## Staff

| Call | Permission |
|---|---|
| `GET students` — filters `search`, `programme`, `department`, `batch`, `section`, `status` (default: enrolled only; `all` or a comma list), `admission_type`, `category`; sort `name`, `register_no`, `roll_no`, `-admitted_on`; `per_page` ≤ 200 | `students.records.view` |
| `GET students/{id}` (detail with guardians, documents, status history, change requests) · `GET students/summary` (counts by status and programme) | `students.records.view` |
| `POST students` (`name, email, mobile?, send_invite?, register_no?, programme_id, admission_type, admitted_on`, profile fields, `batch_id?, section_id?, roll_no?`, `guardians[]`) | `students.records.create` |
| `PATCH students/{id}` (`name, mobile`, profile fields; register number cannot change) · `POST students/{id}/placement` (`batch_id, section_id, roll_no, current_term_number`) · `PUT students/{id}/guardians` (`guardians[]`: `relation, name, phone, email, occupation, annual_income, is_primary`) · `POST students/{id}/documents` | `students.records.update` |
| `POST students/{id}/status` (`status, effective_on?, reason`) | `students.status.change` |
| `POST students/{id}/documents/{doc}/review` (`approved`, `remarks` to reject) | `students.documents.verify` |
| `GET student-change-requests?filter[status]=pending` · `POST student-change-requests/{id}/review` (`approved`, `remarks` to reject) | `students.changes.approve` |

Profile fields: `category, date_of_birth, gender, blood_group, nationality, religion, mother_tongue, address, permanent_address, id_proof_type (aadhaar|passport|voter_id|driving_licence|other), id_proof_number`.

## Status flow

`active` ⇄ `on_leave | detained | year_back | suspended`; any of them → `graduated | dropped_out | transferred_out` (final, reason required, Student role removed, `left_on` set). The list hides final statuses unless asked.

## Student self-service

- `GET me/student` — own record plus `self_editable` and `requestable` field lists.
- `PATCH me/student` — `address, permanent_address, blood_group, mother_tongue, photo_file_id` (photo uploaded with purpose `avatar`).
- `POST me/student/documents` — `document_type, file_id` (purpose `document`); waits for verification.
- `POST me/student/change-requests` — `changes{name, date_of_birth, gender, category, nationality, religion, id_proof_type, id_proof_number}`, `reason`. One pending request at a time.

## Not in this phase

Term/course registration (needs M05 courses), certificates and transfers (later phase).

## Error codes

`STUDENT_EXISTS`, `REGISTER_NO_TAKEN`, `REGISTER_NO_LOCKED`, `BATCH_MISMATCH`, `SECTION_MISMATCH`, `ROLL_NO_TAKEN`, `INVALID_STATUS`, `REASON_REQUIRED`, `REMARKS_REQUIRED`, `NO_CHANGES`, `REQUEST_PENDING`, `ALREADY_REVIEWED`, `NOT_A_STUDENT`, `PROGRAMME_INVALID`, `DOCUMENT_FILE_INVALID`, `FILE_INVALID`.
