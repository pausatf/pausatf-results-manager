# Grand Prix standings

Results Manager (RM) computes Pacific Association Grand Prix standings for road, cross country (XC) and mountain, ultra and trail (MUT) series under the 2026 PA rules. It does this from the results already in the database, so a standing can be rebuilt at any time and is never edited by hand.

## Status

- **Unofficial output.** The repository tests the scoring against constructed fixtures. It contains no comparison with standings that PA's scorers have published. Treat computed standings as a check on, not a replacement for, the official ones until someone reconciles them.
- **Unverified rows.** A standing is stored with `verified = 0` unless RM can show that every scorer is a current PA member, a PA club member where a club is needed, has a known age, and has an identity confirmed by the PAUSATF Site archive. RM holds no membership or club roster, so until a site supplies that data through the hooks below, every row is unverified. Unverified rows are still ranked.
- **Rules are encoded, not interpreted.** Section [Open questions](#open-questions) lists places where the PA texts are ambiguous or disagree with each other.

## Rule sets

A series uses a rule set when its `ruleset` column names one. A series with an empty `ruleset` keeps the earlier scoring (see [Legacy series](#legacy-series)).

| `ruleset` | Source (copy date) | Sections applied |
| --- | --- | --- |
| `pa_road_2026` | PA LDR Guidelines and Rules, updated 2/27/2026 | 4.1, 7.1, 9.2, 10, 11.1, 12, 13, 18.2, 19, 20 |
| `pa_xc_2026` | PA Cross Country Rules and Guidelines, updated 3/1/26 | 1 to 4 (3.J on drop-downs) |
| `pa_mut_2026` | USATF Pacific MUT Grand Prix Rules, updated January 13, 2026 | 2 to 8 |

| | Road | Cross country | MUT |
| --- | --- | --- | --- |
| Individual points | 150 for 1st down to 1 for 100th (LDR 11.1) | Same table (XC 1) | 40, 31, 24, 19, 16, 14, 12, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1 for places 1 to 18 (MUT 3) |
| Divisions | Open (16 to 39), 40, 50, 60, 70, 80 (LDR 10) | Same | Open (16 to 29), 30, 40, 50, 60, 70, 80 (MUT 2) |
| Decade carry-over | Yes | Yes | No. Race-day age only (MUT 8) |
| Age+ standings | Yes | Yes | No |
| Races counted, individuals | All | Best five plus the championship race (XC 4) | Best seven, with at most four above factor 1.0 and at most one at factor 2.0; all races if the series has seven or fewer (MUT 3) |
| Races counted, teams | One fewer than the number of events in the series (LDR 12.3) | Best five plus the championship race | Same as individuals (MUT 4) |
| Team ranking | Cumulative time of the scorers (LDR 12.2(a)) | Place-sum scoring (XC 3) | Cumulative time of the top three (MUT 4) |
| Team points per race | 10, 9, 8, 7, 6, 5, 4, 3, 2, and 1 from 10th (LDR 12.1) | 15, 13.5, 12, 10.5, 9, 7.5, 6, 4.5, 3, 1.5 for places 1 to 10 (XC 2) | The individual table (MUT 4) |

The 2026 LDR text sets no limit on how many races count toward an individual's road total. LDR 9.2(b) caps the series, at fourteen races plus up to two relays, not the athlete, so the road rule set counts every race. The six-race limit in the 2019 guide does not appear in the 2026 text.

Individual points are multiplied by the event's scoring value (LDR 13.1, MUT 5), and team points by the event's team scoring value.

## Set up a series

RM has no Grand Prix admin screen and no WP-CLI command. Series and events are created through the `GrandPrix` class, and standings are computed by calling it. From WP-CLI on a site with the Grand Prix feature enabled:

```sh
wp eval '
$gp = new PAUSATF\Results\GrandPrix();
$series = $gp->create_series([
    "name" => "2026 Road Grand Prix, Short",
    "season_year" => 2026,
    "ruleset" => "pa_road_2026",
    "series_part" => "short",
]);
$gp->add_event_to_series($series, 1234, ["multiplier" => 1.0, "distance_m" => 5000]);
$gp->calculate_standings($series);
'
```

The example is a sketch of the calls, not a tested runbook. Replace `1234` with an event post ID.

### Series columns

| Column | Meaning |
| --- | --- |
| `name`, `slug`, `season_year` | Identity. `slug` defaults from `name`. `slug` and `season_year` together are unique. |
| `ruleset` | `pa_road_2026`, `pa_xc_2026`, `pa_mut_2026`, or empty for the legacy scoring. Added by the GP schema upgrade, `varchar(32)`. |
| `series_part` | `short` or `long` for the two road series, otherwise empty. `varchar(10)`. |
| `status` | `active`, `completed` or `archived`. `get_active_series()` returns active series for a year. |
| `place_points`, `min_races`, `max_races`, `drop_worst`, `bonus_points`, `divisions`, `scoring_system` | Used only by the legacy scoring. A rule set ignores them. |

The road Short and Long series are two series rows. The Short series is for events of 10K and shorter and the Long series for 12K up to the marathon, as the PA road racing page describes them. `add_event_to_series()` returns false when the event's `distance_m` does not fit the series part (`GrandPrixRules::road_series_part()`; 10K to 12K fits neither). The check runs only when both a part and a distance are present.

### Event options

Pass these to `add_event_to_series($series_id, $event_id, $options)`:

| Option | Stored as | Meaning |
| --- | --- | --- |
| `multiplier` | `multiplier` (decimal 3,2; default 1.00) | The event's scoring value: LDR 13.1 for road and XC, the distance factor for MUT (rule 5). |
| `team_multiplier` | `team_multiplier` | The team scoring value when it differs (LDR 13.2(d)). It defaults to `multiplier`. |
| `championship` | `is_championship` | Marks the XC championship race, which always counts (XC 4). |
| `distance_m` | `distance_m` | Race distance in meters. Road team sizes change above 25K (LDR 12.2(a)). |
| `order`, `required` | `event_order`, `is_required` | Display order and a flag stored for sites; scoring does not read them. |

## How standings are computed

`GrandPrix::calculate_standings($series_id)` loads the series' events and every result row for them, runs `GrandPrixStandings::compute()` and replaces all stored rows for the series. `compute()` has no database access.

### Who scores

An individual result scores only when it passes all of these:

- Sex is M or F. A result with no scoring category is left out (see [classification-and-records.md](classification-and-records.md#sex-as-listed)).
- The athlete is not known to be a non-member. `GPEligibility::member()` asks the `pausatf_results_gp_member_status` filter. A false removes the result. A true or an unknown (null) keeps it, and only a true makes the row verified.
- The age is 16 or older (`GrandPrixRules::MIN_AGE`). Younger runners are left out, as LDR 10 and the XC rules allow them to run unattached but not to score.

Residency (LDR 4.1) is not checked.

### Divisions and places

For each race, RM places the eligible athletes within each division and sex, using the printed overall place, or the time when no place is printed. Equal places tie and the next place is skipped. Points come from the rule set's table for that place, times the event multiplier. Because non-members are removed before placing, places are counted among scorers only.

- **Division standings.** An athlete is in the division for their age on race day. A runner in an older division is not counted in a younger one. The decade codes are `OPEN`, `40`, `50`, `60`, `70`, `80` (and `30` for MUT), kept with `gender`.
- **Decade carry-over (road and XC).** An athlete who enters a new decade during the year also scores in the younger division for the rest of that year, and scores in the new one from the birthday (LDR 10). With a birth date from `pausatf_results_gp_birth_date`, RM carries them when the season year minus the birth year equals their age. Without one, RM carries them when the same athlete already raced earlier in the series at the younger age.
- **Age+ standings (road and XC).** Each standing includes everyone at or above that age, labeled `40+`, `50+` and so on, and `OPEN` for everyone 16 and older. The PA texts use these for prize money, with no double dipping (see [Not modeled](#not-modeled)).
- **No age.** The result's listed division is used if it reads as Open or contains a two-digit age, and the row is unverified. RM derives no age from it.

### Race selection

After scoring, the rule set marks which races count toward the total. A race that does not count is kept in the row's `races` data with `counted` false. A total is rounded to two decimals, and ranks within a standing are competition ranks (1, 2, 2, 4) ordered by total points, then name.

### Teams

A team result scores only when the runner's club is not blank or unattached (`unattached`, `unatt`, `ua`, `none`, `n/a` and `-` are ignored), `GPEligibility::check()` does not return false, and the runner has a division. The club key is the lowercase, space-normalized club name.

`GPEligibility::check()` asks `pausatf_results_gp_member_status` and `pausatf_results_gp_is_pa_club`. A false from either removes the runner from team scoring (LDR 7.1, XC 3.B). An unknown counts as eligible and unverified.

- **Road.** A club's scorers are ranked by cumulative official time. Beyond 25K, three scorers are required in every division. At 25K and shorter: five for Open and Men's 40s, four for Men's 50s, three otherwise (LDR 12.2(a)). A team one scorer short in the five and four divisions is scored but ranks after every full team. The club's next runners form a B team that takes a place and scores nothing, which can displace another club's team (LDR 20.1). Team division follows the age, and `pausatf_results_gp_team_division` can move a runner for drop-downs (LDR 18.2, 19).
- **Cross country.** `XCTeamScoring` applies XC section 3. After removing non-scoring runners, a runner's points are their place in the division. Five scorers and two displacers for Open and Men's 40s, and for Women's Open; four scorers and two displacers for Men's 50s; three scorers and two displacers elsewhere. Teams one short in the five-runner divisions and in Men's 50s rank after full teams. Low total wins; a tie goes to the team whose last scorer placed better. The next five runners form a B team, which displaces but does not score. `pausatf_results_xc_team_division` moves a runner to a younger team division (XC 3.J: only for runners in that team's race, which RM cannot tell from heats).
- **MUT.** Teams are Men and Women regardless of age, ranked by the time of their top three. An Overall row per club sums its team divisions, with `gender` `X` and division `OVERALL`. Mixed teams are not scored.

### Stored rows

Rows go to `{prefix}pausatf_grand_prix_standings`. `entry_type` is `individual` or `team`. `standing_type` is `division`, `age_plus` or `team`. `athlete_key` holds the [identity key](athlete-identity.md) for individuals and `club:<name>` for teams. `results_data` holds the per-race JSON. The old unique key on athlete name is dropped by the GP schema upgrade so that homonyms and the same athlete in a division and an age+ standing can coexist.

## Data a site must supply

RM holds none of this data. Sites supply it with filters, normally from PAUSATF Site or a membership system. At the time of writing RM registers no callbacks, so until a site adds them every row is unverified, no non-member is removed, and decade carry-over relies on the earlier-race rule.

| Filter | Arguments | Return |
| --- | --- | --- |
| `pausatf_results_gp_member_status` | `null`, result row | `true` current PA member, `false` not a member (removes the result), `null` unknown |
| `pausatf_results_gp_is_pa_club` | `null`, club name, result row | `true`, `false` or `null`, for team scoring |
| `pausatf_results_gp_birth_date` | `null`, athlete key, result row | A `Y-m-d` string, used for decade carry-over |
| `pausatf_results_gp_team_division` | division or null, result row | Division code for road team scoring (drop-downs, LDR 18.2 and 19) |
| `pausatf_results_xc_team_division` | division or null, result row | Division code (`OPEN`, `40` to `80`) for XC team scoring |

The result row is the `pausatf_results` row: `athlete_name`, `athlete_id`, `athlete_age`, `club`, `division`, `sex` and the rest. The athlete key is `archive:<page key>`, `rm:<post id>` or `name:<name>`.

## Recomputing

Every RM importer fires `pausatf_results_imported` (event ID, result count) once per event after it stores that event's results: the Import screen, the scheduled sync and batch import, `POST /pausatf/v1/import`, the canonical event import, CSV, Hy-Tek, RunSignUp, Athlinks, the timing systems and UltraSignUp. `GrandPrix` listens for it and recomputes every series containing the event, so standings follow imports without site code. It also listens for `pausatf_result_imported` with the same arguments, which RM never fires, so a site can trigger a recompute for an event itself; or call `calculate_standings()` as in the example above. Recomputing deletes and rewrites the series' standing rows.

## Display

`[pausatf_grand_prix series="slug" year="2026" type="division" division="" gender="" limit="50"]` prints one table per sex and division with rank, athlete, races and points. `type` is `division` (default), `age_plus` or `team`. The table does not show the verified flag. The GraphQL `grandPrix` query returns division standings only and has no `type` or `verified` field.

## Legacy series

A series with no `ruleset` is scored the earlier way: a points table from the series row (the default is the 100-point table in `GrandPrixScoring::PA_ROAD_POINTS_A`, places 1 to 50), the series multiplier per event, `drop_worst`, `max_races` and `min_races`, and the divisions list on the series row (`OPEN`, `M40` and so on). Standings group athletes by identity key, division and sex, and a result with no printed sex and no sex in the division goes to sex `X`. Legacy standings are all `standing_type` `division` and `entry_type` `individual`. New series should use a rule set.

## Not modeled

- Membership, residency (LDR 4.1 and 4.2) and the 90-day rule for changing clubs (LDR 4.4). Residency for teams (LDR 12.2(d)) is suspended by the PA Board.
- MUT Mixed teams and the MUT petition to stay in a younger division (rule 8).
- Duration races ranked by distance (MUT rule 7); RM ranks by time.
- Minimum race counts for awards (MUT rules 9 and 10), prize money, entry discounts and the no-double-dipping rule for age+ prizes.
- Series composition limits: no more than fourteen road races for individuals plus two relays (LDR 9.2(b)), and the road scoring value cap of 4.0 with no event more than 1.0 above the next (LDR 13.2(a)).
- Relay team members scoring on one team only (LDR 12.2(e)), and one team per athlete per event (LDR 19.3) except by the road drop-down filter.
- The team score sheet deadlines and club disputes (LDR 18.2 and 18.3). RM scores from results, not from club-submitted score sheets, so a road team RM scores may differ from one PA's scorer accepted.

## Open questions

These need a decision from the PA scorers or the association. RM follows the reading in the first column.

| Question | What RM does |
| --- | --- |
| LDR 12.2(a)(i) says "Open (Men and Women), Men's 40+: 5 runners" and (ii) says "Men's 50+: 4 runners". Does "50+" cover Men's 60, 70 and 80? XC 3.D names Men's 60s, 70s and 80s as three-runner teams. | Road: five scorers for Open and Men's 40, four for Men's 50, three for all others, matching the XC groups. |
| MUT 5 and 6 give distance factors of 1.0, 1.5 and 2.0 and a prize enhancement of 0.5 per $1,000 up to 1.5. LDR 13.2(g) and (h) describe a MUT difficulty rating of 1.0 to 8.0 raised by 1.0 per $1,000. | The MUT rules (January 13, 2026). The "at most one at 2.0" limit tests `multiplier >= 2.0`, so ratings above 2.0 would all count against that limit. |
| XC 3.B refers to "sections 5.C and 5.D" for incomplete teams. The 5-page document has no such sections. | Incomplete teams follow 3.C and 3.D. |
| The PA texts say a runner continues in the younger division "if they run in a race that includes that age division". | RM carries the runner on the birth-year test, or the earlier-race test, and does not check whether the race had that division. |
| LDR 12.2(a) team ranking on equal times. | Teams with equal time and equal completeness share a place. |
| Age comes from the printed `athlete_age`, not from the birth date. | A birth date from the filter is used only for carry-over. |
