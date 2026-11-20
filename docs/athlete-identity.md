# Athlete identity

PAUSATF Site's Archive is the canonical record of who is who. It joins names printed differently ("Kiernan, Russ" and "RUSS KIERNAN"), applies the site owner's merge decisions, and splits people who share a name. Results Manager (RM) asks it for an identity key through `AthleteIdentity::resolve($printed_name, $athlete_id)` and falls back to its own data when the archive cannot answer.

The method returns four fields: `key`, `source` (`archive`, `rm` or `name`), `verified` and `ambiguous`.

## Resolver order

1. **Site archive.** This step runs when PAUSATF Site's Archive module is active (`ArchiveOwnership::site_provides()`, so the `pausatf_results_defer_archive_to_site` filter applies) and has a current build (the newest `archive_builds` row in state `current`). RM derives the archive page key the way the site does, `Meet_Rules::slug(Athlete_Joins::canon($name))`, and then the plain `slug($name)`. It follows an athlete alias in that build. If the page exists and lists no separate people, the key is `archive:<page key>`, the source is `archive` and the result is `verified`. The first candidate key that finds a page decides; RM does not keep looking.
2. **Shared names are not used.** If the page lists several people who share the printed name, a name alone cannot say which one it is. RM sets `ambiguous` and goes on to the next step.
3. **RM athlete post.** When the caller passes an `athlete_id` greater than zero, the key is `rm:<post id>`, the source is `rm` and `verified` is false. RM links that post to the result by a source identifier (`athlete_uid` or `athlete_source_id`), then by normalized name, then by exact title, and finally through the `pausatf_find_athlete` filter.
4. **Name only.** The key is `name:<lowercase name>` with accents removed and punctuation collapsed to single spaces, `verified` is false and the source is `name`.

Any failure in step 1 (the module is off, tables are missing, no current build, a query error) means no archive lookup for that request. RM then resolves through steps 3 and 4. A resolver failure never stops a standings run.

## Where the key is used and stored

- Grand Prix standings group an athlete's results across events by this key instead of by printed name. Standing rows store the key in `athlete_key`, and a row can be verified only if each scorer's key came from the archive, among other conditions (see [grand-prix.md](grand-prix.md)).
- Keys are worked out per request and cached for that request only. Nothing is written to the database apart from the Grand Prix `athlete_key` column.
- Tests and sites with another source can replace the lookup with `AthleteIdentity::set_lookup(callable|null)`; `AthleteIdentity::reset()` clears it. The callable receives a page key and returns `['athlete_key' => string, 'people' => list]` or null.

## What it does not do yet

- Results are not stamped with an archive key. Each request resolves the key from the printed name.
- A person the archive split from a namesake can be told apart only by evidence such as club, age or race. RM does not pass that evidence, so these athletes fall back to RM's own matching and are marked ambiguous.
- RM's athlete posts and results tables are unchanged and are not merged into the archive.
- Club identity (`archive_clubs`) is not used. Team standings key on the normalized club name.
- The name-only fallback merges two people who share a printed name when neither has an RM athlete post.
