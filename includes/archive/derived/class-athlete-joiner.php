<?php
/**
 * Join printed athlete names that are evidently the same person, for browsing only.
 *
 * Every result keeps its printed name. Two kinds of join:
 * 1. Format: the same name printed differently ("Kiernan, Russ", "RUSS KIERNAN", "Russ J. Kiernan").
 *    Hy-Tek prints "Last, First"; most road results print "First Last".
 * 2. Evidence: a short or nickname first name ("Mike" / "Michael", "R. Kiernan") with the same last
 *    name joins a fuller form only when both appear for the same club and exactly one fuller form fits.
 * Manual decisions override both (merges: [[form, form]], separate: [form]).
 * Different people who share a printed name cannot be told apart here.
 *
 * Port of the static preview's scripts/athlete_ids.py.
 *
 * @package PAUSATF\Results\Archive\Derived
 */

declare(strict_types=1);

namespace PAUSATF\Results\Archive\Derived;

require_once __DIR__ . '/class-python-text.php';
require_once __DIR__ . '/class-slug.php';

use Normalizer;
use PAUSATF\Results\Archive\Derived\PythonText as Py;
use RuntimeException;

/**
 * @phpstan-type Evidence array{from:list<string>,to:list<string>,clubs:list<string>}
 */
final class AthleteJoiner {
    private const NICK = [
        'al' => [ 'albert', 'alan', 'allen', 'alfred' ], 'alex' => [ 'alexander', 'alexandra', 'alexis' ], 'andy' => [ 'andrew' ], 'barb' => [ 'barbara' ],
        'ben' => [ 'benjamin' ], 'bill' => [ 'william' ], 'billy' => [ 'william' ], 'bob' => [ 'robert' ], 'bobby' => [ 'robert' ], 'brad' => [ 'bradley' ],
        'cathy' => [ 'catherine', 'cathleen' ], 'kathy' => [ 'katherine', 'kathleen' ], 'kate' => [ 'katherine', 'kathleen' ], 'katie' => [ 'katherine', 'kathleen' ],
        'chris' => [ 'christopher', 'christine', 'christina' ], 'chuck' => [ 'charles' ], 'charlie' => [ 'charles' ], 'dan' => [ 'daniel' ], 'danny' => [ 'daniel' ],
        'dave' => [ 'david' ], 'deb' => [ 'deborah', 'debra' ], 'debbie' => [ 'deborah', 'debra' ], 'dick' => [ 'richard' ], 'don' => [ 'donald' ], 'doug' => [ 'douglas' ],
        'ed' => [ 'edward', 'edwin' ], 'eddie' => [ 'edward' ], 'fred' => [ 'frederick' ], 'greg' => [ 'gregory' ], 'jim' => [ 'james' ], 'jimmy' => [ 'james' ],
        'jamie' => [ 'james' ], 'jeff' => [ 'jeffrey', 'jeffery' ], 'jen' => [ 'jennifer' ], 'jenny' => [ 'jennifer' ], 'jerry' => [ 'gerald', 'jerome' ],
        'joe' => [ 'joseph' ], 'joey' => [ 'joseph' ], 'jon' => [ 'jonathan' ], 'jack' => [ 'john' ], 'johnny' => [ 'john' ], 'josh' => [ 'joshua' ], 'ken' => [ 'kenneth' ],
        'kenny' => [ 'kenneth' ], 'larry' => [ 'lawrence', 'laurence' ], 'liz' => [ 'elizabeth' ], 'beth' => [ 'elizabeth' ], 'betsy' => [ 'elizabeth' ],
        'matt' => [ 'matthew' ], 'mike' => [ 'michael' ], 'mick' => [ 'michael' ], 'nick' => [ 'nicholas' ], 'pat' => [ 'patrick', 'patricia' ], 'patty' => [ 'patricia' ],
        'peggy' => [ 'margaret' ], 'maggie' => [ 'margaret' ], 'pete' => [ 'peter' ], 'phil' => [ 'phillip', 'philip' ], 'ray' => [ 'raymond' ], 'rich' => [ 'richard' ],
        'rick' => [ 'richard', 'frederick' ], 'rob' => [ 'robert' ], 'ron' => [ 'ronald' ], 'russ' => [ 'russell' ], 'sam' => [ 'samuel', 'samantha' ],
        'sandy' => [ 'sandra' ], 'steve' => [ 'steven', 'stephen' ], 'sue' => [ 'susan', 'suzanne' ], 'susie' => [ 'susan' ], 'ted' => [ 'theodore', 'edward' ],
        'terry' => [ 'terrence', 'teresa', 'theresa' ], 'tim' => [ 'timothy' ], 'tom' => [ 'thomas' ], 'tommy' => [ 'thomas' ], 'tony' => [ 'anthony' ],
        'vicky' => [ 'victoria' ], 'vince' => [ 'vincent' ], 'walt' => [ 'walter' ], 'will' => [ 'william' ], 'zach' => [ 'zachary' ], 'zack' => [ 'zachary' ],
    ];
    private const SUFFIX = [ 'jr', 'sr', 'ii', 'iii', 'iv' ];

