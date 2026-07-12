<?php
/**
 * Jsonable Interface
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
 * Contract for objects that can be converted to JSON
 */
interface Jsonable extends \JsonSerializable
{
    /**
     * Convert the object to JSON string
     *
     * @param int $options JSON encoding options
     * @return string JSON representation
     */
    public function toJson(int $options = 0): string;
}
