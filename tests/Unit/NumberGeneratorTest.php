<?php

namespace Tests\Unit;

use App\Shared\Numbering\NumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class NumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected bool $syncPermissions = false;

    public function test_sequences_are_per_institution_and_scope(): void
    {
        $generator = app(NumberGenerator::class);
        $this->travelTo(now()->setDate(2026, 7, 1));

        $this->assertSame('RV26REC0001', $generator->next('receipt', '{INST}{YY}REC{SEQ:4}', 1, '2026', ['INST' => 'RV']));
        $this->assertSame('RV26REC0002', $generator->next('receipt', '{INST}{YY}REC{SEQ:4}', 1, '2026', ['INST' => 'RV']));
        $this->assertSame('BM26REC0001', $generator->next('receipt', '{INST}{YY}REC{SEQ:4}', 2, '2026', ['INST' => 'BM']));
        $this->assertSame('RV26REC0001', $generator->next('receipt', '{INST}{YY}REC{SEQ:4}', 1, '2027', ['INST' => 'RV']));
        $this->assertSame('APP-2026-1', $generator->next('application', 'APP-{YYYY}-{SEQ}', null));
    }

    public function test_format_requires_sequence_token(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(NumberGenerator::class)->next('x', 'NO-SEQ');
    }
}
