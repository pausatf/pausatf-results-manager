<?php
/**
 * Comparable event names and numeric mark values, for personal bests, progressions and lists.
 *
 * The printed mark is always what is shown; the value here only orders marks within one event.
 * Port of the static preview's scripts/events.py; tests/parity holds the parity harness.
 *
 * @package PAUSATF\Results\Archive\Derived
 */

declare(strict_types=1);

namespace PAUSATF\Results\Archive\Derived;

require_once __DIR__ . '/class-python-text.php';

use PAUSATF\Results\Archive\Derived\PythonText as Py;

final class EventNormalizer {
    private const FIELD     = 'jump|vault|put|throw|discus|javelin|hammer|weight|softball|turbo|athlon';
    private const IMPLEMENT = 'hurdle|put|discus|javelin|hammer|weight|turbo|softball|steeple';
    private const DIVWORDS  = "\\b(sub-bantam|bantam|midget|youth|intermediate|young|junior|open|masters?|girls?|boys?|women'?s?|men'?s?|"
        . 'mixed|female|male|\\d+\\s*&\\s*(under|over)|\\d+\\s*-\\s*\\d+|\\d+\\+)\\b';
    private const ROAD      = [
        [ '\\bhalf\\s*marathon\\b|\\b13\\.1\\s*mi|\\b1/2\\s*marathon\\b', 'Half Marathon' ],
        [ '\\bmarathon\\b', 'Marathon' ],
        [ '\\b50[- ]?mile', '50 Mile' ],
        [ '\\b50\\s?k\\b', '50K' ],
        [ '\\b30\\s?k\\b', '30K' ],
        [ '\\b25\\s?k\\b', '25K' ],
        [ '\\b20\\s?k\\b', '20K' ],
        [ '\\b15\\s?k\\b', '15K' ],
        [ '\\b12\\s?k\\b', '12K' ],
        [ '\\b10[- ]?mile', '10 Mile' ],
        [ '\\b10\\s?k\\b', '10K' ],
        [ '\\b8\\s?k\\b', '8K' ],
        [ '\\b5[- ]?mile', '5 Mile' ],
        [ '\\b5\\s?k\\b', '5K' ],
        [ '\\bmile\\b', 'Mile' ],
    ];
    /** Race distance in km by event; ints where the Python has ints, so v / km divides alike. */
    private const KM = [
        'Marathon' => 42.195, 'Half Marathon' => 21.0975, '50 Mile' => 80.467, '50K' => 50, '30K' => 30, '25K' => 25, '20K' => 20, '15K' => 15,
        '12K' => 12, '10 Mile' => 16.093, '10K' => 10, '8K' => 8, '5 Mile' => 8.047, '5K' => 5, 'Mile' => 1.609,
    ];

    public static function roadDistance( ?string $text ): ?string {
        foreach ( self::ROAD as [ $pattern, $distance ] ) {
            if ( null !== Py::search( $pattern, $text ?? '', 'i' ) ) {
                return $distance;
            }
        }
        return null;
    }

    /** '1500 Meter Run Midget Girls' -> '1500m'; youth hurdles and throws keep their age group (implements differ). */
    public static function trackEvent( string $label, bool $youth ): ?string {
        $group = Py::search( '\\b(sub-bantam|bantam|midget|youth|intermediate|young (?:wo)?men)\\b', $label, 'i' );
        // Heat, section and session labels are not part of the event ("1 Mile Run -A Section", "Evening Session").
        $label = Py::sub( '\\s*-?\\s*\\b([a-z]\\s+section|section\\s+[a-z0-9]+|(morning|afternoon|evening)\\s+session|heat\\s+\\d+|flight\\s+\\d+)\\b', ' ', $label, 'i' );
        $level = Py::search( '\\b(freshman|frosh/soph|frosh-soph|sophomore|junior varsity|varsity|jv|elementary|elementry|middle school)\\b', $label, 'i' );
        $label = Py::sub( '\\s*-\\s*(?=(freshman|frosh|sophomore|junior|jv|elementary|elementry|middle))', ' ', $label, 'i' );
        if ( null !== $level ) {
            $label = str_replace( (string) $level[0], ' ', $label );
        }
        $e = Py::sub( self::DIVWORDS, ' ', $label, 'i' );
        $e = Py::sub( '(\\d+)\\s*(?:meters?|metres?|m)\\b(\\s*(run|dash)\\b)?', '${1}m ', $e, 'i' );
        $e = Py::sub( '\\b(\\d+)\\s*x\\s*(\\d+)m', '${1}x${2}m', $e, 'i' );
        $e = Py::sub( '\\b(\\d{2,3})\\s+(hurdles)\\b', '${1}m ${2}', $e, 'i' );
        $e = Py::strip( Py::sub( '\\s+', ' ', $e ), ' -–·' );
        $words = [];
        foreach ( Py::split( $e ) as $w ) {
            $head    = Py::first( $w );
            $words[] = Py::isDigit( $head ) ? $w : mb_strtoupper( $head, 'UTF-8' ) . Py::slice( $w, 1 );
        }
        $e = implode( ' ', $words );
        if ( $youth && null !== $group && null !== Py::search( self::IMPLEMENT, $e, 'i' ) ) {
            $e .= ' (' . Py::title( (string) $group[1] ) . ')';
        }
        if ( null !== $level ) {
            $e .= ' (' . Py::title( (string) $level[1] ) . ')';
        }
        return '' === $e ? null : $e;
    }

