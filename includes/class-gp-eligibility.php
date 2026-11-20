<?php
/**
 * Grand Prix and team-scoring eligibility inputs.
 *
 * PA scores only current USATF Pacific members, and teams only from PA clubs
 * (PA Cross Country rules 2026-03, section 3 B; PA LDR guidelines 2026-02, 4.1 and 7.1).
 * RM holds no membership or club rosters, so these come from filters:
 *
 * - `pausatf_results_gp_member_status` (null, result row): true, false, or null if unknown.
 * - `pausatf_results_gp_is_pa_club` (null, club name, result row): true, false, or null.
 *
 * Unknown counts as eligible but unverified, so standings can be computed before roster
 * data is wired in and still say they are unverified. A false from either filter excludes
 * the runner.
 *
 * @package PAUSATF\Results
 */

declare(strict_types=1);

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

final class GPEligibility {
    /**
     * @param array<string, mixed> $result A results row (athlete_name, club, athlete_id, ...).
     * @return array{eligible: bool, verified: bool}
     */
    public static function check(array $result): array {
        $member = apply_filters('pausatf_results_gp_member_status', null, $result);
        $club = apply_filters('pausatf_results_gp_is_pa_club', null, (string) ($result['club'] ?? ''), $result);
        if ($member === false || $club === false) {
            return ['eligible' => false, 'verified' => true];
        }
        return ['eligible' => true, 'verified' => $member === true && $club === true];
    }

    /**
     * Individual eligibility: membership only, since unattached members score as individuals
     * (LDR 4.1).
     *
     * @param array<string, mixed> $result
     * @return array{eligible: bool, verified: bool}
     */
    public static function member(array $result): array {
        $member = apply_filters('pausatf_results_gp_member_status', null, $result);
        return ['eligible' => $member !== false, 'verified' => $member !== null];
    }
}
