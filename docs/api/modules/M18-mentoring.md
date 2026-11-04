# M18 Mentoring

Permissions: `mentoring.allocate`, `mentoring.mentor`, `mentoring.notes.confidential`, `mentoring.reports.view`.

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `mentoring/assignments?filter[section]=` | allocate / reports | Students of a section with current mentor |
| POST | `mentoring/allocate` | allocate | `section_ids[]`, `mentor_ids[]`; shares unassigned students round-robin by roll number |
| POST | `students/{student}/mentor` | allocate | `mentor_id`, `reason`; closes the current assignment (history kept) |
| GET | `mentoring/at-risk?filter[section]=` | reports | Students with flags and reasons |
| GET | `me/mentees` | mentor | Own mentees with flags |
| GET | `mentees/{student}` | mentor of student, reports | Profile, guardians, attendance, result, fees, notes, concerns, meetings |
| POST | `mentees/{student}/notes` | mentor | `body`, `visibility` shared\|confidential |
| POST | `mentees/{student}/concerns` | mentor | `category`, `description`, `action_plan`, `review_on` |
| PATCH | `student-concerns/{concern}` | mentor | `action_plan`, `review_on`, `resolve`, `outcome` |
| GET/POST | `mentoring-meetings` | mentor | Group meeting: `student_ids[]`, `scheduled_at`, `agenda` |
| PATCH | `mentoring-meetings/{meeting}` | mentor | `status` scheduled\|held\|cancelled, `scheduled_at`, `minutes`, `attended_ids[]` |
| GET | `me/mentor` | student | Mentor and meetings (minutes hidden) |
| POST | `me/mentor/meeting-requests` | student | `agenda` |

Confidential notes are seen only by their author and holders of `mentoring.notes.confidential`. Note text is not written to the audit log.

Errors: `NO_MENTORS`, `MENTOR_INVALID`, `NO_CHANGE`, `NOT_MENTEE`.
