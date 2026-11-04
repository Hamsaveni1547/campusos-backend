# M19 Announcements · M20 Dashboard & reports

## Announcements

Permissions: `communication.announcements.publish` (departments, programmes, batches, sections; own announcements), `communication.announcements.publish_all` (also everyone, staff, students, roles; all announcements).

| Method | Path | Notes |
|---|---|---|
| GET | `me/announcements?filter[unread]=1` | My feed, pinned first; paginated |
| GET | `announcements/{id}` | Marks it read for a recipient; publishers also get `status`, `audience` |
| POST | `announcements/{id}/acknowledge` | Only when `requires_ack` |
| GET | `announcements/audiences` | Audience types, roles and categories I may use |
| GET | `announcements?filter[status]=` | Announcements I manage, with `read_count`, `ack_count` |
| POST / PUT / DELETE | `announcements`, `announcements/{id}` | `title`, `body`, `category`, `priority` normal\|important, `audience_type`, `audience_ids[]` (ULIDs or role names), `is_pinned`, `requires_ack`, `expires_on`. Edit and delete drafts only |
| POST | `announcements/{id}/publish` | Fixes recipients and notifies them (template `communication.announcement`) |
| POST | `announcements/{id}/withdraw` | Hides it from feeds |
| GET | `announcements/{id}/read-stats` | Counts and the first 200 who have not read/acknowledged |

Errors: `NOT_DRAFT`, `NOT_PUBLISHED`, `EMPTY_AUDIENCE`, `NO_ACK_NEEDED`.

## Dashboard

`GET me/dashboard` → `cards[]`: `key`, `label`, `value`, `format` (number, money, percent, text), `tone`, `hint`, `link` (screen name). Personal cards come from the user's own student/staff records; management cards follow permissions.

## Reports

| Method | Path | Notes |
|---|---|---|
| GET | `reports` | Catalogue filtered by permission: `key`, `label`, `module`, `description`, `filters`, `columns` |
| GET | `reports/{key}?filter[programme|exam_cycle|from|to]=` | `columns`, `rows`, `applied_filters`, `generated_at`, `generated_by` |

| Key | Permission | Filters |
|---|---|---|
| fee-defaulters | finance.reports.view | programme |
| fee-collection | finance.reports.view | from, to |
| results-summary | exams.reports.view | exam_cycle (latest with results by default) |
| admissions-summary | admissions.applications.view | — |
| staff-headcount | hr.employees.view | — |
| leave-register | leave.reports.view | from, to |
| library-overdue | library.reports.view | — |
| complaints-summary | feedback.complaints.handle | — |

## E1 additions

- Announcements take `publish_at`; publishing a draft with a future time sets status `scheduled`. `campusos:alerts announcements` (every minute) sends them. Withdrawing a scheduled one returns it to draft.
- `announcements/audiences` returns `limits.departments` and `limits.sections` (ULIDs, or null for no limit). Posting outside them fails with `OUT_OF_SCOPE` (403).
- Leave applications and mentoring allocation/at-risk lists are limited to a HOD's department.
- Notifications: `exams.results_published`, `timetable.published`, `attendance.shortage` (weekly, `campusos:alerts attendance`), `finance.fee_reminder` (daily, `campusos:alerts fees`). All templates can be edited.

## Simulated online payment (demo)

| Method | Path | Notes |
|---|---|---|
| GET | `me/online-payments` | My attempts |
| POST | `me/online-payments` | `amount?` (default everything due), `demand_ids[]?` → pending attempt |
| POST | `me/online-payments/{id}/complete` | `method` upi\|card\|netbanking, `outcome` success\|failure. A success records a normal fee receipt |

Errors: `NOTHING_DUE`, `OVERPAYMENT`, `ALREADY_COMPLETED`.

## E2 additions

- **Attachments:** upload with `POST files` (`purpose=attachment`), then pass `attachment_ids[]` (up to 5) when raising a complaint (`POST me/complaints`) or saving an announcement. The complaint and announcement responses include `attachments[]` (`id`, `name`, `mime_type`, `size_bytes`). A file can be downloaded by anyone who may see its complaint or announcement. Errors: `FILE_INVALID`, `TOO_MANY_FILES`.
- **Library import:** import type `library_books` on the shared imports API. Columns: `title`, `authors`; optional `isbn`, `publisher`, `edition`, `published_year`, `subject`, `call_number`, `accession_nos` (separated by `;`), `shelf`. A row whose ISBN already exists adds copies to that book.
- **Timetable calendar:** `GET me/timetable/calendar-link` returns a private signed URL to `calendar/{campus}/{member}/timetable.ics` (iCalendar, weekly repeats for the current term).
