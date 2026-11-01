<?php
/**
 * Python difflib.SequenceMatcher(None, a, b) ratio, element for element.
 *
 * Club grouping merges spellings whose ratio passes 0.85/0.9/0.93, so the port keeps
 * difflib's exact matching-block search, including the default autojunk rule (elements that
 * fill more than 1% of a second sequence of 200+ elements cannot start a match), rather than
 * an edit-distance approximation such as similar_text().
 *
 * @package PAUSATF\Results\Archive\Derived
 */

declare(strict_types=1);

namespace PAUSATF\Results\Archive\Derived;

final class SequenceMatcher {
    /** @var list<string> */
    private array $a;
    /** @var list<string> */
    private array $b;
    /** @var array<string,list<int>> */
    private array $b2j = [];

    /**
     * @param string|list<string> $a Strings compare by code point.
     * @param string|list<string> $b
     */
    public function __construct( string|array $a, string|array $b, bool $autojunk = true ) {
        $this->a = is_string( $a ) ? mb_str_split( $a, 1, 'UTF-8' ) : $a;
        $this->b = is_string( $b ) ? mb_str_split( $b, 1, 'UTF-8' ) : $b;
        foreach ( $this->b as $j => $elt ) {
            $this->b2j[ 'k' . $elt ][] = $j;
        }
        $n = count( $this->b );
        if ( $autojunk && $n >= 200 ) {
            $ntest = intdiv( $n, 100 ) + 1;
            foreach ( $this->b2j as $elt => $idxs ) {
                if ( count( $idxs ) > $ntest ) {
                    unset( $this->b2j[ $elt ] );
                }
            }
        }
    }

    public static function ratioOf( string $a, string $b ): float {
        return ( new self( $a, $b ) )->ratio();
    }

    public function ratio(): float {
        $matches = 0;
        foreach ( $this->matchingBlocks() as [ , , $size ] ) {
            $matches += $size;
        }
        $length = count( $this->a ) + count( $this->b );
        return $length > 0 ? 2.0 * $matches / $length : 1.0;
    }

    /**
     * Triples (i, j, n) with a[i:i+n] == b[j:j+n]; adjacent blocks are not collapsed since
     * only the sizes are used.
     *
     * @return list<array{int,int,int}>
     */
    public function matchingBlocks(): array {
        $queue  = [ [ 0, count( $this->a ), 0, count( $this->b ) ] ];
        $blocks = [];
        while ( $queue ) {
            [ $alo, $ahi, $blo, $bhi ] = array_pop( $queue );
            [ $i, $j, $k ]             = $this->findLongestMatch( $alo, $ahi, $blo, $bhi );
            if ( $k > 0 ) {
                $blocks[] = [ $i, $j, $k ];
                if ( $alo < $i && $blo < $j ) {
                    $queue[] = [ $alo, $i, $blo, $j ];
                }
                if ( $i + $k < $ahi && $j + $k < $bhi ) {
                    $queue[] = [ $i + $k, $ahi, $j + $k, $bhi ];
                }
            }
        }
        sort( $blocks );
        return $blocks;
    }

    /** @return array{int,int,int} */
    public function findLongestMatch( int $alo, int $ahi, int $blo, int $bhi ): array {
        $a        = $this->a;
        $b        = $this->b;
        $besti    = $alo;
        $bestj    = $blo;
        $bestsize = 0;
        $j2len    = [];
        for ( $i = $alo; $i < $ahi; $i++ ) {
            $newj2len = [];
            foreach ( $this->b2j[ 'k' . $a[ $i ] ] ?? [] as $j ) {
                if ( $j < $blo ) {
                    continue;
                }
                if ( $j >= $bhi ) {
                    break;
                }
                $k              = ( $j2len[ $j - 1 ] ?? 0 ) + 1;
                $newj2len[ $j ] = $k;
                if ( $k > $bestsize ) {
                    $besti    = $i - $k + 1;
                    $bestj    = $j - $k + 1;
                    $bestsize = $k;
                }
            }
            $j2len = $newj2len;
        }
        // With no junk function the junk set is empty, so only difflib's first two extension
        // loops can apply; they let a match grow over popular (autojunked) elements.
        while ( $besti > $alo && $bestj > $blo && $a[ $besti - 1 ] === $b[ $bestj - 1 ] ) {
            --$besti;
            --$bestj;
            ++$bestsize;
        }
        while ( $besti + $bestsize < $ahi && $bestj + $bestsize < $bhi && $a[ $besti + $bestsize ] === $b[ $bestj + $bestsize ] ) {
            ++$bestsize;
        }
        return [ $besti, $bestj, $bestsize ];
    }
}