    public static function eventOf( string $sport, string $label, string $meetTitle ): ?string {
        if ( 'Track & Field' === $sport || 'Youth Track & Field' === $sport ) {
            return self::trackEvent( $label, str_starts_with( $sport, 'Youth' ) );
        }
        if ( 'Road' === $sport || 'Ultra' === $sport ) {
            return self::roadDistance( "{$label} {$meetTitle}" );
        }
        if ( 'Cross Country' === $sport || 'Youth Cross Country' === $sport ) {
            $m = Py::search( '(\\d+(?:\\.\\d+)?)\\s*(k|km|000m|mile|mi)\\b', "{$label} {$meetTitle}", 'i' );
            if ( null !== $m ) {
                $n    = (string) $m[1];
                $unit = Py::lower( (string) $m[2] );
                $d    = in_array( $unit, [ 'k', 'km', '000m' ], true ) ? "{$n}K" : "{$n} Mile";
                return "{$d} XC";
            }
        }
        return null;
    }

    /** Seconds for a timed mark; an int when the mark has no fraction, as in Python. */
    public static function secs( ?string $mark ): int|float|null {
        $m = Py::match( '^(\\d+(?::\\d+){0,2})(?:\\.(\\d+))?\\s*[a-z*#]*$', Py::strip( $mark ?? '' ), 'i' );
        if ( null === $m ) {
            return null;
        }
        $v = 0;
        foreach ( explode( ':', (string) $m[1] ) as $p ) {
            $v = $v * 60 + Py::toInt( $p );
        }
        return null !== $m[2] && '' !== $m[2] ? $v + Py::toFloat( '0.' . $m[2] ) : $v;
    }

    /** Field mark in metres: '5.23m', '5.23', "17-04.50", '17\' 4.5"'. */
    public static function distance( ?string $mark ): ?float {
        $t = Py::lower( Py::strip( $mark ?? '' ) );
        $m = Py::match( '^(\\d+)\\s*(?:-|\'|ft)\\s*(\\d+(?:\\.\\d+)?)?\\s*(?:"|in)?\\s*[a-z*#]*$', $t );
        if ( null !== $m && ( str_contains( $t, '-' ) || str_contains( $t, "'" ) || str_contains( $t, 'ft' ) ) ) {
            $inches = null !== $m[2] && '' !== $m[2] ? Py::toFloat( $m[2] ) : 0.0;
            return self::round3( Py::toInt( (string) $m[1] ) * 0.3048 + $inches * 0.0254 );
        }
        $m = Py::match( '^(\\d+(?:\\.\\d+)?)\\s*m?\\s*[a-z*#]*$', $t );
        return null !== $m ? Py::toFloat( (string) $m[1] ) : null;
    }

    /**
     * (numeric value, 'low' or 'high' is better), or (null, null) when the mark is not a
     * performance (DNF, NH, ...).
     *
     * @return array{0:int|float|null,1:'low'|'high'|null}
     */
    public static function value( ?string $event, ?string $mark ): array {
        if ( null === $event || '' === $event ) {
            return [ null, null ];
        }
        if ( null !== Py::search( self::FIELD, $event, 'i' ) ) {
            if ( null !== Py::search( 'athlon', $event, 'i' ) ) {
                $m = Py::match( '^\\d+$', Py::strip( $mark ?? '' ) );
                return null !== $m ? [ Py::toInt( (string) $m[0] ), 'high' ] : [ null, null ];
            }
            $v = self::distance( $mark );
            return null !== $v && 0.0 !== $v ? [ $v, 'high' ] : [ null, null ];
        }
        $v = self::secs( $mark );
        return null !== $v && 0 !== $v && 0.0 !== $v && self::plausible( $event, $v ) ? [ $v, 'low' ] : [ null, null ];
    }

    public static function kmOf( ?string $event ): int|float|null {
        if ( null !== $event && isset( self::KM[ $event ] ) ) {
            return self::KM[ $event ];
        }
        $m = Py::match( '^(\\d+(?:\\.\\d+)?)(K| Mile) XC$', $event ?? '' );
        return null !== $m ? Py::toFloat( (string) $m[1] ) * ( 'K' === $m[2] ? 1 : 1.609 ) : null;
    }

    /** A printed time that cannot be a running pace (e.g. "29:48:00" read as hours over 10K) is not ranked. */
    public static function plausible( ?string $event, int|float|null $v ): bool {
        $km = self::kmOf( $event );
        if ( null === $km || null === $v ) {
            return true;
        }
        $pace = $v / $km;
        return 140 <= $pace && $pace <= 1200;
    }

    /**
     * Python round(x, 3): the correctly rounded decimal, read back as a float. sprintf's %F
     * uses zend_dtoa in mode 3, which rounds the exact binary value as CPython does; round()
     * pre-rounds and can differ on values just below a half.
     */
    private static function round3( float $x ): float {
        return (float) sprintf( '%.3F', $x );
    }
}
