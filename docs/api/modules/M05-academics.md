# M05 Curriculum & teaching — API notes for the frontend

All routes are under `/api/v1` with a bearer token and `X-Institution`. Option lists: `GET academics/options` (`course_types`, `components`, `curriculum_statuses`, `categories`).

## Who can do what

| Role | Permissions |
|---|---|
| Institution admin | `academics.*` |
| Institution head | view, approve curricula |
| Head of department | view, manage courses/syllabus, prepare curricula, offer courses, allocate faculty, track syllabus, share materials |
| Admin staff | view, offer courses |
| Faculty | view, track syllabus and share materials **for their allocated classes** |
| Student | `me/courses` and their own section's classes |

## Courses and syllabus

- `GET courses` (filters `search, department, type, status, regulation`) · `GET courses/{id}` (with `units[].topics[]`, `textbooks`, `description`).
- `POST courses` / `PATCH courses/{id}`: `department_id, code (upper-cased), title, type, credits, lecture_hours, tutorial_hours, practical_hours, regulation, description, status`. `DELETE` only when unused.
- `PUT courses/{id}/syllabus`: `units[]: {title, hours, topics[]: {title, hours}}`, `textbooks[]`, `description`. Replaces the whole syllabus; refused (`SYLLABUS_IN_USE`) once topics are marked taught.

## Curriculum (scheme)

- `GET curricula` (filters `programme, status`) · `GET curricula/{id}` (`entries[]` with course, `credits_by_term`).
- `POST curricula` `{programme_id, regulation, copy_from?}` → new draft, version = last + 1.
- `PUT curricula/{id}/entries` `{entries[]: {course_id, term_number, category, is_elective}}` (draft only).
- `POST curricula/{id}/submit` → submitted. `POST curricula/{id}/decide` `{approve, remarks}` (remarks required to return) → approved (older approved version of the same regulation is archived) or back to draft.
- `DELETE` drafts only.

## Offerings and allocation (current term by default)

- `GET course-offerings` (filters `term, department, section, search, unallocated=1`) — each with `sections[]` (class id, section, batch, `faculty[]`, `coverage_percent`).
- `POST course-offerings` `{course_id, term_id, section_ids[], is_elective, capacity}` · `PATCH` (`section_ids` syncs; a section with faculty or progress cannot be removed) · `DELETE`.
- `POST course-offerings/generate` `{curriculum_id (approved), term_id, term_number, section_ids[]}` → offers every course of that curriculum term; `{created, skipped}`.
- `POST offering-sections/{class}/allocations` `{employee_id, component: theory|lab|tutorial, weekly_hours}` → returns `warning` when the teacher goes over the weekly limit (setting `academics.max_weekly_hours`, default 18). `DELETE faculty-allocations/{id}`.
- `GET faculty-workload` (filters `term, department`) → per teacher hours, `over_limit`, classes.

## Teaching

- `GET me/teaching` (teacher) → classes, weekly hours, coverage %.
- `GET me/courses` (student) → courses of their section this term with teachers and coverage %.
- `GET offering-sections/{class}` → syllabus with `covered_on/covered_by` per topic, materials (students see published only), `can_teach`.
- `POST offering-sections/{class}/coverage` `{topic_id, covered_on?, notes?}` · `DELETE .../coverage/{topic}`.
- `POST offering-sections/{class}/materials` `{title, unit_id?, file_id (purpose document) | url, is_published?}` · `DELETE .../materials/{id}`.

Only teachers allocated to the class (or people who manage offerings) can track coverage and share materials.

## Later

CO/PO outcomes and attainment (with assessments), elective registration (with term registration).

## Error codes

`SYLLABUS_IN_USE`, `CURRICULUM_LOCKED`, `CURRICULUM_EMPTY`, `CURRICULUM_NOT_APPROVED`, `TERM_OUT_OF_RANGE`, `INVALID_STATUS`, `REMARKS_REQUIRED`, `OFFERING_EXISTS`, `TERM_CLOSED`, `SECTION_MISMATCH`, `SECTION_INVALID`, `SECTION_IN_USE`, `FACULTY_NOT_ELIGIBLE`, `FACULTY_INVALID`, `ALREADY_ALLOCATED`, `TOPIC_INVALID`, `FILE_INVALID`, `COURSE_INVALID`, `DEPARTMENT_INVALID`, `PROGRAMME_INVALID`, `IN_USE`.
