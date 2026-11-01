<?php
/**
 * calculate_standings() against an in-memory $wpdb double.
 */

namespace PAUSATF\Results\Tests;

use PHPUnit\Framework\TestCase;

/** Minimal wpdb double: canned SELECT results, recorded writes. */
final class StandingsWpdbDouble {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    /** @var array<string, mixed> */
    public array $series = [];
    /** @var list<array<string, mixed>> */
    public array $events = [];
    /** @var list<array<string, mixed>> */
    public array $results = [];
    /** @var list<array<string, mixed>> */
    public array $inserted = [];

    public function prepare(string $sql, ...$args): string {
        return $sql;
    }
    public function get_row(string $sql, $output = null): ?array {
        return str_contains($sql, 'grand_prix_series') ? $this->series : null;
    }
    public function get_results(string $sql, $output = null): array {
        return str_contains($sql, 'grand_prix_events') ? $this->events : $this->results;
    }
    public function delete(string $table, array $where): int {
        return 0;
    }
    public function insert(string $table, array $data): int {
        $this->inserted[] = $data;
        return 1;
    }
    public function get_charset_collate(): string {
        return '';
    }
}

class GrandPrixStandingsTest extends TestCase {
    private StandingsWpdbDouble $db;

    protected function setUp(): void {
        global $wpdb;
        $this->db = new StandingsWpdbDouble();
        $wpdb = $this->db;
        require_once __DIR__ . '/../includes/class-grand-prix.php';
        $this->db->series = [
            'id' => 7, 'place_points' => json_encode(\PAUSATF\Results\GrandPrixScoring::PA_ROAD_POINTS_A),
            'bonus_points' => '{}', 'divisions' => json_encode(['OPEN']), 'min_races' => 0, 'max_races' => null, 'drop_worst' => 0,
        ];
        $this->db->events = [
            ['event_id' => 1, 'multiplier' => 1.0, 'event_name' => 'A', 'event_date' => '2005-01-01'],
            ['event_id' => 2, 'multiplier' => 1.0, 'event_name' => 'B', 'event_date' => '2005-02-01'],
        ];
    }

    /** @param array<string, mixed> $extra */
    private function row(string $name, string $sex, int $event, int $place, array $extra = []): array {
        return $extra + ['athlete_name' => $name, 'athlete_id' => null, 'division' => 'OPEN', 'sex' => $sex,
            'event_id' => $event, 'event_name' => "E{$event}", 'place' => $place, 'time_display' => '30:00'];
    }

    /** @return array<string, array<string, mixed>> */
    private function standings(): array {
        $grand_prix = new \PAUSATF\Results\GrandPrix();
        $grand_prix->calculate_standings(7);
        $out = [];
        foreach ($this->db->inserted as $row) {
            $out[$row['athlete_name'] . '|' . $row['gender']] = $row;
        }
        return $out;
    }

    public function test_open_women_are_ranked_as_women_and_not_merged_with_men(): void {
        $this->db->results = [
            $this->row('Pat Lee', 'M', 1, 1),
            $this->row('Pat Lee', 'F', 1, 1),
        ];
        $rows = $this->standings();
        self::assertSame('F', $rows['Pat Lee|F']['gender']);
        self::assertSame(100.0, (float) $rows['Pat Lee|F']['total_points']);
        self::assertSame(100.0, (float) $rows['Pat Lee|M']['total_points']);
    }

    public function test_single_race_athletes_are_ranked_and_ties_share_a_rank(): void {
        $this->db->results = [
            $this->row('A', 'M', 1, 2), $this->row('A', 'M', 2, 2),   // 90 + 90
            $this->row('B', 'M', 1, 1), $this->row('B', 'M', 2, 3),   // 100 + 81 = 181
            $this->row('C', 'M', 1, 3), $this->row('C', 'M', 2, 1),   // 81 + 100 = 181
            $this->row('D', 'M', 1, 51),                                 // beyond 50th: 0
        ];
        $rows = $this->standings();
        self::assertSame(1, $rows['B|M']['rank_position']);
        self::assertSame(1, $rows['C|M']['rank_position']);
        self::assertSame(3, $rows['A|M']['rank_position']);
        self::assertSame(0.0, (float) $rows['D|M']['total_points']);
        self::assertArrayHasKey('D|M', $rows, 'No PA rule sets a minimum number of races.');
    }

    public function test_unlabelled_open_result_is_kept_as_unknown_sex(): void {
        $this->db->results = [$this->row('Lee Kim', '', 1, 4)];
        $rows = $this->standings();
        self::assertSame('X', $rows['Lee Kim|X']['gender']);
        self::assertSame(73.0, (float) $rows['Lee Kim|X']['total_points']);
    }
}
