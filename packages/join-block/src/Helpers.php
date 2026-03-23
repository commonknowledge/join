<?php

namespace CommonKnowledge\JoinBlock;

if (! defined('ABSPATH')) {
    // Exit if accessed directly
    exit;
}

class Helpers
{
    /**
     * Remove null and empty-string values from an array.
     *
     * @param array $arr
     * @return array
     */
    public static function removeNullOrEmpty($arr)
    {
        return array_filter($arr, function ($v) {
            return $v !== null && $v !== '';
        });
    }
}
