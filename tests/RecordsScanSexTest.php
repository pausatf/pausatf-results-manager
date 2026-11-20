<?php
/**
 * The records scan reads a result's stored sex and uses the division label only for legacy rows.
 */

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\RecordsDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-listed-sex.php';
require_once __DIR__ . '/../includes/class-records-database.php';

final class RecordsScanSexTest extends TestCase {
    /** @return array<string, array{array<string, mixed>, ?string}> */
    public static function results(): array {
        return [
            'stored M beats a women-looking label' => [['sex' => 'M', 'division' => 'Women 40'], 'M'],
            'stored F beats a men-looking label' => [['sex' => 'F', 'division' => 'M40'], 'F'],
            'stored spelled-out value' => [['sex' => 'female', 'division' => ''], 'F'],
            'listed X is not scored from the label' => [['sex' => null, 'sex_listed' => 'X', 'division' => 'M40'], null],
            'listed NB is not scored from the label' => [['sex' => '', 'sex_listed' => 'NB', 'division' => 'Women 50'], null],
            'listed W with no category yet' => [['sex' => null, 'sex_listed' => 'W', 'division' => ''], 'F'],
            'legacy row falls back to a women label' => [['sex' => null, 'division' => 'Women 50'], 'F'],
            'legacy row falls back to a W label' => [['division' => 'W35'], 'F'],
            'legacy row falls back to a men label' => [['sex' => '', 'division' => 'M40'], 'M'],
            'legacy row with an Open label has no sex' => [['sex' => null, 'division' => 'Open'], null],
            'no sex and no label' => [[], null],
        ];
    }

    /** @param array<string, mixed> $result */
    #[DataProvider('results')]
    public function test_scan_sex(array $result, ?string $expected): void {
        self::assertSame($expected, RecordsDatabase::infer_gender($result));
    }
}
