<?php
/**
 * Group printed club names that are evidently the same club, for browsing only.
 *
 * Hy-Tek cuts club names to its column width ("Pleasanton H", "Pleasanton Heat") and pages add
 * a city ("Pleasanton H,Pleasa") or a suffix ("Pleasanton Heat TC", "Pleasanton HeatB").
 * Every entry keeps its printed name; grouping only decides which team page lists it.
 * A cut-off name joins a longer one only when exactly one longer name starts with it.
 *
 * Port of the static preview's scripts/team_groups.py. Where Python iterates a set and the
 * order can matter (ties in the weight and length orderings), this port iterates in first
 * appearance order; Python's own order there depends on PYTHONHASHSEED. tests/parity records
 * the printed names whose group changes with that seed.
 *
 * Printed names are map keys in the results, so PHP turns numeric names such as "38" into
 * int keys; lookups with the string still work.
 *
 * @package PAUSATF\Results\Archive\Derived
 */

declare(strict_types=1);

namespace PAUSATF\Results\Archive\Derived;

require_once __DIR__ . '/class-python-text.php';
require_once __DIR__ . '/class-sequence-matcher.php';
require_once __DIR__ . '/class-slug.php';

use PAUSATF\Results\Archive\Derived\PythonText as Py;

/**
 * @phpstan-type Group array{name:string,variants:array<array-key,int>,count:int,roster_evidence:list<array{names:list<string>,shared_athletes:int,of:int}>}
 */
final class ClubGrouper {
    private const CLUB_WORDS = [
        'track and field club', 'track & field club', 't&f club', 'track and field', 'track & field', 'track club', 'running club',
        'athletic association', 'athletic club', 'athletics', 'track', 'field', 'club', 'tfc', 'tc', 't&f', 'tf', 'rc', 'ac', 't',
    ];
    private const CUT_AFTER   = [ 'track', 'tf', 'tc', 't&f', '&', 'and', 'field', 'running', 'athletic' ];
    private const CUT_WORDS   = [ 'club', 'field', 'track', 'athletic', 'athletics', 'association', 'assoc', 'running' ];
    private const CUT_WIDTHS  = [ 11, 12, 16, 19, 25, 26 ];

    /** @var array<string,string> */
    private array $merged = [];

    /**
     * Comparable core of a printed club name: letters and digits only, without city, team number,
     * B-team marker or club-type words. Spacing and punctuation differences ("M.P.Striders" /
     * "MP Striders", "Nor Cal" / "NorCal") therefore compare equal.
     */
    public static function base( string $name ): string {
        $t = Py::sub( '(?<=[a-z])TC\\b', ' TC', Py::strip( $name ) );              // "Los GatosTC"
        $t = Py::lower( Py::sub( '\\s+', ' ', $t ) );
        $t = Py::sub( ',.*$', '', $t );                                              // ",Pleasa" city suffix
        $t = Py::sub( '^\\d+\\s*-\\s*', '', $t );                                    // Hy-Tek team number "38-"
        $t = Py::sub( '^(38|46)\\s+(?=[a-z])', '', $t );                             // region number before a team code "38 PH"
        $t = Py::sub( '\\s*\'[ab]\'$|\\s+b(\\s+team)?$|(?<=[a-z])b$', '', $t );    // B teams / relay letters
        $t = Py::sub( '\\bt\\s*(&|and)\\s*f\\b', 'tf', $t );                         // "T&F", "T & F", "T and F"
        $t = Py::sub( '\\s*\\$\\s*[\\d,]+(\\.\\d+)?\\s*$', '', $t );                 // prize money printed after a team name
        $t = Py::sub( '\\b(\\w{1,2})\\s*&\\s*(\\w{1,2})\\b', '${1}${2}', $t );       // "WV J&S" -> "WV JS"
        $t = str_replace( '#', '', str_replace( '&', ' and ', $t ) );
        $t = Py::strip( Py::sub( '\\s+', ' ', $t ), ' .-' );
        $t = self::stripClubWords( str_replace( ' & field', ' and field', str_replace( ' and ', ' & ', $t ) ) );
        return (string) preg_replace( '/[^a-z0-9]/', '', $t );
    }

