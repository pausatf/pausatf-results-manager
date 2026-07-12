<?php
/**
 * Arrayable Interface
 *
 * @package PAUSATF\Results\Contracts
 * @since 3.0.0
 */

declare(strict_types=1);

namespace PAUSATF\Results\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract for objects that can be converted to arrays
 */
interface Arrayable
{
    /**
     * Convert the object to an array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
