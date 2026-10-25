<?php

namespace Tests\Unit;

use App\Support\Levels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LevelsTest extends TestCase
{
    /**
     * @dataProvider levelProvider
     */
    #[DataProvider('levelProvider')]
    public function test_level_is_derived_from_total_xp(int $xp, int $expectedLevel): void
    {
        $this->assertSame($expectedLevel, Levels::fromXp($xp));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function levelProvider(): array
    {
        return [
            'level 1 at 0 xp' => [0, 1],
            'level 1 below threshold' => [99, 1],
            'level 2 at exactly 100' => [100, 2],
            'level 2 below next' => [399, 2],
            'level 3 at exactly 400' => [400, 3],
            'level 10 at 8100' => [8100, 10],
            'level 10 below next threshold' => [9999, 10],
            'level 11 at exactly 10000' => [10000, 11],
        ];
    }

    public function test_thresholds_match_the_prd_formula(): void
    {
        // XP needed for level L = 100 * (L - 1)^2
        $this->assertSame(0, Levels::threshold(1));
        $this->assertSame(100, Levels::threshold(2));
        $this->assertSame(400, Levels::threshold(3));
        $this->assertSame(8100, Levels::threshold(10));

        $this->assertSame(100, Levels::nextThreshold(1));
        $this->assertSame(400, Levels::nextThreshold(2));
    }

    public function test_xp_to_next_level(): void
    {
        $this->assertSame(100, Levels::xpToNext(0, 1));
        $this->assertSame(1, Levels::xpToNext(99, 1));
        $this->assertSame(0, Levels::xpToNext(100, 1));
        $this->assertSame(450, Levels::xpToNext(450, 3));
    }

    public function test_progress_is_clamped_between_zero_and_one_hundred(): void
    {
        $this->assertSame(0, Levels::progress(100, 2));
        $this->assertSame(50, Levels::progress(250, 2));
        $this->assertSame(100, Levels::progress(399, 2));
        $this->assertSame(100, Levels::progress(100, 1));
    }
}