    /**
     * @param list<array{0:string,1:int}>      $counts    Printed name and number of entries, in first-seen order.
     * @param array<array-key,string>          $overrides Display name chosen for a group, keyed by its core.
     * @param list<array{0:string,1:string}>   $manual    [printed name, printed name of the club it belongs to].
     * @param list<string>                     $separate  Printed names whose core never joins another by rule.
     * @param array<array-key,list<string>>    $rosters   Printed club name => athlete names (lower case).
     * @param (callable(string):string)|null   $slug
     * @return array{0:array<array-key,string>,1:array<array-key,Group>} Printed name => group key, and groups.
     */
    public static function buildGroups( array $counts, array $overrides = [], array $manual = [], array $separate = [], array $rosters = [], ?callable $slug = null ): array {
        return ( new self() )->build( $counts, $overrides, $manual, $separate, $rosters, $slug ?? Slug::of( ... ) );
    }

    /**
     * @param list<array{0:string,1:int}>    $counts
     * @param array<array-key,string>        $overrides
     * @param list<array{0:string,1:string}> $manual
     * @param list<string>                   $separate
     * @param array<array-key,list<string>>  $rosters
     * @param callable(string):string        $slug
     * @return array{0:array<array-key,string>,1:array<array-key,Group>}
     */
    private function build( array $counts, array $overrides, array $manual, array $separate, array $rosters, callable $slug ): array {
        /** @var array<string,array<array-key,int>> $bases core => printed name => entries */
        $bases = [];
        foreach ( $counts as [ $name, $n ] ) {
            $b = self::base( $name );
            if ( '' !== $b && 1 !== preg_match( '/^(unat|unatt|unattach|unattched|unattatched)/', $b ) && ! in_array( $b, [ 'independent', 'none', 'na' ], true ) ) {
                $bases[ $b ][ $name ] = ( $bases[ $b ][ $name ] ?? 0 ) + $n;
            }
        }
        $cores = Py::keys( $bases );
        // Plural/singular of the same core ("heats"/"heat") are one club.
        foreach ( $cores as $b ) {
            if ( str_ends_with( $b, 's' ) && ! str_ends_with( $b, 'ss' ) && isset( $bases[ substr( $b, 0, -1 ) ] ) ) {   // "heats" -> "heat", not "express" -> "expres"
                $this->merged[ $b ] = substr( $b, 0, -1 );
            }
        }
        $keep = [];
        foreach ( $separate as $x ) {
            $keep[ self::base( $x ) ] = true;
        }

        // Manual groupings (printed name -> printed name of the club it belongs to) win over the rules.
        // Joined root to root, so a decision can never create a cycle with an automatic rule.
        // Each decision names a club (dst) and forms that belong to it; whichever of them occur here are joined,
        // even when the dst form itself never appears in this data.
        $byDst = [];
        foreach ( $manual as [ $src, $dst ] ) {
            $byDst[ 'k' . $dst ] ??= [ $dst ];
            $byDst[ 'k' . $dst ][] = $src;
        }
        foreach ( $byDst as $forms ) {
            $present = [];
            foreach ( $forms as $x ) {
                $c = self::base( $x );
                if ( isset( $bases[ $c ] ) && ! in_array( $c, $present, true ) ) {
                    $present[] = $c;
                }
            }
            foreach ( array_slice( $present, 1 ) as $c ) {
                if ( $this->root( $c ) !== $this->root( $present[0] ) ) {
                    $this->merged[ $this->root( $c ) ] = $this->root( $present[0] );
                }
            }
        }

        // A cut-off core joins a longer core that starts with it, when all such longer cores
        // already resolve to one club. Longest first, so chains like "st" < "stride" < "striders" settle.
        $cores = $this->sortDesc( $this->roots( $cores ), static fn( string $c ): int => strlen( $c ) );
        foreach ( $cores as $b ) {
            if ( strlen( $b ) < 7 || isset( $keep[ $b ] ) ) {
                continue;
            }
            $targets = [];
            foreach ( $cores as $c ) {
                if ( strlen( $c ) > strlen( $b ) && str_starts_with( $c, $b ) && ! isset( $keep[ $c ] ) ) {
                    $r = $this->root( $c );
                    if ( ! in_array( $r, $targets, true ) ) {
                        $targets[] = $r;
                    }
                }
            }
            // Near-identical spellings among the targets ("greyhounds"/"greyounds") are one club.
            if ( count( $targets ) > 1 ) {
                $weights = $this->weights( $bases );
                $ts      = $this->sortDesc( $targets, static fn( string $c ): int => $weights[ $c ] ?? 0 );
                $similar = true;
                foreach ( array_slice( $ts, 1 ) as $t ) {
                    if ( SequenceMatcher::ratioOf( $ts[0], $t ) < 0.9 ) {
                        $similar = false;
                        break;
                    }
                }
                if ( $similar ) {
                    foreach ( array_slice( $ts, 1 ) as $t ) {
                        $this->merged[ $t ] = $ts[0];
                    }
                    $targets = [ $ts[0] ];
                }
            }
            if ( 1 === count( $targets ) ) {
                $this->merged[ $b ] = $targets[0];
            }
        }
        // Remaining near-identical spellings of the same length class ("lightening"/"lightning").
        $weights = $this->weights( $bases );
        $cores   = $this->sortDesc( $this->roots( Py::keys( $bases ) ), static fn( string $c ): int => $weights[ $c ] ?? 0 );
        $n       = count( $cores );
        for ( $i = 0; $i < $n; $i++ ) {
            $a = $cores[ $i ];
            for ( $j = $i + 1; $j < $n; $j++ ) {
                $c = $cores[ $j ];
                if ( $this->root( $c ) !== $c || $this->root( $a ) === $this->root( $c ) || strlen( $c ) < 10 ) {
                    continue;
                }
                if ( substr( $c, 0, 6 ) === substr( $a, 0, 6 ) && SequenceMatcher::ratioOf( $a, $c ) >= 0.93 ) {
                    $this->merged[ $c ] = $this->root( $a );
                }
            }
        }

        $evidence = $rosters ? $this->rosterEvidence( $bases, $rosters, $keep ) : [];

        /** @var array<string,array<array-key,int>> $groups */
        $groups = [];
        foreach ( $bases as $b => $names ) {
            $r = $this->root( (string) $b );
            foreach ( $names as $name => $count ) {
                $groups[ $r ][ $name ] = ( $groups[ $r ][ $name ] ?? 0 ) + $count;
            }
        }
        $nameToGroup = [];
        $out         = [];
        foreach ( $groups as $r => $variants ) {
            $r       = (string) $r;
            $display = self::display( $variants );
            $chosen  = $overrides[ $r ] ?? null;
            if ( null === $chosen || '' === $chosen ) {
                $chosen = $overrides[ self::core( $display ) ] ?? null;
            }
            if ( null !== $chosen && '' !== $chosen ) {
                $display = $chosen;
            }
            $key = $slug( $display );
            $ev  = [];
            foreach ( $evidence as $c => $items ) {
                if ( $this->root( (string) $c ) === $r ) {
                    array_push( $ev, ...$items );
                }
            }
            $out[ $key ] = [
                'name'            => $display,
                'variants'        => self::mostCommon( $variants ),
                'count'           => array_sum( $variants ),
                'roster_evidence' => $ev,
            ];
            foreach ( $variants as $name => $count ) {
                $nameToGroup[ $name ] = $key;
            }
        }
        return [ $nameToGroup, $out ];
    }

