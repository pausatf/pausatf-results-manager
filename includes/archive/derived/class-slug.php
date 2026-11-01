<?php
/**
 * URL keys for derived pages, as the static preview's build_site.slug() makes them.
 *
 * @package PAUSATF\Results\Archive\Derived
 */

declare(strict_types=1);

namespace PAUSATF\Results\Archive\Derived;

final class Slug {
    public static function of( string $text ): string {
        // After the substitution only ASCII remains, so byte slicing equals Python's s[:80].
        $s = (string) preg_replace( '/[^a-z0-9]+/', '-', mb_strtolower( $text, 'UTF-8' ) );
        $s = substr( trim( $s, '-' ), 0, 80 );
        return '' === $s ? 'x' : $s;
    }
}
