<?php

namespace Autoklose\DataShipper\Traits;

trait HasDataSubscribers {
    public static function mapData($changes, $subscriber)
    {
        $mapped = [];

        foreach ($changes as $key => $value) {
            $value = self::transformData($value, $key, $subscriber);
            $map = "{$subscriber}Map";
            $class = get_called_class();
            if (property_exists($class, $map) && in_array($key, $class::$$map)) {
                $customKey = $class::$$map[$key];
                $mapped[$customKey] = $value;
            } else {
                $mapped[$key] = $value;
            }
        }

        return $mapped;
    }

    public static function transformData($value, $key, $subscriber)
    {
        return $value;
    }
}
