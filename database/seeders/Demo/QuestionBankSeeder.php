<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Academics\Models\Course;
use App\Modules\Academics\Models\FacultyAllocation;
use App\Modules\Exams\Models\ExamSchedule;
use App\Modules\QuestionBank\Models\PaperBlueprint;
use App\Modules\QuestionBank\Models\Question;
use App\Modules\QuestionBank\Services\QuestionBankService;
use Database\Seeders\Support\CampusSeeder;

/**
 * Question banks for three current courses (tagged by unit, CO, Bloom level and difficulty), a 60-mark
 * end-term blueprint, and papers in every state: draft, submitted, approved and sealed (linked to its exam).
 */
class QuestionBankSeeder extends CampusSeeder
{
    /**
     * Course code => [short-answer topics (12), long-answer topics (8), MCQs (4: [question, options])].
     *
     * @var array<string, array{0: list<string>, 1: list<string>, 2: list<array{0: string, 1: list<string>}>}>
     */
    private const BANK = [
        '22CS31' => [
            ['an abstract data type', 'a stack and its operations', 'infix to postfix conversion', 'a circular queue', 'a priority queue', 'a singly linked list', 'a doubly linked list', 'a binary search tree', 'tree traversals', 'a complete binary tree', 'hashing and collisions', 'a graph and its representations'],
            ['how to evaluate a postfix expression using a stack, with an example', 'insertion and deletion in a circular queue implemented using arrays', 'polynomial addition using linked lists', 'insertion and deletion in a binary search tree', 'the threaded binary tree and in-order traversal without a stack', 'BFS and DFS traversals of a graph with examples', 'heap sort and analyse its complexity', 'open addressing techniques used in hashing'],
            [['Which data structure is used for function calls?', ['Queue', 'Stack', 'Heap', 'Graph']], ['The worst-case search time in a BST is', ['O(1)', 'O(log n)', 'O(n)', 'O(n log n)']], ['Which traversal of a BST gives sorted order?', ['Pre-order', 'In-order', 'Post-order', 'Level-order']], ['A queue follows', ['LIFO', 'FIFO', 'Random order', 'Priority order only']]],
        ],
        '22AD31' => [
            ['a Python list comprehension', 'a dictionary and its methods', 'a stack using Python lists', 'a queue using collections.deque', 'recursion with an example', 'a linked list node class', 'binary search', 'a tree and its terminology', 'a heap using heapq', 'a graph adjacency list', 'time complexity', 'a hash table'],
            ['merge sort in Python and analyse its complexity', 'a linked list class with insert and delete', 'a binary search tree in Python with search and insert', "Dijkstra's shortest path algorithm with an example", 'quick sort and its average and worst cases', 'a priority queue with heapq for task scheduling', 'tries for prefix search', 'dynamic programming using the knapsack problem'],
            [['Which Python type is immutable?', ['list', 'dict', 'tuple', 'set']], ['heapq in Python implements a', ['max-heap', 'min-heap', 'balanced BST', 'trie']], ['Average lookup time in a Python dict is', ['O(1)', 'O(log n)', 'O(n)', 'O(n²)']], ['Which algorithm uses divide and conquer?', ['Bubble sort', 'Merge sort', 'Insertion sort', 'Selection sort']]],
        ],
        '22MCA31' => [
            ['cloud computing', 'IaaS', 'PaaS', 'SaaS', 'virtualization', 'a hypervisor', 'elasticity', 'multi-tenancy', 'a public cloud', 'a hybrid cloud', 'object storage', 'a service-level agreement'],
            ['the NIST cloud reference architecture', 'types of hypervisors with examples', 'cloud deployment models and when to use each', 'MapReduce with a word-count example', 'security challenges in the cloud and their mitigations', 'containers versus virtual machines', 'designing a scalable web application on AWS', 'cloud pricing models and cost optimisation'],
            [['Amazon EC2 is an example of', ['SaaS', 'PaaS', 'IaaS', 'FaaS']], ['Which is a type-1 hypervisor?', ['VirtualBox', 'VMware ESXi', 'VMware Workstation', 'QEMU user mode']], ['Google Docs is an example of', ['IaaS', 'PaaS', 'SaaS', 'DaaS']], ['Scaling out means', ['Adding more machines', 'Adding more RAM', 'Faster CPU', 'Bigger disk']]],
        ],
    ];

