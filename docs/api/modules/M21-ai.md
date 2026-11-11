# M21 AI (phases A1, A2)

AI is off until `GROQ_API_KEY` and/or `GEMINI_API_KEY` is set in the backend `.env` (`AI_PROVIDER`, `AI_FALLBACK`; model names via `GROQ_MODEL_FAST/SMART`, `GEMINI_MODEL_FAST/SMART`). Groq is tried first, then Gemini. Settings group `ai` switches each feature and sets the daily limit per person. Every call is logged in `ai_requests` (no prompt or answer text).

Errors: `AI_DISABLED` (404), `AI_LIMIT` (429), `AI_UNAVAILABLE` (503, provider down or rate-limited).

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `ai/status` | signed in | Which features are on |
| GET | `ai/usage?days=` | `ai.usage.view` | Calls by day, feature, provider; tokens; failures |
| POST | `ai/announcements/draft` | announcement publishers | `idea`, `tone` friendly\|formal\|urgent, `audience?` → `title`, `body` |
| POST | `ai/announcements/rewrite` | announcement publishers | `text`, `mode` improve\|shorten\|formal\|kannada\|english → `text` |
| GET | `ai/announcements/{id}/summary?language=en\|kn` | readers of the announcement | `points[]`, cached |
| POST | `ai/complaints/suggest-category` | signed in | `subject`, `description` → `category_id`, `urgency`, `reason` |
| GET | `ai/complaints/{id}/triage` | complaint handlers | `summary`, `urgency`, `next_step` (complainant name never sent) |
| GET | `ai/surveys/{id}/insights` | `feedback.results.view` | `summary`, `sentiment{positive,neutral,negative}`, `themes[]`, `suggestion`; refused below the anonymity minimum |
| GET | `ai/assistant` | `ai.assistant.use` | My conversations and the tools available to me |
| POST | `ai/assistant/messages` | `ai.assistant.use` | `message`, `conversation_id?` → `conversation_id`, `message{role,content,tools}` |
| GET / DELETE | `ai/assistant/conversations/{id}` | owner | Messages / delete |

Assistant tools (read-only, run as the signed-in person, only those that apply to them): `my_attendance`, `my_timetable(date)`, `my_fees`, `my_results`, `my_library_loans`, `classes_to_mark`, `my_dashboard`, `latest_announcements`. Add a tool: implement `App\Modules\AI\Contracts\AssistantTool` and list it in `ToolRegistry::TOOLS`.
