<?php

declare(strict_types=1);

namespace Forge\Traits;

use JsonException;

trait HasMetadataToJson
{
    /**
     * Returns the metadata as a JSON string.
     *
     * @return string|null
     */
    public function getMetadataAsJson(): ?string
    {
        if ($this->metadata === null) {
            return null;
        }

        try {
            return json_encode($this->metadata, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return null;
        }
    }
}
