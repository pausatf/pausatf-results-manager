<?php
/**
 * Python str and re semantics the derived archive rules depend on.
 *
 * The grouping and event rules were first written in Python and their published output is
 * the reference, so the ports reproduce Python behaviour rather than PHP defaults:
 * - Python's \w is [\p{L}\p{N}_]; PCRE2 10.43+ also counts combining marks (Mn) and Pc in
 *   UCP mode, which moves \b inside accented names.
 * - Python's \s and str.split()/strip() also treat U+001C..U+001F as whitespace and not U+180E.
 * - len(), slicing and str.isdigit()/isupper() count code points, not bytes.
 * Both were checked over every code point; the only remaining differences are characters
 * assigned after Unicode 15 (Python 3.12's tables), which do not occur in the archive.
 *
 * @package PAUSATF\Results\Archive\Derived
 */

declare(strict_types=1);

namespace PAUSATF\Results\Archive\Derived;

use IntlChar;
use RuntimeException;

final class PythonText {
    private const WORD = '[\p{L}\p{N}_]';
    private const SPACE = '(?:[\x{1c}-\x{1f}]|(?!\x{180e})\s)';

    /** @var array<string,string> */
    private static array $compiled = [];

    /**
     * A PCRE pattern for a Python `re` pattern written with \b, \s and \w outside character
     * classes (the only places the ported rules use them).
     */
    public static function rx( string $python, string $flags = '' ): string {
        $key = $flags . '/' . $python;
        if ( ! isset( self::$compiled[ $key ] ) ) {
            $word     = self::WORD;
            $boundary = "(?:(?<={$word})(?!{$word})|(?<!{$word})(?={$word}))";
            $out      = '';
            $len      = strlen( $python );
            for ( $i = 0; $i < $len; $i++ ) {
                $ch = $python[ $i ];
                if ( '\\' === $ch && $i + 1 < $len ) {
                    $next = $python[ ++$i ];
                    $out .= match ( $next ) {
                        'b' => $boundary,
                        's' => self::SPACE,
                        'w' => $word,
                        default => '\\' . $next,
                    };
                    continue;
                }
                $out .= '/' === $ch ? '\\/' : $ch;
            }
            self::$compiled[ $key ] = '/' . $out . '/u' . $flags;
        }
        return self::$compiled[ $key ];
    }

    /**
     * First match groups, or null. Unmatched groups are null, as in Python.
     *
     * @return array<int|string,string|null>|null
     */
    public static function search( string $python, string $subject, string $flags = '' ): ?array {
        $m = [];
        $r = preg_match( self::rx( $python, $flags ), $subject, $m, PREG_UNMATCHED_AS_NULL );
        self::check( $r );
        return 1 === $r ? $m : null;
    }

    /**
     * Python re.match: anchored at the start only.
     *
     * @return array<int|string,string|null>|null
     */
    public static function match( string $python, string $subject, string $flags = '' ): ?array {
        return self::search( '\A(?:' . $python . ')', $subject, $flags );
    }

    public static function sub( string $python, string $replacement, string $subject, string $flags = '' ): string {
        $r = preg_replace( self::rx( $python, $flags ), $replacement, $subject );
        self::check( $r );
        return (string) $r;
    }

    /** @param callable(array<int|string,string|null>):string $replace */
    public static function subCallback( string $python, callable $replace, string $subject, string $flags = '' ): string {
        $r = preg_replace_callback( self::rx( $python, $flags ), $replace, $subject, -1, $count, PREG_UNMATCHED_AS_NULL );
        self::check( $r );
        return (string) $r;
    }

    /** str.lower() */
    public static function lower( string $s ): string {
        return mb_strtolower( $s, 'UTF-8' );
    }

    /** str.title() */
    public static function title( string $s ): string {
        $out   = '';
        $cased = false;
        foreach ( mb_str_split( $s, 1, 'UTF-8' ) as $ch ) {
            $out  .= $cased ? mb_strtolower( $ch, 'UTF-8' ) : mb_convert_case( $ch, MB_CASE_TITLE, 'UTF-8' );
            $cased = self::isCased( $ch );
        }
        return $out;
    }

