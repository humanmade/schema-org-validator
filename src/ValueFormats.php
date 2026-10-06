<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator;

/**
 * Format checks for scalar values of schema.org data types.
 */
final class ValueFormats
{
    private const OFFSET = '(?:Z|[+-]\d{2}(?::?\d{2})?)?';

    /**
     * True when the value is acceptable for the data type. Data types without a format check accept anything.
     *
     * @param bool|float|int|string $value
     */
    public static function accepts(string $dataType, $value): bool
    {
        switch ($dataType) {
            case 'URL':
                return is_string($value) && self::isUrl($value);
            case 'Date':
                return is_string($value) && self::isDate($value);
            case 'DateTime':
                return is_string($value) && self::isDateTime($value);
            case 'Time':
                return is_string($value) && self::isTime($value);
            case 'Number':
            case 'Float':
                return self::isNumber($value);
            case 'Integer':
                return self::isInteger($value);
            case 'Boolean':
                return self::isBoolean($value);
            default:
                return true;
        }
    }

    public static function isUrl(string $value): bool
    {
        if (preg_match('~^([a-z][a-z0-9+.\-]*):\S+$~i', $value, $matches) !== 1) {
            return false;
        }
        if (in_array(strtolower($matches[1]), ['http', 'https'], true)) {
            $host = parse_url($value, PHP_URL_HOST);

            return is_string($host) && $host !== '';
        }

        return true;
    }

    /**
     * ISO 8601 calendar date: YYYY, YYYY-MM or YYYY-MM-DD.
     */
    public static function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/', $value, $m) !== 1) {
            return false;
        }
        $month = isset($m[2]) ? (int) $m[2] : 1;
        $day = isset($m[3]) ? (int) $m[3] : 1;

        return checkdate($month, $day, (int) $m[1]);
    }

    /**
     * ISO 8601 date and time: YYYY-MM-DDThh:mm[:ss[.fff]] with an optional offset.
     */
    public static function isDateTime(string $value): bool
    {
        $pattern = '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)' . self::OFFSET . '$/';
        if (preg_match($pattern, $value, $m) !== 1) {
            return false;
        }

        return self::isDate($m[1]) && self::isClock($m[2]);
    }

    /**
     * ISO 8601 time: hh:mm[:ss[.fff]] with an optional offset.
     */
    public static function isTime(string $value): bool
    {
        if (preg_match('/^(\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)' . self::OFFSET . '$/', $value, $m) !== 1) {
            return false;
        }

        return self::isClock($m[1]);
    }

    /**
     * @param mixed $value
     */
    public static function isNumber($value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }

        return is_string($value) && preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/', $value) === 1;
    }

    /**
     * @param mixed $value
     */
    public static function isInteger($value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value) && floor($value) === $value;
        }

        return is_string($value) && preg_match('/^[+-]?\d+$/', $value) === 1;
    }

    /**
     * @param mixed $value
     */
    public static function isBoolean($value): bool
    {
        if (is_bool($value)) {
            return true;
        }
        if (!is_string($value)) {
            return false;
        }

        $name = Terms::localName($value);

        return $name !== null && in_array(strtolower($name), ['true', 'false'], true);
    }

    private static function isClock(string $clock): bool
    {
        $parts = explode(':', $clock);
        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];
        $seconds = isset($parts[2]) ? (float) $parts[2] : 0.0;

        if ($hours > 24 || $minutes > 59 || $seconds >= 61) {
            return false;
        }

        return $hours < 24 || ($minutes === 0 && $seconds === 0.0);
    }
}
