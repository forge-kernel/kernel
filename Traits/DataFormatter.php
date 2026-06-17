<?php

declare(strict_types=1);

namespace Forge\Traits;

trait DataFormatter
{
    protected function formatDebugData(mixed $data): mixed
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->formatDebugData($value);
            }
            return $data;
        }

        if (is_object($data)) {
            if (method_exists($data, 'toArray')) {
                return $this->formatDebugData($data->toArray());
            }

            if ($data instanceof \JsonSerializable) {
                return $this->formatDebugData($data->jsonSerialize());
            }

            if ($data instanceof \DateTimeInterface) {
                return $data->format(\DateTimeInterface::ATOM);
            }

            $objectAsArray = (array) $data;

            $cleanedArray = [];
            foreach ($objectAsArray as $key => $value) {
                $cleanedKey = preg_replace('/^\0.*?\0/', '', $key);
                $cleanedArray[$cleanedKey] = $this->formatDebugData($value);
            }
            return $cleanedArray;
        }

        if ($data === null) {
            return "";
        }


        return $data;
    }
}
