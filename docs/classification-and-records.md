# Classifications, divisions, placement time and records

This page describes how Results Manager (RM) decides who competes in which classification and division, how it records sex, which time it places and awards by, and which record rules it encodes. Each rule names its source so a reader can check it. Where RM does not model a rule, the page says so.

## Sources and their status

- **2026 USATF Competition Rules.** The rule book is published only as an image flipbook at usatf.org/governance/rule-books, so the text cannot be searched or copied. The page cites below come from a reading of the flipbook on 2026-11-20 and are paraphrases. Rules 141, 165 (the parts cited), 265 and 320 were read. Rule 300.1, which the age-division code also cites, was not read and is marked below.
- **PA documents.** PA LDR Guidelines and Rules (updated 2/27/2026), PA Cross Country Rules (updated 3/1/26) and the USATF Pacific MUT Grand Prix Rules (updated January 13, 2026). They govern Grand Prix scoring and are covered in [grand-prix.md](grand-prix.md).

The rules engine is the `usatf_rules_engine` feature (on by default). The age-division code is `USATFAgeDivisions`, the record and timing data is in `USATFCompetitionRules`, and both load only while the feature is on. `ListedSex`, `PlacementTime` and `EventDate` are core classes and always load.

## Classifications (Rule 141)

Rule 141.1 defines four classifications. RM applies the age tests per discipline from `USATFAgeDivisions::AGE_RULES`. A discipline that is not listed uses track and field.

| Classification | Rule | Test | RM disciplines |
| --- | --- | --- | --- |
| Open | 141.1(a) | No maximum age. 14 or older on the day of competition for track and field and race walking; 16 or older for cross country and long distance running (LDR). | `track_field` and `race_walk` 14; `road`, `cross_country` and `mut` 16 |
| U20 | 141.1(b) | 14 or older on the day of competition and not turning 20 during the competition year. | `USATFAgeDivisions::is_u20()` |
| Youth | 141.1(c) | Has not reached 19 by the final day of the National Junior Olympic track and field meet of the competition year. This is not the World Athletics U18 definition. | See "Youth divisions" below |
| Masters | 141.1(d) | Track and field and race walking: 35 or older on the first day of the meet. LDR: 40 or older on the first day of the meet. USATF's LDR masters age differs from WMA and World Athletics. | `track_field` and `race_walk` 35; `road`, `cross_country` and `mut` 40 |

Rule 141.3 sets minimum ages for long events: 16 for events longer than the half marathon or one hour, and 18 for events longer than the marathon or two hours. `USATFAgeDivisions::minimum_age_for_event($distance_m, $duration_s)` returns 16, 18 or null. It reports the minimum; nothing in the importers rejects an entry for being under it.

The Governance Handbook's Cross Country Running Council text (Regulation 13(D)(1)(b)(i)) says Open cross country athletes are 14 or older, while Rule 141.1(a) and the PA Cross Country Rules say 16. RM follows the rule book.

`pausatf_results_age_rules` (filter, one argument: the whole `AGE_RULES` array) lets a site change these values for a meet. RM registers no callback for it.

### Divisions

- **Masters meet divisions (Rule 320.2).** Five-year groups from 25-29 (M25 and W25) and 30-34 through 95-99, then 100 and over (M100 and W100). Rule 320.4 takes the age on the first day of the meet. `get_masters_meet_division($age, $sex)` returns the Rule 320 group for a meet that follows these groups, including M/W25 and M/W30. The masters classification itself still starts at 35 or 40 (Rule 141.1(d)), so `get_division_for_age()` uses the discipline's masters age as the lowest masters group and treats everyone between the youth bands and that age as Open.
- **Relay divisions (Rules 320.3 and 320.5).** Not modeled.
- **Youth divisions.** RM keeps six bands by year of birth: 8 and under, 9-10, 11-12, 13-14, 15-16 and 17-18, with the stored codes `U8`, `U10`, `U12`, `U14`, `U16` and `U18`. The code cites Rule 300.1(b) for the bands and for taking the age reached by December 31 of the competition year. The names are PA's; Sub-Bantam is PA only. Rule 300.1 was not read for this page, so the bands are as the code states them. Rule 141.1(c), which was read, tests youth status against the Junior Olympic meet date, not December 31. The two tests give different answers for athletes who turn 19 between the meet and the end of the year; RM uses December 31 for division assignment.
- **Open plus age division.** `get_eligible_divisions()` returns the athlete's own division and, when the athlete is at least the discipline's Open age, the Open division as well.

Age is taken on the date the rule names, and the caller passes it in. RM stores `athlete_age` as printed in the results and does not compute age from a birth date, with one exception: Grand Prix standings accept a birth date through a filter (see [grand-prix.md](grand-prix.md)).

## Sex as listed

Rule 141.2 limits the female category to athletes of female reproductive biology and genetics. RM does not decide eligibility for a category. It records what the results list and scores only the two categories USATF defines.

- `sex_listed` holds the value exactly as the results print it, trimmed and cut to 20 characters. Blank becomes null.
- `sex` holds the scoring category: `M` for M, MALE, MAN, MEN, BOY or BOYS, and `F` for F, W, FEMALE, WOMAN, WOMEN, GIRL or GIRLS (case-insensitive). Any other value, such as X, NB or blank, leaves `sex` null. A null sex is never counted as male. Such results display normally and are not scored in Grand Prix standings or rankings.

