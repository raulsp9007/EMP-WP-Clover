<?php

namespace Src\Services;

class ModifierService extends BaseService
{
    /**
     * Get modifiers for a specific item
     *
     * @param string $itemId The item ID
     * @return array The modifiers data
     */
    public function getItemModifiers(string $itemId): array
    {
        $endpoint = "/items/{$itemId}?expand=modifierGroups";
        clover_log("ModifierService: Making API call to: {$this->baseUrl}{$endpoint}");
        return $this->get($endpoint);
    }

    /**
     * Get a specific modifier by its ID and modifier group ID
     *
     * @param string $modifierGroupId The modifier group ID
     * @param string $modifierId The modifier ID
     * @return array The modifier data
     */
    public function getModifier(string $modifierGroupId, string $modifierId): array
    {
        $endpoint = "/modifier_groups/{$modifierGroupId}/modifiers/{$modifierId}";
        clover_log("ModifierService: Making API call to get modifier: {$this->baseUrl}{$endpoint}");
        return $this->get($endpoint);
    }

    /**
     * Get all modifiers for an item by processing the modifier groups
     *
     * @param string $itemId The item ID
     * @param array|null $prefetchedData Optional pre-fetched item data with expanded modifier groups
     * @return array Array of modifiers with their details
     */
    public function getAllItemModifiers(string $itemId, ?array $prefetchedData = null): array
    {
        clover_log("ModifierService: Starting to get modifiers for item ID: {$itemId}");

        // Use pre-fetched data if provided, otherwise fetch from API
        if ($prefetchedData !== null) {
            clover_log("ModifierService: Using pre-fetched data for {$itemId} (no API call needed)");
            $itemData = array('data' => $prefetchedData);
        } else {
            // First, get the item with its modifier groups
            $itemData = $this->getItemModifiers($itemId);
            clover_log("ModifierService: Item data response for {$itemId}: " . print_r($itemData, true));
        }

        if (!isset($itemData['data']['modifierGroups']['elements']) || !is_array($itemData['data']['modifierGroups']['elements'])) {
            clover_log("ModifierService: No modifier groups found for item {$itemId}");
            return array('modifiers' => array());
        }

        $allModifiers = array();
        $modifierGroups = $itemData['data']['modifierGroups']['elements'];
        clover_log("ModifierService: Found " . count($modifierGroups) . " modifier groups for item {$itemId}");

        foreach ($modifierGroups as $group) {
            clover_log("ModifierService: Processing modifier group: " . ($group['id'] ?? 'unknown') . " for item {$itemId}");

            // Check if modifiers are already expanded in the pre-fetched data
            if (isset($group['modifiers']['elements']) && is_array($group['modifiers']['elements'])) {
                // Use the pre-expanded modifiers (no individual API calls needed)
                foreach ($group['modifiers']['elements'] as $modifier) {
                    if (isset($modifier['id'], $modifier['name'], $modifier['price'])) {
                        $modifier['modifierGroupId'] = $group['id'];
                        $modifier['modifierGroupName'] = $group['name'] ?? '';
                        $allModifiers[] = $modifier;
                        clover_log("ModifierService: Successfully added modifier {$modifier['id']} from pre-fetched data for item {$itemId}");
                    }
                }
            } elseif (isset($group['modifierIds'])) {
                clover_log("ModifierService: Modifier IDs for group " . ($group['id'] ?? 'unknown') . ": " . $group['modifierIds']);

                // Split the modifier IDs string
                $modifierIds = explode(',', $group['modifierIds']);

                foreach ($modifierIds as $modifierId) {
                    $modifierId = trim($modifierId); // Clean up any whitespace
                    clover_log("ModifierService: Importing modifier {$modifierId} of item {$itemId}");

                    // Get the full modifier details
                    $modifierDetails = $this->getModifier($group['id'], $modifierId);

                    clover_log("ModifierService: Modifier details for {$modifierId}: " . print_r($modifierDetails, true));

                    if (isset($modifierDetails['data']['id'])) {
                        // Add the modifier group information to the modifier data
                        $modifierDetails['data']['modifierGroupId'] = $group['id'];
                        $modifierDetails['data']['modifierGroupName'] = $group['name'] ?? '';

                        $allModifiers[] = $modifierDetails['data'];
                        clover_log("ModifierService: Successfully added modifier {$modifierId} to list for item {$itemId}");
                    } else {
                        clover_log("ModifierService: Failed to get modifier details for {$modifierId} in item {$itemId}");
                    }
                }
            } else {
                clover_log("ModifierService: No modifierIds found in group " . ($group['id'] ?? 'unknown') . " for item {$itemId}");
            }
        }

        clover_log("ModifierService: Completed getting modifiers for item {$itemId}. Total modifiers: " . count($allModifiers));
        return array('modifiers' => $allModifiers);
    }
}