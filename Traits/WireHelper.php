<?php
declare(strict_types=1);

namespace Forge\Traits;

use App\Modules\ForgeWire\Services\ComponentRegistry;

trait WireHelper {
    private ?ComponentRegistry $componentRegistry = null;

    public function setComponentRegistry(ComponentRegistry $registry): void
    {
        $this->componentRegistry = $registry;
    }
   /**
     * Track components found in the response HTML
     *
     * @return array<int, string> Component IDs found in the HTML
     */
    private function trackComponentsInResponse(string $html): array
    {
        if (!preg_match_all('/fw:id=["\']([^"\']+)["\']/', $html, $matches)) {
            return [];
        }

        $now = time();
        $componentIds = [];
        foreach ($matches[1] as $componentId) {
            $activeKey = "forgewire:active:{$componentId}";
            $this->session->set($activeKey, $now);
            $componentIds[] = $componentId;
        }

        return $componentIds;
    }

    /**
      * Clean up components that are no longer present on the current page
      * or haven't been seen recently.
      *
      * @param array<int, string> $currentComponentIds Component IDs present in the current HTML response
      */
    private function cleanupStaleComponents(array $currentComponentIds): void
    {
        $allKeys = $this->session->all();
        $now = time();
        $staleThreshold = (int) (config('forge_wire.stale_threshold', 300));
        $currentSet = array_flip($currentComponentIds);

        foreach ($allKeys as $key => $value) {
            if (preg_match('/^forgewire:active:([^:]+)$/', $key, $m)) {
                $id = $m[1];
                $lastSeen = (int) $value;
                if (!isset($currentSet[$id]) || ($now - $lastSeen) > $staleThreshold) {
                    $this->removeComponent($id);
                }
                continue;
            }

            if (!str_starts_with($key, 'forgewire:') && !str_starts_with($key, 'forge_storage:upload:')) {
                continue;
            }

            if (str_starts_with($key, 'forgewire:processed:')) {
                if (!is_numeric($value) || ($now - (int) $value) > $staleThreshold) {
                    $this->session->remove($key);
                }
                continue;
            }

            if (preg_match('/^forgewire:shared-group:(.+):components$/', $key, $m)) {
                $class = $m[1];
                $components = is_array($value) ? array_values(array_filter(
                    $value,
                    fn($id) => isset($currentSet[$id])
                )) : [];
                if (empty($components)) {
                    $this->session->remove($key);
                    $this->session->remove("forgewire:shared-group:{$class}:initialized");
                    $this->session->remove("forgewire:shared:{$class}");
                } else {
                    $this->session->set($key, $components);
                }
                continue;
            }

            if (str_starts_with($key, 'forge_storage:upload:')) {
                $createdAt = is_array($value) && isset($value['created_at']) && is_int($value['created_at'])
                    ? $value['created_at'] : null;
                if ($createdAt === null || ($now - $createdAt) > $staleThreshold) {
                    $this->session->remove($key);
                }
                continue;
            }
        }
    }

    /**
     * Remove a component and all its related session data
     */
    private function removeComponent(string $componentId): void
    {
        $componentClass = $this->componentRegistry?->getComponentData($componentId)['class'] ?? null;

        $this->componentRegistry?->unregister($componentId);

        $allKeys = array_keys($this->session->all());
        $prefix = "forgewire:{$componentId}";

        foreach ($allKeys as $key) {
            if (str_starts_with($key, $prefix . ':') || $key === $prefix) {
                $this->session->remove($key);
            }
        }

        if ($componentClass !== null) {
            $this->removeFromSharedGroups($componentId, $componentClass);
        }

        $this->session->remove("forgewire:active:{$componentId}");
    }

    /**
     * Remove component from shared groups and clean up empty groups
     */
    private function removeFromSharedGroups(string $componentId, string $componentClass): void
    {
        $groupKey = "forgewire:shared-group:{$componentClass}:components";
        if ($this->session->has($groupKey)) {
            $components = $this->session->get($groupKey, []);
            $components = array_filter($components, fn($id) => $id !== $componentId);
            $components = array_values($components);

            if (empty($components)) {
                // Remove entire shared group if empty
                $this->session->remove($groupKey);
                $this->session->remove("forgewire:shared-group:{$componentClass}:initialized");
                $this->session->remove("forgewire:shared:{$componentClass}");
            } else {
                $this->session->set($groupKey, $components);
            }
        }
    }
}
