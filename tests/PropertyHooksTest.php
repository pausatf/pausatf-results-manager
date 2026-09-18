<?php

use PHPUnit\Framework\TestCase;
use PAUSATF\Results\Models\AthleteResult;
use PAUSATF\Results\Sanctions\Sanction;

require_once __DIR__ . '/../includes/contracts/interface-arrayable.php';
require_once __DIR__ . '/../includes/contracts/interface-jsonable.php';
require_once __DIR__ . '/../includes/models/class-athlete-result.php';
require_once __DIR__ . '/../includes/sanctions/class-sanction.php';

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

    public function testSanctionDefaultsAndNormalization(): void
    {
        $sanction = new Sanction();
        self::assertSame('not_submitted', $sanction->nationalStatus);
        self::assertSame('draft', $sanction->localStatus);
        self::assertSame('road', $sanction->eventType);
        self::assertNull($sanction->eventEndDate);
        self::assertSame(0, $sanction->estimatedFinishers);
        $sanction->eventName = ' Road Race ';
        self::assertSame('Road Race', $sanction->eventName);
    }

    public function testSanctionRejectsInvalidStatus(): void
    {
        $sanction = new Sanction();
        $this->expectException(InvalidArgumentException::class);
        $sanction->nationalStatus = 'invalid';
    }
}
