# Grand Prix standings

RM computes Grand Prix standings for a series when its `ruleset` column names a rule set. A series without one keeps the earlier scoring, using the series' own points table, drop rule and race cap.

| `ruleset` | Rules followed |
|---|---|
| `pa_road_2026` | USATF Pacific LDR Guidelines and Rules, updated 2/27/2026: sections 4, 10-13, 18-20 |
| `pa_xc_2026` | PA Cross Country Grand Prix rules, updated 3/1/26: sections 1-4 |
| `pa_mut_2026` | USATF Pacific MUT Grand Prix Rules, updated January 13, 2026: rules 2-8 |

The road Short and Long series are separate series rows with `series_part` set to `short` or `long`. `add_event_to_series()` refuses an event whose `distance_m` does not fit the part: Short is 10K or shorter, Long is 12K to the marathon.

## Event options

Pass these to `add_event_to_series($series_id, $event_id, $options)`:

- `multiplier`: the event's scoring value (LDR 13.1, MUT rule 5).
- `team_multiplier`: the team scoring value, when it differs from the individual one (LDR 13.2(d)). It defaults to `multiplier`.
- `championship`: marks the XC championship race, which always counts (XC section 4).
- `distance_m`: the race distance in meters. Road team sizes change above 25K (LDR 12.2(a)).

## What is computed

Each rule set produces three kinds of row in the standings table, told apart by `entry_type` and `standing_type`:

- **Division standings.** Points go by place within the division among eligible athletes. Runners from older divisions are left out.
- **Age+ standings** (road and XC). Each standing includes everyone at or above that age.
- **Team standings.** Road teams rank by cumulative time. XC teams use `XCTeamScoring`. MUT teams rank by the time of their top three, and an Overall row sums each club's team divisions.

Athletes are matched across races by `AthleteIdentity` (see `athlete-identity.md`). The shortcode takes `type="division"`, `type="age_plus"` or `type="team"`.

## Data RM does not have

These hooks supply it. Until they are wired in, rows are stored with `verified = 0`.

| Filter | Arguments | Use |
|---|---|---|
| `pausatf_results_gp_member_status` | null, result row | true, false or null (unknown). False removes the athlete. |
| `pausatf_results_gp_is_pa_club` | null, club, result row | The same, for team scoring. |
| `pausatf_results_gp_birth_date` | null, athlete key, result row | A `Y-m-d` date, used to decide the decade carry-over. |
| `pausatf_results_gp_team_division` | division, result row | Road drop-downs (LDR 18.2, 19). |
| `pausatf_results_xc_team_division` | division, result row | XC drop-downs (XC section 3 J). |

Without a birth date, a runner is carried in the younger division only if the series already shows them racing at the younger age.

## Not modeled

- MUT Mixed teams.
- Road prize money and the no-double-dipping rule for age+ prizes.
- Minimum race counts for awards (MUT rule 9).
- Duration races ranked by distance (MUT rule 7).
- The MUT petition to stay in a younger division (rule 8).