    protected function seed(): void
    {
        if (Question::query()->exists()) {
            return;
        }

        $bank = app(QuestionBankService::class);

        foreach (self::BANK as $code => [$short, $long, $mcq]) {
            $course = Course::query()->with('department')->where('code', $code)->firstOrFail();
            $hod = $this->user('hod.'.strtolower($course->department->code).'@'.self::DOMAIN);
            $setter = $this->teacherOf($course) ?? $hod;

            $this->as($setter, function () use ($bank, $course, $setter, $short, $long, $mcq): void {
                foreach ($short as $i => $topic) {
                    $bank->saveQuestion($course, ['unit' => 1 + $i % 5, 'type' => 'short', 'marks' => 2, 'co_code' => 'CO1', 'bloom_level' => $i % 2 ? 'understand' : 'remember', 'difficulty' => 'easy', 'text' => 'Define '.$topic.'.', 'answer' => 'Definition of '.$topic.' with one example.'], $this->fresh($setter));
                }

                foreach ($long as $i => $topic) {
                    $bank->saveQuestion($course, ['unit' => 1 + $i % 5, 'type' => 'long', 'marks' => 10, 'co_code' => $i % 2 ? 'CO3' : 'CO2', 'bloom_level' => $i % 2 ? 'analyse' : 'apply', 'difficulty' => $i % 3 === 0 ? 'hard' : 'medium', 'text' => 'Explain '.$topic.'.', 'answer' => 'Scheme: concept 3, illustration 4, analysis 3.'], $this->fresh($setter));
                }

                foreach ($mcq as $i => [$question, $options]) {
                    $bank->saveQuestion($course, ['unit' => 1 + $i, 'type' => 'mcq', 'marks' => 1, 'co_code' => 'CO1', 'bloom_level' => 'remember', 'difficulty' => 'easy', 'text' => $question, 'options' => $options, 'answer' => $options[1]], $this->fresh($setter));
                }
            });

            $blueprint = $this->as($setter, fn () => PaperBlueprint::query()->create([
                'course_id' => $course->id, 'name' => 'Semester end examination (60 marks)', 'duration_minutes' => 180, 'avoid_recent_papers' => 2,
                'sections' => [
                    ['name' => 'Part A', 'type' => 'short', 'marks' => 2.0, 'count' => 10, 'attempt' => null, 'units' => null, 'bloom_levels' => null, 'difficulty' => null],
                    ['name' => 'Part B', 'type' => 'long', 'marks' => 10.0, 'count' => 6, 'attempt' => 4, 'units' => null, 'bloom_levels' => null, 'difficulty' => null],
                ],
            ]));

            $this->papers($bank, $blueprint, $course, $setter, $hod, $code === '22CS31');
        }
    }

    private function papers(QuestionBankService $bank, PaperBlueprint $blueprint, Course $course, User $setter, User $hod, bool $allStates): void
    {
        $schedule = ExamSchedule::query()->where('course_id', $course->id)->whereHas('cycle', fn ($q) => $q->where('status', '!=', 'published'))->first();
        $papers = $this->as($setter, fn () => $bank->generate($blueprint, $allStates ? 3 : 1, $schedule));
        $reviewer = $hod->id === $setter->id ? $this->user('coe@'.self::DOMAIN) : $hod;
        $coe = $this->user('coe@'.self::DOMAIN);

        if (! $allStates) {
            return; // one draft paper
        }

        // A: sealed; B: approved; C: submitted for review.
        foreach ($papers as $index => $paper) {
            $this->as($setter, fn () => $bank->submit($paper->refresh(), $this->fresh($setter)));

            if ($index === 2) {
                continue;
            }

            $this->as($reviewer, fn () => $bank->review($paper->refresh(), true, 'Good coverage of units; balanced difficulty.', $this->fresh($reviewer)));

            if ($index === 0) {
                $this->as($coe, fn () => $bank->seal($paper->refresh(), $this->fresh($coe), '127.0.0.1'));
            }
        }
    }

    private function teacherOf(Course $course): ?User
    {
        return FacultyAllocation::query()
            ->whereHas('offeringSection.offering', fn ($q) => $q->where('course_id', $course->id))
            ->with('employee.user')->first()?->employee?->user;
    }
}
