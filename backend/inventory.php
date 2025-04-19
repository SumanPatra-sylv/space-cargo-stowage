<?php
// backend/inventory.php (Modified for grouping and priority sorting)

// Use __DIR__ for robust path handling when included by index.php
require_once __DIR__ . '/database.php'; // Defines getDbConnection()

// Initialize $db to avoid errors in catch block if connection fails early
$db = null;

try {
    $db = getDbConnection();
    if ($db === null) {
        http_response_code(503); // Service Unavailable
        // index.php may have already set Content-Type, but we ensure JSON structure
        echo json_encode(['success' => false, 'error' => 'Database connection unavailable.']);
        error_log("inventory.php Error: Failed to get DB connection.");
        exit; // Exit script
    }

    // Query items that are currently stowed
    // *** ADD 'priority' TO SELECT ***
    // Order by containerId initially to make grouping easier in PHP
    $sql = "SELECT
                itemId, name, containerId, priority, -- Added priority
                positionX, positionY, positionZ,
                dimensionW, dimensionH, dimensionD,
                placedDimensionW, placedDimensionH, placedDimensionD,
                mass, status, expiryDate, remainingUses, preferredZone, lastUpdated
            FROM items
            WHERE status = 'stowed' AND containerId IS NOT NULL -- Ensure containerId is not null for grouping
            ORDER BY containerId"; // Helps slightly but PHP grouping is the main mechanism
    $stmt = $db->prepare($sql);
    $stmt->execute();

    // Fetch all results first to process in PHP
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- NEW: Group items by containerId ---
    $groupedInventory = [];
    foreach ($results as $row) {
        $containerId = $row['containerId'];

        // Initialize container array in the group if it doesn't exist
        if (!isset($groupedInventory[$containerId])) {
            $groupedInventory[$containerId] = []; // Create an empty array for this container
        }

        // Format the item data (nesting, type casting, etc.)
        $itemData = $row; // Start with the raw row data

        // Ensure priority is an integer, default to 0 if null/missing
        $itemData['priority'] = isset($row['priority']) ? (int)$row['priority'] : 0;

        // Nest position data
        $itemData['position'] = [
            'x' => isset($row['positionX']) ? (float)$row['positionX'] : null,
            'y' => isset($row['positionY']) ? (float)$row['positionY'] : null,
            'z' => isset($row['positionZ']) ? (float)$row['positionZ'] : null,
        ];

        // Nest placed dimensions, using original dimensions as fallback if placed are null
        $itemData['placedDimensions'] = [
             'w' => isset($row['placedDimensionW']) ? (float)$row['placedDimensionW'] : (isset($row['dimensionW']) ? (float)$row['dimensionW'] : null),
             'h' => isset($row['placedDimensionH']) ? (float)$row['placedDimensionH'] : (isset($row['dimensionH']) ? (float)$row['dimensionH'] : null),
             'd' => isset($row['placedDimensionD']) ? (float)$row['placedDimensionD'] : (isset($row['dimensionD']) ? (float)$row['dimensionD'] : null)
        ];

        // Optionally cast other numeric fields
        $itemData['mass'] = isset($row['mass']) ? (float)$row['mass'] : null;
        $itemData['remainingUses'] = isset($row['remainingUses']) ? (int)$row['remainingUses'] : null;


        // Remove redundant top-level keys that are now nested
        unset($itemData['positionX'], $itemData['positionY'], $itemData['positionZ']);
        unset($itemData['placedDimensionW'], $itemData['placedDimensionH'], $itemData['placedDimensionD']);
        // Keep original dimensions if needed, otherwise unset them too
        // unset($itemData['dimensionW'], $itemData['dimensionH'], $itemData['dimensionD']);

        // Optional: Remove containerId from individual item data if only needed as group key
        // unset($itemData['containerId']);

        // Add the formatted item to its specific container group
        $groupedInventory[$containerId][] = $itemData;
    }

    // --- NEW: Sort items within each container group by priority (descending) ---
    foreach ($groupedInventory as $containerId => &$items) { // Use reference '&' to modify the array directly
        usort($items, function ($itemA, $itemB) {
            // Get priorities, default to 0 if missing/null
            $priorityA = $itemA['priority'] ?? 0;
            $priorityB = $itemB['priority'] ?? 0;

            // Sort descending: Higher priority ($priorityB) comes before lower priority ($priorityA)
            // The spaceship operator (<=>) returns -1, 0, or 1
            return $priorityB <=> $priorityA;
        });
    }
    unset($items); // IMPORTANT: Unset the reference after the loop is finished

    // --- Output the grouped and sorted inventory ---
    http_response_code(200); // Explicitly set OK status

    // Return the final structure: an object with containerIds as keys
    // containing arrays of items sorted by priority.
    // Ensure Content-Type is set by index.php or set it here if running standalone
    // header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => true, 'inventoryByContainer' => $groupedInventory]);

} catch (Exception $e) {
    // Ensure an error status code is sent
    if (http_response_code() < 400) { // Only set if not already an error like 503
         http_response_code(500); // Internal Server Error
    }
    // Log the detailed error for the server admin/developer
    error_log("inventory.php Error: " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString());

    // Send a generic, safe error message to the client
    // Ensure Content-Type is set by index.php or set it here if running standalone
    // header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'An internal error occurred while fetching inventory data. Please try again later.']);
    exit; // Exit script execution after sending error response

} finally {
    // Always ensure the database connection is closed
    $db = null;
}

// No exit() needed here for successful completion when included by index.php
?>