    /**
     * Roster evidence: the same athletes under two names that fit each other (abbreviation, team code,
     * misspelling) means one club. Each round joins the first qualifying pair, as the Python does.
     *
     * @param array<string,array<array-key,int>> $bases
     * @param array<array-key,list<string>>      $rosters
     * @param array<array-key,true>              $keep
     * @return array<string,list<array{names:list<string>,shared_athletes:int,of:int}>>
     */
    private function rosterEvidence( array $bases, array $rosters, array $keep ): array {
        $sets = [];
        foreach ( $rosters as $name => $athletes ) {
            $sets[ $name ] = array_fill_keys( $athletes, true );
        }
        $evidence = [];
        $changed  = true;
        $rounds   = 0;
        while ( $changed && $rounds < 2000 ) {   // each round joins two clubs; bounded for safety
            $changed = false;
            ++$rounds;
            $clubNames  = [];
            $clubRoster = [];
            foreach ( $bases as $b => $names ) {
                $r = $this->root( (string) $b );
                $clubRoster[ $r ] ??= [];
                foreach ( $names as $name => $count ) {
                    $clubNames[ $r ][ $name ] = true;
                    $clubRoster[ $r ]        += $sets[ $name ] ?? [];
                }
            }
            $clubs = Py::keys( $clubRoster );
            $size  = [];
            // Athlete => clubs listing them, so shared counts for one club come from its own roster.
            $index = [];
            foreach ( $clubs as $pos => $c ) {
                $size[ $c ] = count( $clubRoster[ $c ] );
                foreach ( $clubRoster[ $c ] as $athlete => $_ ) {
                    $index[ $athlete ][] = $pos;
                }
            }
            usort( $clubs, static fn( string $x, string $y ): int => $size[ $x ] <=> $size[ $y ] );
            $order = Py::keys( $clubRoster );
            foreach ( $clubs as $small ) {
                if ( isset( $keep[ $small ] ) || $size[ $small ] < 3 ) {
                    continue;
                }
                $shared = [];
                foreach ( $clubRoster[ $small ] as $athlete => $_ ) {
                    foreach ( $index[ $athlete ] as $pos ) {
                        $shared[ $order[ $pos ] ] = ( $shared[ $order[ $pos ] ] ?? 0 ) + 1;
                    }
                }
                $best = null;
                foreach ( $clubs as $big ) {
                    if ( $big === $small || isset( $keep[ $big ] ) || $size[ $big ] <= $size[ $small ] ) {
                        continue;
                    }
                    $k = $shared[ $big ] ?? 0;
                    if ( ( ( $k >= 4 && $k >= 0.65 * $size[ $small ] ) || ( $k >= 3 && $k === $size[ $small ] ) )
                        && self::fits( $small, Py::keys( $clubNames[ $big ] ) ) ) {
                        if ( null === $best || $k > $best[1] ) {
                            $best = [ $big, $k ];
                        }
                    }
                }
                if ( null !== $best ) {
                    $this->merged[ $small ] = $best[0];
                    $evidence[ $best[0] ][] = [ 'names' => Py::sorted( Py::keys( $clubNames[ $small ] ) ), 'shared_athletes' => $best[1], 'of' => $size[ $small ] ];
                    $changed                = true;
                    break;
                }
            }
        }
        return $evidence;
    }

