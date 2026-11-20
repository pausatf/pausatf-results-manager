# Athlete identity

PAUSATF Site's Archive is the canonical record of who is who. It joins names printed differently ("Kiernan, Russ", "RUSS KIERNAN"), applies the site owner's merge decisions, and splits people who share a name. Results Manager uses it when it can, through `AthleteIdentity::resolve($printed_name, $athlete_id)`.

## How a name resolves

1. **Site archive.** This step runs when the Archive module is active (`ArchiveOwnership::site_provides()`, so the `pausatf_results_defer_archive_to_site` filter applies) and has a current build. RM derives the archive page key the way the site does, `Meet_Rules::slug(Athlete_Joins::canon($name))`, then the plain `slug($name)`. It follows any athlete alias in that build. If the page exists and lists no separate people, the key is `archive:<page key>` and is marked verified.
2. **Split names are not used.** If the page lists several people who share the printed name, a name alone cannot say which one it is. RM moves on to the next step and sets `ambiguous`.
3. **RM's athlete post.** The key is `rm:<post id>`. RM links that post by source identifiers or by name, so it is not marked verified.
4. **Name only.** The key is `name:<lowercase name>`, unverified.

Keys are worked out per request and cached for that request only. Nothing is written to the database.

## Where it is used

- Grand Prix standings group an athlete's results across events by this key instead of by printed name.

## What remains

- Results are not yet stamped with an archive key. Each request resolves the key from the printed name.
- A homonym the archive split can be told apart only by evidence such as club, age or race. That evidence is not passed in yet, so these athletes fall back to RM's own matching.
- RM's athlete posts and tables are unchanged and are not merged into the archive.
- Club identity (`archive_clubs`) is not used yet.
- The Grand Prix standings table still has a unique key on athlete name. Two people with the same printed name can therefore still collide when standings are written; the Grand Prix branch changes that key.
