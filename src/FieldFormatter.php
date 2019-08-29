<?php

namespace LocalDynamics\Revisionable;

use DateTime;

class FieldFormatter
{
    public static function format(string $key, $value, array $formats) : string
    {
        foreach ($formats as $pkey => $format) {
            $parts = explode(':', $format);
            if (sizeof($parts) === 1) {
                continue;
            }

            if ($pkey == $key) {
                $method = array_shift($parts);

                if (method_exists(get_class(), $method)) {
                    return self::$method($value, implode(':', $parts));
                }
                break;
            }
        }

        return $value;
    }

    public static function isEmpty($value, array $options = []) : string
    {
        $value_set = isset($value) && $value != '';

        return sprintf(self::boolean($value_set, $options), $value);
    }

    public static function boolean($value, ?array $options = null) : string
    {
        if (! is_null($options)) {
            $options = explode('|', $options);
        }

        if (sizeof($options) != 2) {
            $options = ['No', 'Yes'];
        }

        return $options[! ! $value];
    }

    public static function string($value, $format = null) : string
    {
        if (is_null($format)) {
            $format = '%s';
        }

        return sprintf($format, $value);
    }

    public static function datetime($value, string $format = 'Y-m-d H:i:s') : ?string
    {
        if (empty($value)) {
            return null;
        }

        $datetime = new DateTime($value);

        return $datetime->format($format);
    }
}
