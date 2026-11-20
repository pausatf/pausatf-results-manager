<?php

use PHPUnit\Framework\TestCase;
use PAUSATF\Results\Models\AthleteResult;

require_once __DIR__ . '/../includes/contracts/interface-arrayable.php';
require_once __DIR__ . '/../includes/contracts/interface-jsonable.php';
require_once __DIR__ . '/../includes/models/class-athlete-result.php';

final class PropertyHooksTest extends TestCase
{
    public function testAthleteDefaultsAndNormalization(): void
    {
        $result = new AthleteResult(123);
        self::assertSame('finished', $result->status);
        self::assertNull($result->timeSeconds);
        self::assertNull($result->place);
        $result->athleteName = ' Runner ';
        $result->gender = ' f ';
        self::assertSame('Runner', $result->athleteName);
        self::assertSame('F', $result->gender);
    }

    public function testAthleteRejectsInvalidPlace(): void
    {
        $result = new AthleteResult(123);
        $this->expectException(InvalidArgumentException::class);
        $result->place = 0;
    }
}