    /**
     * (first, last) of a printed name in lower case without accents, punctuation, middle initials or suffixes.
     *
     * @return array{0:string,1:string}|null
     */
    public static function parts( ?string $name ): ?array {
        $n = Normalizer::normalize( $name ?? '', Normalizer::FORM_KD );
        if ( false === $n ) {
            throw new RuntimeException( 'Athlete name is not valid UTF-8.' );
        }
        // encode('ascii', 'ignore'): NFKD leaves accents as separate marks, which drop out here.
        $n = strtolower( (string) preg_replace( '/[^\x00-\x7f]+/', '', $n ) );
        $n = (string) preg_replace( '/\(.*?\)|\[.*?\]/', ' ', $n );
        if ( str_contains( $n, ',' ) ) {
            [ $last, $first ] = explode( ',', $n, 2 );
            $n                = "{$first} {$last}";
        }
        $n     = str_replace( '-', ' - ', str_replace( "'", '', (string) preg_replace( "/[^a-z' -]/", ' ', $n ) ) );
        $words = [];
        foreach ( preg_split( '/ +/', $n, -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $w ) {
            if ( ! in_array( $w, self::SUFFIX, true ) ) {
                $words[] = $w;
            }
        }
        // Re-join hyphenated names ("smith - jones" -> "smith-jones").
        $joined = [];
        foreach ( $words as $w ) {
            $last = count( $joined ) - 1;
            if ( $last >= 0 && ( '-' === $w || str_ends_with( $joined[ $last ], '-' ) ) ) {
                $joined[ $last ] .= $w;
            } else {
                $joined[] = $w;
            }
        }
        $words = [];
        foreach ( $joined as $w ) {
            $w = trim( $w, '-' );
            if ( '' !== $w ) {
                $words[] = $w;
            }
        }
        if ( count( $words ) < 2 ) {
            return null;
        }
        return [ $words[0], $words[ count( $words ) - 1 ] ];
    }

    public static function canon( ?string $name ): ?string {
        $p = self::parts( $name );
        return null === $p ? null : "{$p[0]} {$p[1]}";
    }

    /**
     * @param iterable<array{0:string,1:?string,2:?string,3:string}> $entries  Printed name, printed club, sex or null, race id.
     * @param array<array-key,string>                                $teamOf   Printed club => club group key.
     * @param list<array{0:string,1:string}>                         $manual   Forms joined by decision.
     * @param list<string>                                           $separate Forms never joined by rule.
     * @param (callable(string):string)|null                         $slug
     * @return array{0:array<array-key,string>,1:list<Evidence>} Printed name => athlete key, and the evidence joins.
     */
    public static function build( iterable $entries, array $teamOf, array $manual = [], array $separate = [], ?callable $slug = null ): array {
        $slug   = $slug ?? Slug::of( ... );
        $forms  = [];   // canonical "first last" => printed form => count
        $clubs  = [];   // canonical => club group keys
        $sexes  = [];   // canonical => sexes recorded for it
        $races  = [];   // canonical => races it appears in
        $plain  = [];
        $canons = [];
        foreach ( $entries as [ $name, $club, $sex, $race ] ) {
            $c = $canons[ $name ] ??= self::canon( $name ) ?? false;
            if ( false === $c ) {
                $plain[ $name ] = $slug( $name );
                continue;
            }
            $forms[ $c ][ $name ]   = ( $forms[ $c ][ $name ] ?? 0 ) + 1;
            $races[ $c ][ $race ]   = true;
            $sexes[ $c ]          ??= [];
            $clubs[ $c ]          ??= [];
            if ( null !== $sex && '' !== $sex ) {
                $sexes[ $c ][ $sex ] = true;
            }
            if ( null !== $club && '' !== $club && '' !== ( $teamOf[ $club ] ?? '' ) ) {
                $clubs[ $c ][ $teamOf[ $club ] ] = true;
            }
        }
        // Two forms are different people when they ran in the same race, or were recorded with different sexes.
        $distinct = static function ( string $a, string $b ) use ( $races, $sexes ): bool {
            if ( array_intersect_key( $races[ $a ], $races[ $b ] ) ) {
                return true;
            }
            return $sexes[ $a ] && $sexes[ $b ] && ( array_diff_key( $sexes[ $a ], $sexes[ $b ] ) || array_diff_key( $sexes[ $b ], $sexes[ $a ] ) );
        };
        $keep = [];
        foreach ( $separate as $x ) {
            $k = self::canon( $x );
            if ( null !== $k ) {
                $keep[ $k ] = true;
            }
        }
        /** @var array<string,string> $parent */
        $parent = [];
        $root   = static function ( string $c ) use ( &$parent ): string {
            while ( isset( $parent[ $c ] ) ) {
                $c = $parent[ $c ];
            }
            return $c;
        };

        $byLast = [];
        foreach ( $forms as $c => $_ ) {
            $byLast[ 'k' . explode( ' ', (string) $c, 2 )[1] ][] = (string) $c;
        }
        $evidence = [];
        foreach ( $byLast as $cs ) {
            foreach ( $cs as $c ) {
                if ( isset( $keep[ $c ] ) ) {
                    continue;
                }
                $first   = explode( ' ', $c, 2 )[0];
                $targets = [];
                foreach ( $cs as $d ) {
                    if ( $d !== $c && ! isset( $keep[ $d ] ) && self::fits( $first, explode( ' ', $d, 2 )[0] )
                        && array_intersect_key( $clubs[ $c ], $clubs[ $d ] ) && ! $distinct( $c, $d ) ) {
                        $targets[] = $d;
                    }
                }
                // Exactly one fuller form, after merging fuller forms already joined to each other.
                $roots = [];
                foreach ( $targets as $d ) {
                    $roots[ $root( $d ) ] = true;
                }
                if ( 1 === count( $roots ) && $root( $c ) !== (string) array_key_first( $roots ) ) {
                    $t     = null;
                    $tSize = -1;
                    foreach ( $targets as $d ) {
                        $size = array_sum( $forms[ $d ] );
                        if ( $size > $tSize ) {   // max() keeps the first of equal counts
                            $t     = $d;
                            $tSize = $size;
                        }
                    }
                    $t                       = (string) $t;
                    $parent[ $root( $c ) ]   = $root( $t );
                    $evidence[]              = [
                        'from'  => Py::sorted( Py::keys( $forms[ $c ] ) ),
                        'to'    => Py::sorted( Py::keys( $forms[ $t ] ) ),
                        'clubs' => Py::sorted( Py::keys( array_intersect_key( $clubs[ $c ], $clubs[ $t ] ) ) ),
                    ];
                }
            }
        }
        foreach ( $manual as [ $a, $b ] ) {
            $ca = self::canon( $a );
            $cb = self::canon( $b );
            if ( null !== $ca && null !== $cb && isset( $forms[ $ca ], $forms[ $cb ] ) && $root( $ca ) !== $root( $cb ) ) {
                $parent[ $root( $ca ) ] = $root( $cb );
            }
        }
        $groups = [];
        foreach ( $forms as $c => $f ) {
            $r = $root( (string) $c );
            foreach ( $f as $name => $count ) {
                $groups[ $r ][ $name ] = ( $groups[ $r ][ $name ] ?? 0 ) + $count;
            }
        }
        $out = $plain;
        foreach ( $groups as $f ) {
            // Key from the most common "First Last" form, so links stay readable and stable.
            $display = null;
            $best    = null;
            foreach ( $f as $name => $count ) {
                $name = (string) $name;
                $key  = [ (int) ( ! str_contains( $name, ',' ) && ! Py::isUpper( $name ) ), $count ];
                if ( null === $best || $key[0] > $best[0] || ( $key[0] === $best[0] && $key[1] > $best[1] ) ) {
                    $display = $name;
                    $best    = $key;
                }
            }
            $display = (string) $display;
            $k       = $slug( self::canon( $display ) ?? $display );
            foreach ( $f as $name => $count ) {
                $out[ $name ] = $k;
            }
        }
        return [ $out, $evidence ];
    }

    /** A short first name that can stand for a fuller one: initial, nickname, or a prefix of 3+ letters. */
    private static function fits( string $short, string $full ): bool {
        if ( $short === $full ) {
            return false;
        }
        if ( 1 === strlen( $short ) ) {
            return str_starts_with( $full, $short );
        }
        return in_array( $full, self::NICK[ $short ] ?? [], true ) || ( strlen( $short ) >= 3 && str_starts_with( $full, $short ) && strlen( $full ) > strlen( $short ) );
    }
}
