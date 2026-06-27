<?php

namespace LocalDynamics\Revisionable;

use UnitEnum;

class FieldModifier
{
    public static function sortJsonKeys($attribute)
    {
        if (! is_array($attribute) && ! is_object($attribute)) {
            return $attribute;
        }

        $attribute = (array) $attribute;

        foreach ($attribute as $key => $value) {
            $attribute[$key] = static::sortJsonKeys($value);
        }

        ksort($attribute);

        return $attribute;
    }

    /**
     * Normalise a JSON value into a canonical string with recursively sorted
     * object keys, so that two payloads which differ only in key order (e.g.
     * because the database re-ordered them on write) compare as equal.
     */
    public static function canonicalJson($value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return json_encode(static::sortJsonKeys($value));
    }

    public static function convertValue($value)
    {
        if (is_null($value)) {
            return null;
        }

        if ($value instanceof UnitEnum) {
            $value = $value->value;
        }

        $jsonData = json_decode($value);

        if (is_array($jsonData) || is_object($jsonData)) {
            return json_encode((array) $jsonData);
        }

        if (is_array($value) || is_object($value)) {
            return json_encode((array) $value);
        }

        return $value;
    }
}
