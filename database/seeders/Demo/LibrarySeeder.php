<?php

namespace Database\Seeders\Demo;

use App\Modules\Library\Models\LibraryBook;
use App\Modules\Library\Models\LibraryCopy;
use App\Modules\Library\Models\LibraryLoan;
use App\Modules\Library\Services\LibraryService;
use App\Modules\Students\Models\Student;
use Database\Seeders\Support\CampusSeeder;

/**
 * A small catalogue of standard textbooks with copies, current and overdue loans, and fines
 * (due, paid and waived). student@ has one book on loan and one paid fine.
 */
class LibrarySeeder extends CampusSeeder
{
    /** [title, authors, publisher, edition, year, subject, copies] */
    private const BOOKS = [
        ['Database System Concepts', 'Abraham Silberschatz, Henry F. Korth, S. Sudarshan', 'McGraw Hill', '7th', 2019, 'Databases', 4],
        ['Fundamentals of Database Systems', 'Ramez Elmasri, Shamkant B. Navathe', 'Pearson', '7th', 2016, 'Databases', 3],
        ['Operating System Concepts', 'Abraham Silberschatz, Peter B. Galvin, Greg Gagne', 'Wiley', '10th', 2018, 'Operating systems', 4],
        ['Computer Networking: A Top-Down Approach', 'James F. Kurose, Keith W. Ross', 'Pearson', '8th', 2021, 'Networks', 3],
        ['Introduction to Algorithms', 'Thomas H. Cormen, Charles E. Leiserson, Ronald L. Rivest, Clifford Stein', 'MIT Press', '4th', 2022, 'Algorithms', 5],
        ['Data Structures Using C', 'Reema Thareja', 'Oxford University Press', '2nd', 2014, 'Data structures', 5],
        ['The C Programming Language', 'Brian W. Kernighan, Dennis M. Ritchie', 'Pearson', '2nd', 1988, 'Programming', 4],
        ['Python Crash Course', 'Eric Matthes', 'No Starch Press', '3rd', 2023, 'Programming', 3],
        ['Head First Java', 'Kathy Sierra, Bert Bates', 'O’Reilly', '3rd', 2022, 'Programming', 3],
        ['Software Engineering', 'Ian Sommerville', 'Pearson', '10th', 2015, 'Software engineering', 3],
        ['Artificial Intelligence: A Modern Approach', 'Stuart Russell, Peter Norvig', 'Pearson', '4th', 2020, 'Artificial intelligence', 4],
        ['Hands-On Machine Learning with Scikit-Learn, Keras and TensorFlow', 'Aurélien Géron', 'O’Reilly', '3rd', 2022, 'Machine learning', 3],
        ['Deep Learning', 'Ian Goodfellow, Yoshua Bengio, Aaron Courville', 'MIT Press', '1st', 2016, 'Machine learning', 2],
        ['Pattern Recognition and Machine Learning', 'Christopher M. Bishop', 'Springer', '1st', 2006, 'Machine learning', 2],
        ['Cloud Computing: Concepts, Technology and Architecture', 'Thomas Erl, Zaigham Mahmood, Ricardo Puttini', 'Pearson', '1st', 2013, 'Cloud computing', 2],
        ['Cryptography and Network Security', 'William Stallings', 'Pearson', '8th', 2022, 'Security', 3],
        ['Higher Engineering Mathematics', 'B.S. Grewal', 'Khanna Publishers', '44th', 2020, 'Mathematics', 6],
        ['Discrete Mathematics and Its Applications', 'Kenneth H. Rosen', 'McGraw Hill', '8th', 2019, 'Mathematics', 3],
        ['Digital Design', 'M. Morris Mano, Michael D. Ciletti', 'Pearson', '6th', 2018, 'Electronics', 3],
        ['Computer Organization and Design', 'David A. Patterson, John L. Hennessy', 'Morgan Kaufmann', '6th', 2020, 'Computer architecture', 3],
        ['Marketing Management', 'Philip Kotler, Kevin Lane Keller', 'Pearson', '16th', 2021, 'Management', 4],
        ['Financial Management', 'Prasanna Chandra', 'McGraw Hill', '10th', 2019, 'Management', 3],
        ['Organizational Behavior', 'Stephen P. Robbins, Timothy A. Judge', 'Pearson', '18th', 2018, 'Management', 3],
        ['Principles of Economics', 'N. Gregory Mankiw', 'Cengage', '9th', 2020, 'Economics', 2],
        ['Research Methodology: Methods and Techniques', 'C.R. Kothari', 'New Age International', '4th', 2019, 'Research', 3],
    ];

    protected function seed(): void
    {
        if (LibraryBook::query()->exists()) {
            return;
        }

        $library = app(LibraryService::class);
        $librarian = $this->user('library@'.self::DOMAIN);
        $accession = 1000;

        $this->as($librarian, function () use ($library, &$accession): void {
            foreach (self::BOOKS as $i => [$title, $authors, $publisher, $edition, $year, $subject, $copies]) {
                $book = LibraryBook::query()->create(['title' => $title, 'authors' => $authors, 'publisher' => $publisher, 'edition' => $edition, 'published_year' => $year, 'subject' => $subject, 'call_number' => sprintf('%03d.%02d', 4 + $i * 7 % 900, $i)]);

                foreach (range(1, $copies) as $n) {
                    $copy = LibraryCopy::query()->create(['library_book_id' => $book->id, 'accession_no' => 'BIT'.(++$accession), 'shelf' => 'R'.(1 + $i % 6).'-S'.(1 + $n % 5)]);
                    $copy->forceFill(['status' => $n === $copies && $copies >= 5 ? 'lost' : 'available'])->save();
                }
            }

            $members = [
                ...Student::query()->where('status', 'active')->orderBy('id')->limit(14)->pluck('register_no')->all(),
                'kavya.shetty@'.self::DOMAIN, 'raghavendra.kulkarni@'.self::DOMAIN,
            ];
            $available = LibraryCopy::query()->where('status', 'available')->orderBy('id')->pluck('accession_no')->values();

            foreach ($members as $i => $member) {
                $library->issue($available[$i * 3], $member);
            }

            // Back-date: some loans issued weeks ago, a few already overdue.
            LibraryLoan::query()->orderBy('id')->get()->each(function (LibraryLoan $loan, int $i): void {
                $issued = now()->subDays(match (true) {
                    $i < 4 => 25, $i < 9 => 9, default => 3
                });
                $loan->forceFill(['issued_on' => $issued->toDateString(), 'due_on' => $issued->copy()->addDays($loan->member_type === 'student' ? 14 : 30)->toDateString()])->save();
            });

            // Returned late: fines due, paid and waived.
            foreach ([[0, 'due', null], [1, 'paid', null], [2, 'waived', 'First late return; warned']] as [$i, $how, $note]) {
                $loan = LibraryLoan::query()->whereNull('returned_on')->orderBy('id')->skip($i)->first();

                if ($loan !== null) {
                    $returned = $library->return($loan->copy->accession_no);

                    if ($how !== 'due' && $returned->fine_status === 'due') {
                        $library->settleFine($returned, $how, $note);
                    }
                }
            }

            $library->issue($available[60], 'student@'.self::DOMAIN);
            $past = $library->issue($available[61], 'student@'.self::DOMAIN);
            $past->forceFill(['issued_on' => now()->subDays(20)->toDateString(), 'due_on' => now()->subDays(6)->toDateString()])->save();
            $returned = $library->return($past->copy->accession_no);
            $library->settleFine($returned, 'paid', null);
        });
    }
}
