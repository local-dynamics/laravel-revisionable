<?php


namespace LocalDynamics\Revisionable;


class FieldModifier
{

    public static function sortJsonKeys($attribute)
    {
        if (empty($attribute)) {
            return $attribute;
        }
        foreach ($attribute as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = static::sortJsonKeys($value);
            } else {
                continue;
            }
            ksort($value);
            $attribute[$key] = $value;
        }

        return $attribute;
    }

    public static function convertValue($value)
    {
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
