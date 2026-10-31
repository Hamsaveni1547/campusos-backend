<?php

namespace Tests\Unit;

use App\Modules\QuestionBank\Support\PaperCrypto;
use App\Modules\QuestionBank\Support\PaperGenerator;
use App\Shared\Exceptions\BusinessRuleException;
use Tests\TestCase;

class QuestionBankSupportTest extends TestCase
{
    /**
     * @return list<array{id: int, type: string, marks: float, unit: int|null, bloom_level: string|null, difficulty: string}>
     */
    private function bank(): array
    {
        $bank = [];
        foreach (range(1, 6) as $id) {
            $bank[] = ['id' => $id, 'type' => 'short', 'marks' => 2.0, 'unit' => $id <= 3 ? 1 : 2, 'bloom_level' => 'remember', 'difficulty' => 'easy'];
        }
        foreach (range(7, 9) as $id) {
            $bank[] = ['id' => $id, 'type' => 'long', 'marks' => 10.0, 'unit' => 1, 'bloom_level' => 'apply', 'difficulty' => 'medium'];
        }

        return $bank;
    }

    public function test_same_seed_gives_the_same_paper_and_sections_respect_filters(): void
    {
        $sections = [['type' => 'short', 'marks' => 2.0, 'count' => 2, 'units' => [2]], ['type' => 'long', 'marks' => 10.0, 'count' => 2]];

        $first = PaperGenerator::generate($sections, $this->bank(), [], 42);
        $this->assertNull($first['short']);
        $this->assertSame($first, PaperGenerator::generate($sections, $this->bank(), [], 42));
        $this->assertEmpty(array_diff($first['sections'][0], [4, 5, 6]));
        $this->assertEmpty(array_diff($first['sections'][1], [7, 8, 9]));
    }

    public function test_avoided_questions_are_used_only_when_needed_and_short_sections_are_reported(): void
    {
        $result = PaperGenerator::generate([['type' => 'long', 'marks' => 10.0, 'count' => 2]], $this->bank(), [7, 8], 1);
        $this->assertContains(9, $result['sections'][0]);

        $this->assertSame(1, PaperGenerator::generate([['type' => 'short', 'marks' => 2.0, 'count' => 1], ['type' => 'long', 'marks' => 10.0, 'count' => 4]], $this->bank(), [], 1)['short']);
    }

    public function test_seal_round_trip_and_tamper_detection(): void
    {
        $crypto = new PaperCrypto;
        $this->assertSame('What is a key?', $crypto->decrypt($crypto->encrypt('What is a key?')));

        $sealed = $crypto->seal('{"q":[1,2]}');
        $this->assertStringNotContainsString('"q"', $sealed['payload']);
        $this->assertSame('{"q":[1,2]}', $crypto->open($sealed['payload'], $sealed['wrapped_key'], $sealed['hash']));

        $this->expectException(BusinessRuleException::class);
        $crypto->open($sealed['payload'], $sealed['wrapped_key'], hash('sha256', 'something else'));
    }
}
