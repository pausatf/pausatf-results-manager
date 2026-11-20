# Age grading

Results Manager ships no age-grading factors. Age-graded rankings stay empty until an administrator imports a factor table, and they are off by default even then.

## Method

WMA age-grading tables give, for each event and sex, an open standard (the best performance at any age) and a factor for each single year of age. The factor is 1.0 at peak ages and below 1.0 elsewhere. For a time `t`:

- age-graded time = `t × factor`
- age-graded percentage = `open standard ÷ (t × factor) × 100`

The second line equals `age standard ÷ t`, where `age standard = open standard ÷ factor`. USATF's Masters LDR age-grading calculator uses the WMA tables; the 2023 revision is current as of this writing. Load the edition the association adopts.

Rules RM applies:

- Only M and F results are graded, by the listed scoring category.
- Athletes under 18 are never graded. Results still show minors; RM derives no new age data about them.
- Ages are not interpolated. If the table has no factor for an athlete's age, the result is not graded.
- Each ranking row stores the table edition. Rankings show only rows from the loaded edition, so importing a new edition hides the old rows until rankings are regenerated.

## File format

A UTF-8 JSON object:

```json
{
  "edition": "WMA 2023 road",
  "source": "Where the numbers came from: document title and URL",
  "events": {
    "5K": {
      "M": { "standard_seconds": 0, "factors": { "18": 0, "19": 0 } },
      "F": { "standard_seconds": 0, "factors": { "18": 0 } }
    }
  }
}
```

The zeros above are placeholders for the shape only; a file with them is rejected.

- `edition` and `source` are required and are stored with the table.
- Event names must match the names rankings use: `5K`, `10K`, `15K`, `10 Miles`, `Half Marathon`, `Marathon`.
- `standard_seconds` is the open standard in seconds and must be positive.
- `factors` maps an age in whole years to a factor greater than 0 and at most 1.
- An event may list `M`, `F` or both.

## Import

```sh
wp pausatf-results age-grading import /path/to/table.json
wp pausatf-results age-grading status
```

Importing validates the whole file before storing anything, replaces any earlier table, and adds the `age_grade_edition` column to the rankings table if it is missing. Then enable **Age-Graded Rankings** under Features and regenerate rankings.

## Upgrade note

Earlier releases computed age-graded rankings from placeholder factors, and the percentage ignored the factor. Those rows carry no edition, so they are no longer shown. Regenerating rankings with a table loaded deletes and rebuilds them.