The `sex_listed` column arrives through the results schema upgrade (see [upgrading.md](upgrading.md)). Until the `pausatf_results_schema` option reaches 1, writers skip `sex_listed` and write only `sex`.

Which importers apply this today:

| Path | Writes `sex` and `sex_listed` through `ListedSex` |
| --- | --- |
| Canonical event import (`POST /pausatf/v1/events/import`, and the `pausatf import-2026-calendars` command) through `ResultsRepository` | Yes |
| UltraSignUp importer | Yes |
| Admin URL import, scheduled sync, batch import and `POST /pausatf/v1/import` (`ResultsImporter`) | No. The parsers read sex, but the importer does not store it. |
| CSV importer | Writes the printed value to the one-character `sex` column without mapping; `sex_listed` is not written |
| Hy-Tek, RunSignUp, Athlinks and timing system importers | No |

Where `sex` is null, Grand Prix standings fall back to an explicit sex in the division label, such as M40 or Women 50. A bare Open label does not decide sex.

## Placement time (Rule 165)

Rule 165.16 lets the Games Committee of a Masters LDR championship, or of an LDR non-championship race held partly or entirely outside the stadium, choose net time, gun time or a stated combination for times, places and awards, provided the entry materials say which. Net time is the finish transponder time minus the start transponder time, unrounded. Rule 165.4(b) allows the same election for non-championship races outside the stadium.

`PlacementTime` applies the choice:

- **Basis.** `PlacementTime::basis($event_id)` reads the event meta `_pausatf_placement_time` (`gun`, `net` or `combination`). The `pausatf_results_placement_time` filter (basis, event ID) can supply it instead, for example from a series setting. RM writes no value for the meta; a site or an editor sets it. Anything unrecognized, and an event that states nothing, is gun time.
- **Combination.** The rules do not define a combination; the entry materials do. The filter `pausatf_results_placement_time_combination` receives `gun` and the provider's fields and returns `gun` or `net` for one result. Without a callback, gun time is used.
- **Missing time.** If the chosen time is absent, `choose()` falls back to the other one and reports which was used, so the displayed time matches the stored one. Rule 165.16 leaves a missing start or finish time to the Referee; the fallback only keeps the provider's other time on record.
- **Official time.** Races outside the stadium are read to 0.01 s and converted to the next longer whole second (Rule 165.11(c)); hand times round up the same way (Rule 165.7). `PlacementTime::official_seconds()` does this, and the result is what `time_seconds` stores. The rounding happens after ranking: Rule 165.16 ranks places on the unrounded time, so equal raw times tie and times that round to the same second do not. RM stores the provider's place and only the rounded time, so it cannot rebuild a tie from `time_seconds` alone.

Callers: the RunSignUp, Athlinks and ChronoTrack importers choose the basis per event. The UltraSignUp importer and the MYLAPS client round up with `official_seconds()` directly. The legacy HTML, CSV, Hy-Tek, Webscorer and RaceTab paths store the time as parsed.

The default of gun time is stated in the code as Rule 165.4. The verified reading of Rule 165 does not include a sentence that makes gun time the default, so the default is RM's choice, not a quotation of the rule.

### Records and time basis

A record claim uses gun time, except Masters records (Rules 165.16(c) and 265.8). Rule 265.8 allows rounded transponder net times for American Masters LDR records when the athlete wins the division (Rule 320 age groups from 40) and the start and end mats are fully outside the start and finish. `USATFCompetitionRules` holds this as data under its road racing timing entry. The records workflow does not read it: RM's record checks compare the performance it is given and do not pick a time basis.

## Road record courses (Rule 265.4)

For a road performance to count as a record, Rule 265.4 requires:

1. The net elevation drop from start to finish is at most 1 part per thousand, which is 1 m per km.
2. Start and finish are no more than 50% of the race distance apart in a straight line.
3. World and National Open records follow World Athletics rules, and other records may need re-measurement under Road Running Technical Council criteria.

`USATFCompetitionRules::road_course_meets_record_limits($distance_m, $separation_m, $net_drop_m)` checks limits 1 and 2. Both limits are inclusive, a net climb always passes the drop limit, and a non-positive distance or a negative separation fails. The constants are `ROAD_RECORD_MAX_DROP_PER_KM` (1.0) and `ROAD_RECORD_MAX_SEPARATION` (0.5). World Athletics Competition Rule 31 sets the same two limits. The limits govern record eligibility, not course certification: USATF's certification page says a course that fails them can still be certified.

`get_road_certification_rules()` also lists Rule 265.5 (except for race walking and Masters, women's road records are kept separately for women-only and mixed races) as data. No record flow calls the helper or applies Rule 265.5: record submission and approval (`RecordsDatabase`) do not receive course elevation or separation. A site that wants the check must call the helper itself.

Other record rules were not verified and are not enforced: Rule 265.1 (verifying non-winning road performances), 265.2, 265.6 (time-defined events) and the wind and altitude entries in `USATFCompetitionRules`, whose rule numbers (Rule 184 for wind) come from code comments and were not read for this page.

## Event standards

`USATFEventStandards::get_standards($year)` returns the 2024 USATF outdoor championship standards for every year, because no other year is defined. Treat it as reference data from 2024, not as 2026 standards.
