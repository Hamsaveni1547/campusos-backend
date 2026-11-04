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
