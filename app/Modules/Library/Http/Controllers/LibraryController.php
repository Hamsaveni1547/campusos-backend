<?php

namespace App\Modules\Library\Http\Controllers;

use App\Models\User;
use App\Modules\Library\Authorization\LibraryPermissions as P;
use App\Modules\Library\Models\LibraryBook;
use App\Modules\Library\Models\LibraryCopy;
use App\Modules\Library\Models\LibraryLoan;
use App\Modules\Library\Services\LibraryService;
use App\Shared\Http\ApiResponse;
use App\Shared\Settings\SettingsRepository;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Library
 */
class LibraryController extends Controller
{
    public function __construct(private LibraryService $library) {}

    /**
     * Loan rules from the settings.
     */
    public function options(SettingsRepository $settings): JsonResponse
    {
        return ApiResponse::data(collect(['student_limit', 'staff_limit', 'student_days', 'staff_days', 'max_renewals', 'fine_per_day'])->mapWithKeys(fn (string $k): array => [$k => (int) $settings->get('library', $k)])->all());
    }

    // ----- catalogue -------------------------------------------------------

    /**
     * Catalogue search (any signed-in member). Filters: `filter[search]` (title, author, ISBN, accession no), `filter[subject]`.
     */
    public function books(Request $request): JsonResponse
    {
        $f = (array) $request->input('filter', []);

        $books = LibraryBook::query()
            ->withCount(['copies' => fn ($q) => $q->whereIn('status', ['available', 'issued']), 'copies as available_count' => fn ($q) => $q->where('status', 'available')])
            ->when($f['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('title', 'like', "%{$term}%")->orWhere('authors', 'like', "%{$term}%")->orWhere('isbn', $term)->orWhereHas('copies', fn ($c) => $c->where('accession_no', $term))))
            ->when($f['subject'] ?? null, fn ($q, $subject) => $q->where('subject', $subject))
            ->orderBy('title')->paginate(25);

        return ApiResponse::paginated($books, fn (LibraryBook $b): array => $this->presentBook($b));
    }

    /**
     * Catalogue fields and copy counts.
     */
    private function presentBook(LibraryBook $book): array
    {
        return [
            'id' => $book->ulid, 'title' => $book->title, 'authors' => $book->authors, 'isbn' => $book->isbn, 'publisher' => $book->publisher,
            'edition' => $book->edition, 'published_year' => $book->published_year, 'subject' => $book->subject, 'call_number' => $book->call_number,
            'copies' => $book->copies_count, 'available' => $book->available_count,
        ];
    }

    public function show(LibraryBook $book): JsonResponse
    {
        $book->loadCount(['copies' => fn ($q) => $q->whereIn('status', ['available', 'issued']), 'copies as available_count' => fn ($q) => $q->where('status', 'available')]);
        $loans = LibraryLoan::query()->with('member')->whereNull('returned_on')->whereIn('library_copy_id', $book->copies()->select('id'))->get()->keyBy('library_copy_id');

        return ApiResponse::data([
            ...$this->presentBook($book),
            'copy_list' => $book->copies()->orderBy('accession_no')->get()->map(fn (LibraryCopy $c): array => [
                'id' => $c->ulid, 'accession_no' => $c->accession_no, 'shelf' => $c->shelf, 'status' => $c->status,
                'due_on' => $loans->get($c->id)?->due_on->toDateString(),
            ])->values(),
        ]);
    }

    public function storeBook(Request $request): JsonResponse
    {
        Gate::authorize(P::CatalogueManage);
        $validated = $this->validatedBook($request) + $request->validate(['copies' => ['nullable', 'array', 'max:50'], 'copies.*.accession_no' => ['required', 'string', 'max:30', 'distinct'], 'copies.*.shelf' => ['nullable', 'string', 'max:50']]);
        $book = LibraryBook::query()->create(collect($validated)->except('copies')->all());

        foreach ($validated['copies'] ?? [] as $copy) {
            $this->addCopy($book, $copy['accession_no'], $copy['shelf'] ?? null);
        }

        return ApiResponse::data(['id' => $book->ulid], 201);
    }

    public function updateBook(Request $request, LibraryBook $book): JsonResponse
    {
        Gate::authorize(P::CatalogueManage);
        $book->update($this->validatedBook($request));

        return ApiResponse::data(['id' => $book->ulid]);
    }

    /**
     * Add a copy: `accession_no`, `shelf?`.
     */
    public function storeCopy(Request $request, LibraryBook $book): JsonResponse
    {
        Gate::authorize(P::CatalogueManage);
        $validated = $request->validate(['accession_no' => ['required', 'string', 'max:30'], 'shelf' => ['nullable', 'string', 'max:50']]);
        $copy = $this->addCopy($book, $validated['accession_no'], $validated['shelf'] ?? null);

        return ApiResponse::data(['id' => $copy->ulid], 201);
    }

    /**
     * Mark a copy lost or withdrawn (or available again): `status`, `shelf?`.
     */
    public function updateCopy(Request $request, LibraryCopy $copy): JsonResponse
    {
        Gate::authorize(P::CatalogueManage);
        $validated = $request->validate(['status' => ['required', Rule::in(['available', 'lost', 'withdrawn'])], 'shelf' => ['nullable', 'string', 'max:50']]);
        abort_if($copy->status === 'issued', 409, 'Return the copy first.');
        $copy->update($validated);

        return ApiResponse::message('Copy updated.');
    }

    // ----- circulation -----------------------------------------------------

    /**
     * A member's current loans, unpaid fines and dues: `member` (email, register no or employee code).
     */
    public function memberLookup(Request $request): JsonResponse
    {
        Gate::authorize(P::CirculationManage);
        ['user' => $user, 'type' => $type] = $this->library->member((string) $request->validate(['member' => ['required', 'string', 'max:100']])['member']);

        return ApiResponse::data(['member' => ['id' => $user->ulid, 'name' => $user->name, 'email' => $user->email, 'type' => $type], ...$this->account($user)]);
    }

    /**
     * Issue: `accession_no`, `member`.
     */
    public function issue(Request $request): JsonResponse
    {
        Gate::authorize(P::CirculationManage);
        $validated = $request->validate(['accession_no' => ['required', 'string'], 'member' => ['required', 'string']]);
        $loan = $this->library->issue($validated['accession_no'], $validated['member']);

        return ApiResponse::data($this->loan($loan->load(['copy.book', 'member'])), 201);
    }

    /**
     * Return: `accession_no`.
     */
    public function return(Request $request): JsonResponse
    {
        Gate::authorize(P::CirculationManage);
        $loan = $this->library->return((string) $request->validate(['accession_no' => ['required', 'string']])['accession_no']);

        return ApiResponse::data($this->loan($loan->load(['copy.book', 'member'])));
    }

    public function renew(Request $request, LibraryLoan $loan): JsonResponse
    {
        abort_unless($loan->user_id === $this->user($request)->id || $this->user($request)->can(P::CirculationManage), 403);

        return ApiResponse::data($this->loan($this->library->renew($loan)->load(['copy.book', 'member'])));
    }

    /**
     * Settle a fine: `how` (paid|waived), `note?`.
     */
    public function settleFine(Request $request, LibraryLoan $loan): JsonResponse
    {
        $validated = $request->validate(['how' => ['required', Rule::in(['paid', 'waived'])], 'note' => ['nullable', 'string', 'max:200']]);
        Gate::authorize($validated['how'] === 'waived' ? P::FinesWaive : P::CirculationManage);
        $this->library->settleFine($loan, $validated['how'], $validated['note'] ?? null);

        return ApiResponse::message($validated['how'] === 'paid' ? 'Fine collected.' : 'Fine waived.');
    }

    /**
     * Loans. Filters: `filter[state]` (active|overdue|fines), `filter[search]` (member name or accession no).
     */
    public function loans(Request $request): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $f = (array) $request->input('filter', []);

        $loans = LibraryLoan::query()->with(['copy.book', 'member'])
            ->when(($f['state'] ?? null) === 'active', fn ($q) => $q->whereNull('returned_on'))
            ->when(($f['state'] ?? null) === 'overdue', fn ($q) => $q->whereNull('returned_on')->whereDate('due_on', '<', today()))
            ->when(($f['state'] ?? null) === 'fines', fn ($q) => $q->where('fine_status', 'due'))
            ->when($f['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->whereHas('member', fn ($m) => $m->where('name', 'like', "%{$term}%"))->orWhereHas('copy', fn ($c) => $c->where('accession_no', $term))))
            ->orderBy('due_on')->paginate(25);

        return ApiResponse::paginated($loans, fn (LibraryLoan $l): array => $this->loan($l));
    }

    /**
     * Counts for the library desk.
     */
    public function summary(): JsonResponse
    {
        Gate::authorize(P::ReportsView);

        return ApiResponse::data([
            'titles' => LibraryBook::query()->count(),
            'copies' => LibraryCopy::query()->whereIn('status', ['available', 'issued'])->count(),
            'on_loan' => LibraryLoan::query()->whereNull('returned_on')->count(),
            'overdue' => LibraryLoan::query()->whereNull('returned_on')->whereDate('due_on', '<', today())->count(),
            'fines_due' => round((float) LibraryLoan::query()->where('fine_status', 'due')->sum('fine'), 2),
        ]);
    }

    /**
     * Library dues of a user (for no-dues before certificates or relieving).
     */
    public function userDues(User $user): JsonResponse
    {
        Gate::authorize(P::ReportsView);

        return ApiResponse::data($this->library->dues($user));
    }

    /**
     * The signed-in member's loans, recent history and fines.
     */
    public function mine(Request $request): JsonResponse
    {
        return ApiResponse::data($this->account($this->user($request)));
    }

    // ----- helpers ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function account(User $user): array
    {
        $loans = LibraryLoan::query()->with('copy.book')->where('user_id', $user->id)->latest('issued_on')->latest('id')->limit(30)->get();

        return [
            'dues' => $this->library->dues($user),
            'current' => $loans->whereNull('returned_on')->map(fn (LibraryLoan $l): array => $this->loan($l, withMember: false))->values(),
            'history' => $loans->whereNotNull('returned_on')->map(fn (LibraryLoan $l): array => $this->loan($l, withMember: false))->values(),
        ];
    }

    private function addCopy(LibraryBook $book, string $accessionNo, ?string $shelf): LibraryCopy
    {
        validator(['accession_no' => $accessionNo], ['accession_no' => [Rule::unique('library_copies', 'accession_no')->where('institution_id', app(CurrentInstitution::class)->id())]])->validate();
        $copy = LibraryCopy::query()->create(['library_book_id' => $book->id, 'accession_no' => $accessionNo, 'shelf' => $shelf]);
        $copy->forceFill(['status' => 'available'])->save();

        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedBook(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:300'],
            'authors' => ['required', 'string', 'max:300'],
            'isbn' => ['nullable', 'string', 'max:20'],
            'publisher' => ['nullable', 'string', 'max:150'],
            'edition' => ['nullable', 'string', 'max:50'],
            'published_year' => ['nullable', 'integer', 'min:1500', 'max:'.(now()->year + 1)],
            'subject' => ['nullable', 'string', 'max:100'],
            'call_number' => ['nullable', 'string', 'max:50'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loan(LibraryLoan $l, bool $withMember = true): array
    {
        return [
            'id' => $l->ulid,
            'book' => ['id' => $l->copy->book->ulid, 'title' => $l->copy->book->title, 'authors' => $l->copy->book->authors],
            'accession_no' => $l->copy->accession_no,
            'member' => $withMember ? ['id' => $l->member->ulid, 'name' => $l->member->name, 'type' => $l->member_type] : null,
            'issued_on' => $l->issued_on->toDateString(),
            'due_on' => $l->due_on->toDateString(),
            'returned_on' => $l->returned_on?->toDateString(),
            'renewals' => $l->renewals,
            'overdue' => $l->isOverdue(),
            'fine' => (float) $l->fine,
            'fine_status' => $l->fine_status,
            'fine_note' => $l->fine_note,
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