    /** str.strip(chars); with no chars, Python whitespace. */
    public static function strip( string $s, ?string $chars = null ): string {
        $class = null === $chars ? self::SPACE : '[' . preg_quote( $chars, '/' ) . ']';
        $r = preg_replace( '/^' . $class . '+|' . $class . '+$/uD', '', $s );
        self::check( $r );
        return (string) $r;
    }

    public static function rstrip( string $s ): string {
        $r = preg_replace( '/' . self::SPACE . '+$/uD', '', $s );
        self::check( $r );
        return (string) $r;
    }

    /**
     * str.split() with no separator.
     *
     * @return list<string>
     */
    public static function split( string $s ): array {
        $parts = preg_split( '/' . self::SPACE . '+/u', $s, -1, PREG_SPLIT_NO_EMPTY );
        return false === $parts ? [] : $parts;
    }

    /** len() in code points. */
    public static function len( string $s ): int {
        return mb_strlen( $s, 'UTF-8' );
    }

    /** s[start:end] in code points (end null = to the end; negative counts from the end). */
    public static function slice( string $s, int $start, ?int $end = null ): string {
        $n = self::len( $s );
        $a = $start < 0 ? max( 0, $n + $start ) : min( $start, $n );
        $b = null === $end ? $n : ( $end < 0 ? max( 0, $n + $end ) : min( $end, $n ) );
        return $b > $a ? mb_substr( $s, $a, $b - $a, 'UTF-8' ) : '';
    }

    /** First code point, or '' (s[:1]). */
    public static function first( string $s ): string {
        return '' === $s ? '' : mb_substr( $s, 0, 1, 'UTF-8' );
    }

    /** str.isdigit(): every character has a digit value, and there is at least one. */
    public static function isDigit( string $s ): bool {
        if ( '' === $s ) {
            return false;
        }
        foreach ( mb_str_split( $s, 1, 'UTF-8' ) as $ch ) {
            $type = IntlChar::getIntPropertyValue( $ch, IntlChar::PROPERTY_NUMERIC_TYPE );
            if ( IntlChar::NT_DECIMAL !== $type && IntlChar::NT_DIGIT !== $type ) {
                return false;
            }
        }
        return true;
    }

    /** str.isupper(): no lowercase or titlecase character and at least one uppercase one. */
    public static function isUpper( string $s ): bool {
        $cased = false;
        foreach ( mb_str_split( $s, 1, 'UTF-8' ) as $ch ) {
            if ( IntlChar::isULowercase( $ch ) || IntlChar::istitle( $ch ) ) {
                return false;
            }
            $cased = $cased || IntlChar::isUUppercase( $ch );
        }
        return $cased;
    }

    /** int() of a digit string matched by \d, which may hold non-ASCII decimal digits. */
    public static function toInt( string $digits ): int {
        return (int) self::asciiDigits( $digits );
    }

    /** float() of a decimal string matched by \d and '.'. */
    public static function toFloat( string $digits ): float {
        return (float) self::asciiDigits( $digits );
    }

    /**
     * Python sorted() order for strings: code point order, which is UTF-8 byte order.
     *
     * @param array<array-key,string> $items
     * @return list<string>
     */
    public static function sorted( array $items ): array {
        $items = array_map( 'strval', array_values( $items ) );
        sort( $items, SORT_STRING );
        return $items;
    }

    /**
     * Keys of a map as strings; PHP turns numeric-string keys such as "38" into ints.
     *
     * @param array<array-key,mixed> $map
     * @return list<string>
     */
    public static function keys( array $map ): array {
        return array_map( 'strval', array_keys( $map ) );
    }

    private static function isCased( string $ch ): bool {
        return IntlChar::isULowercase( $ch ) || IntlChar::isUUppercase( $ch ) || IntlChar::istitle( $ch );
    }

    private static function asciiDigits( string $digits ): string {
        if ( 1 !== preg_match( '/[^\x00-\x7f]/', $digits ) ) {
            return $digits;
        }
        $out = '';
        foreach ( mb_str_split( $digits, 1, 'UTF-8' ) as $ch ) {
            $d    = IntlChar::charDigitValue( $ch );
            $out .= $d >= 0 && ! ctype_digit( $ch ) && '.' !== $ch ? (string) $d : $ch;
        }
        return $out;
    }

    private static function check( mixed $result ): void {
        if ( null === $result || false === $result ) {
            throw new RuntimeException( 'Regular expression failed: ' . preg_last_error_msg() );
        }
    }
}
