# M08 Assessment — API notes for the frontend

All routes are under `/api/v1` with a bearer token and `X-Institution`. `GET assessment/options` → `kinds[]`, `mark_statuses[]` (present, absent, exempt), `sheet_statuses[]` (draft, submitted, verified, locked), `bloom_levels[]`, `round_internal`, `co_target_percent`.

## Rules (settings group `assessment`)

`round_internal` (on: final internal mark rounded half up to a whole number), `co_target_percent` (60: marks % a student needs to attain an outcome).

Internal marks per student = sum over parts of: each assessment as marks ÷ max (capped at 1; absent or missing = 0; exempt left out), the best N kept when `best_of` is set, averaged, × part weight. The sheet stores a snapshot of the rules (`rules.groups`, `rules.total`) and each student's `breakdown`.

## Who can do what

| Role | Permissions |
|---|---|
| Institution admin, Head of department | `assessment.*` |
| Faculty | enter marks, set up the scheme and assignments, submit internal marks for classes they teach |
| Admin staff | lock verified marks, reports |
| Institution head | reports |
| Student | own internal marks (once verified) and assignments |

## Course outcomes

`GET courses/{course}/outcomes`; `POST courses/{course}/outcomes` `{code, description, bloom_level?}`, `PATCH|DELETE course-outcomes/{id}` (schemes.manage; delete blocked while assessments are tagged: `OUTCOME_IN_USE`).

## Scheme (per course offering, shared by all its sections)

- `GET offerings/{offering}/scheme` → `can_manage`, `total_weight`, `groups[] {id, name, best_of, weight, assessments[] {id, name, kind, held_on, max_marks, outcome_codes[]}}`.
- `POST offerings/{offering}/assessment-groups` `{name, best_of?, weight}`, `PATCH|DELETE assessment-groups/{id}`.
- `POST assessment-groups/{id}/assessments` `{name, kind, held_on?, max_marks, outcome_codes?[]}`, `PATCH|DELETE assessments/{id}`.
- The scheme cannot change once any section's sheet is submitted (`SCHEME_LOCKED`); unknown outcome codes → `OUTCOME_INVALID`; max below existing marks → `MAX_BELOW_MARKS`.

## Marks and internal marks (per class)

- `GET assessments/{id}/marks?class=` → `editable`, `sheet_status`, `students[] {id, name, roll_no, register_no, mark_id, marks, status}`.
- `PUT assessments/{id}/marks` `{class, marks{student_id: {marks, status}}}` — only while the class sheet is draft (`MARKS_LOCKED`), marks 0..max (`MARKS_OUT_OF_RANGE`).
- `GET classes/{class}/internal-marks` → sheet `status, remarks, rules, computed_at, submitted_by/at, verified_by/at, can_edit, can_verify, can_lock, students[] {computed, final, breakdown}`.
- `POST classes/{class}/internal-marks/compute` (preview), `POST …/submit` (recomputes and submits).
- `GET internal-mark-sheets?status=&term=` → non-draft sheets of the current term by default.
- `POST internal-mark-sheets/{id}/verify` `{approve, remarks?}` — not by the submitter (`SAME_PERSON`); sending back needs remarks and returns the sheet to draft.
- `POST internal-mark-sheets/{id}/lock` — verified sheets only.
- `GET classes/{class}/attainment` → `outcomes[] {code, description, assessments, students, attained, percentage}`.

## Corrections (after submission)

- `POST mark-corrections` `{mark_id, marks?, status, reason}` by the class teacher; one pending per mark (`CORRECTION_PENDING`).
- `GET mark-corrections?status=` — approvers see all, others their own.
- `POST mark-corrections/{id}/decide` `{approve, remarks?}` — approving changes the mark and recomputes that student; the sheet keeps its status.

## Assignments

- `GET offerings/{offering}/assignments` (with `submissions`, `evaluated` counts); `POST` `{title, instructions?, file_id?, due_at, max_marks, allow_late?, assessment_id?}`; `PATCH|DELETE assignments/{id}` (delete blocked once submitted).
- `GET assignments/{id}/submissions`; `POST assignment-submissions/{id}/evaluate` `{marks, feedback?}` — when linked to an assessment, the mark is scaled to its max and saved there.

## Students

- `GET me/internal-marks` → per course `assessments[] {name, max_marks, marks, status}` and `internal {final, out_of, breakdown}` (null until verified).
- `GET me/assignments` → with `can_submit` and own `submission`; `POST me/assignments/{id}/submit` `{file_id?, text?}` (upload the file first through `files`). Past the deadline: `DEADLINE_PASSED` unless late is allowed (then `is_late`); evaluated submissions cannot change.
