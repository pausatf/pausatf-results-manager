<?php
/**
 * Athlete identity: the site archive's key when available, RM's own as a fallback.
 */

namespace PAUSATF\Results\Tests {
    use PAUSATF\Results\AthleteIdentity;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/class-athlete-identity.php';
    require_once __DIR__ . '/fixtures/site-archive-doubles.php';

    class AthleteIdentityTest extends TestCase {
        /** @var array<string, array{athlete_key: string, people: list<mixed>}> */
        private array $archive = [
            'russ-kiernan' => ['athlete_key' => 'russ-kiernan', 'people' => []],
            'pat-smith' => ['athlete_key' => 'pat-smith', 'people' => [['pat-smith-1'], ['pat-smith-2']]],
        ];

        /** @var list<string> */
        private array $asked = [];

        protected function setUp(): void {
            AthleteIdentity::set_lookup(function (string $key): ?array {
                $this->asked[] = $key;
                return $this->archive[$key] ?? null;
            });
        }

        protected function tearDown(): void {
            AthleteIdentity::reset();
        }

        public function test_differently_printed_names_resolve_to_one_archive_athlete(): void {
            $a = AthleteIdentity::resolve('Kiernan, Russ');
            $b = AthleteIdentity::resolve('RUSS J. KIERNAN');

            self::assertSame('archive:russ-kiernan', $a['key']);
            self::assertSame($a['key'], $b['key']);
            self::assertTrue($a['verified']);
        }

        public function test_homonyms_the_archive_split_are_not_merged_by_name(): void {
            $identity = AthleteIdentity::resolve('Pat Smith', 42);

            self::assertSame('rm:42', $identity['key']);
            self::assertTrue($identity['ambiguous']);
            self::assertFalse($identity['verified']);
        }

        public function test_falls_back_to_rm_id_then_name(): void {
            self::assertSame(['key' => 'rm:7', 'source' => 'rm', 'verified' => false, 'ambiguous' => false], AthleteIdentity::resolve('New Runner', 7));
            self::assertSame('name:new runner', AthleteIdentity::resolve('  New   Runner ')['key']);
        }

        public function test_without_the_archive_rm_matching_is_used(): void {
            AthleteIdentity::set_lookup(null);

            self::assertSame('rm:9', AthleteIdentity::resolve('Kiernan, Russ', 9)['key']);
            self::assertSame('name:russ kiernan', AthleteIdentity::resolve('Russ Kiernan')['key']);
        }

        public function test_results_are_cached_per_request(): void {
            AthleteIdentity::resolve('Russ Kiernan');
            $asked = count($this->asked);
            AthleteIdentity::resolve('Russ Kiernan');

            self::assertSame($asked, count($this->asked));
        }

        public function test_archive_keys_follow_the_site_rules(): void {
            self::assertSame(['russ-kiernan', 'kiernan-russ'], AthleteIdentity::archive_keys('Kiernan, Russ'));
        }
    }
}