    /**
     * The smaller core's letters appear in order in a printed name of the larger club, starting
     * at the beginning of one of its words; or the two are near-identical spellings.
     *
     * @param list<string> $bigNames
     */
    private static function fits( string $small, array $bigNames ): bool {
        if ( '' === $small ) {
            return false;
        }
        $sortedSmall = str_split( $small );
        sort( $sortedSmall, SORT_STRING );
        foreach ( $bigNames as $big ) {
            $low = Py::lower( $big );
            $bb  = self::base( $big );
            if ( SequenceMatcher::ratioOf( $small, $bb ) >= 0.85 ) {
                return true;
            }
            // Same words in another order ("adidas transports" / "transports adidas"), or the
            // larger club's name is the start of the smaller's ("empire" / "empire runners").
            $sortedBig = '' === $bb ? [] : str_split( $bb );
            sort( $sortedBig, SORT_STRING );
            if ( ( strlen( $small ) >= 6 && $sortedSmall === $sortedBig ) || ( strlen( $bb ) >= 5 && str_starts_with( $small, $bb ) ) ) {
                return true;
            }
            preg_match_all( '/[a-z0-9]+/', $low, $words, PREG_OFFSET_CAPTURE );
            foreach ( $words[0] as [ $word, $offset ] ) {
                if ( $word[0] !== $small[0] ) {
                    continue;
                }
                $rest = (string) preg_replace( '/[^a-z0-9]/', '', substr( $low, $offset ) );
                if ( self::isSubsequence( $small, $rest ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function isSubsequence( string $needle, string $haystack ): bool {
        $pos = 0;
        $len = strlen( $needle );
        for ( $i = 0; $i < $len; $i++ ) {
            $found = strpos( $haystack, $needle[ $i ], $pos );
            if ( false === $found ) {
                return false;
            }
            $pos = $found + 1;
        }
        return true;
    }

    /**
     * Display: the most common variant among the longest (uncut) printed forms.
     *
     * @param array<array-key,int> $v printed name => entries
     */
    private static function display( array $v ): string {
        $names   = Py::keys( $v );
        $core    = [];
        $width   = [];
        $printed = [];
        $perCore = [];
        foreach ( $names as $x ) {
            $core[ $x ]          = self::core( $x );
            $printed[ $x ]       = Py::rstrip( Py::sub( ',.*$', '', $x ) );
            $width[ $x ]         = Py::len( $printed[ $x ] );
            $perCore[ $core[ $x ] ] = ( $perCore[ $core[ $x ] ] ?? 0 ) + $v[ $x ];
        }
        $coreKeys = Py::keys( $perCore );
        // A form is cut off when its core is a strict prefix of a more common core in the club.
        // Hy-Tek cuts club names at fixed widths (12, 16, 19, 25 characters); a form of exactly that
        // width whose core is a strict prefix of a longer core in the club is a cut-off form.
        $isCut = static function ( string $x ) use ( $names, $core, $width, $printed, $coreKeys ): bool {
            if ( ! in_array( $width[ $x ], self::CUT_WIDTHS, true ) ) {
                return false;
            }
            foreach ( $coreKeys as $c ) {
                if ( $c !== $core[ $x ] && str_starts_with( $c, $core[ $x ] ) ) {
                    return true;
                }
            }
            $prefix = Py::lower( $printed[ $x ] );
            foreach ( $names as $y ) {
                if ( $core[ $y ] === $core[ $x ] && $width[ $y ] > $width[ $x ] && str_starts_with( Py::lower( $y ), $prefix ) ) {
                    return true;
                }
            }
            return false;
        };
        $cut  = static fn( string $x ): bool => null !== Py::search( '\\b(tr|tra|trac|track c|track cl|track clu)$|,', $x, 'i' );
        $full = array_values( array_filter( $names, static fn( string $x ): bool => ! $isCut( $x ) && ! $cut( $x ) ) );
        if ( ! $full ) {
            $full = $names;
        }
        // Support for a full form includes the cut-off forms it explains; short team codes ("Aobt",
        // "Tntt", "CVRR") and single-word fragments are never preferred as the club's name.
        $cutCore = [];
        foreach ( $names as $y ) {
            if ( in_array( $width[ $y ], self::CUT_WIDTHS, true ) || $cut( $y ) ) {
                $cutCore[ $core[ $y ] ] = ( $cutCore[ $core[ $y ] ] ?? 0 ) + $v[ $y ];
            }
        }
        $best    = null;
        $bestKey = null;
        foreach ( $full as $x ) {
            $support = $perCore[ $core[ $x ] ];
            foreach ( $cutCore as $c => $n ) {
                $c = (string) $c;
                if ( $c !== $core[ $x ] && str_starts_with( $core[ $x ], $c ) ) {
                    $support += $n;
                }
            }
            $isCode = strlen( (string) preg_replace( '/[^a-z]/', '', Py::lower( $x ) ) ) <= 5 && ! str_contains( Py::strip( $x ), ' ' );
            // split() ignores the trailing space rstrip() removed from the printed form.
            $key = [ (int) ! $isCode, (int) ( count( Py::split( $printed[ $x ] ) ) > 1 ), $support, $v[ $x ], -Py::len( $x ) ];
            // max() keeps the first of equal keys.
            if ( null === $bestKey || self::compareKeys( $key, $bestKey ) > 0 ) {
                $best    = $x;
                $bestKey = $key;
            }
        }
        return (string) $best;
    }

    /**
     * Python tuple ordering for keys of equal length.
     *
     * @param list<int> $x
     * @param list<int> $y
     */
    private static function compareKeys( array $x, array $y ): int {
        foreach ( $x as $i => $value ) {
            if ( $value !== $y[ $i ] ) {
                return $value <=> $y[ $i ];
            }
        }
        return 0;
    }

    /** base() without a plural s, as the display rules compare forms. */
    private static function core( string $x ): string {
        $b = self::base( $x );
        return str_ends_with( $b, 's' ) && ! str_ends_with( $b, 'ss' ) ? substr( $b, 0, -1 ) : $b;
    }

    /** Drop trailing club-type words, including ones cut off mid-word ("Track Fi", "TF Clu", "At"). */
    private static function stripClubWords( string $t ): string {
        $changed = true;
        while ( $changed ) {
            $changed = false;
            $words   = explode( ' ', $t );
            if ( count( $words ) < 2 ) {
                break;
            }
            $last = Py::strip( $words[ count( $words ) - 1 ], '.' );
            // whole club phrases
            foreach ( self::CLUB_WORDS as $w ) {
                if ( str_ends_with( $t, ' ' . $w ) && Py::len( $t ) - strlen( $w ) - 1 >= 2 ) {   // keep at least a 2-character name ("3M")
                    $t       = Py::strip( substr( $t, 0, -strlen( $w ) - 1 ) );
                    $changed = true;
                    break;
                }
            }
            if ( $changed ) {
                continue;
            }
            // a single-letter cut after a club word: "Track C", "TF C", "Track & F"
            if ( 1 === Py::len( $last ) && count( $words ) > 2 && in_array( Py::strip( $words[ count( $words ) - 2 ], '.' ), self::CUT_AFTER, true ) ) {
                $t       = Py::strip( implode( ' ', array_slice( $words, 0, -1 ) ) );
                $changed = true;
                continue;
            }
            // a cut-off fragment of a club word (2+ letters), e.g. "clu", "fi", "athle", "assoc"
            if ( Py::len( $last ) >= 2 ) {
                $fragment = str_starts_with( $last, 'ath' ) && count( $words ) > 2;   // misspelled "Athele"
                foreach ( self::CUT_WORDS as $cw ) {
                    $fragment = $fragment || str_starts_with( $cw, $last );
                }
                if ( $fragment ) {
                    $t       = Py::strip( implode( ' ', array_slice( $words, 0, -1 ) ) );
                    $changed = true;
                }
            }
        }
        return $t;
    }

    private function root( string $b ): string {
        $seen = [];
        while ( isset( $this->merged[ $b ] ) && ! isset( $seen[ $b ] ) ) {
            $seen[ $b ] = true;
            $b          = $this->merged[ $b ];
        }
        return $b;
    }

    /**
     * Distinct roots in first-seen order.
     *
     * @param list<string> $cores
     * @return list<string>
     */
    private function roots( array $cores ): array {
        $out = [];
        foreach ( $cores as $c ) {
            $out[ $this->root( $c ) ] = true;
        }
        return Py::keys( $out );
    }

    /**
     * Entries per root.
     *
     * @param array<string,array<array-key,int>> $bases
     * @return array<string,int>
     */
    private function weights( array $bases ): array {
        $w = [];
        foreach ( $bases as $b => $names ) {
            $r       = $this->root( (string) $b );
            $w[ $r ] = ( $w[ $r ] ?? 0 ) + array_sum( $names );
        }
        return $w;
    }

    /**
     * Python sorted(items, key=key, reverse=True): stable, so equal keys keep their order.
     *
     * @param list<string>          $items
     * @param callable(string):int  $key
     * @return list<string>
     */
    private function sortDesc( array $items, callable $key ): array {
        $keys = [];
        foreach ( $items as $item ) {
            $keys[ $item ] = $key( $item );
        }
        usort( $items, static fn( string $x, string $y ): int => $keys[ $y ] <=> $keys[ $x ] );
        return $items;
    }

    /**
     * Counter.most_common(): by count, descending, equal counts in insertion order.
     *
     * @param array<array-key,int> $v
     * @return array<array-key,int>
     */
    private static function mostCommon( array $v ): array {
        $names = Py::keys( $v );
        usort( $names, static fn( string $x, string $y ): int => $v[ $y ] <=> $v[ $x ] );
        $out = [];
        foreach ( $names as $name ) {
            $out[ $name ] = $v[ $name ];
        }
        return $out;
    }
}
