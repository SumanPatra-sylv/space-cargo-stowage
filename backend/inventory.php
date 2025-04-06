<?php
// backend/inventory.php (Modified for central index.php handling - CORRECTED QUERY)

/* --- REMOVED/COMMENTED OUT - Handled by index.php ---
// ... (headers and OPTIONS handling) ...
--- END REMOVED/COMMENTED OUT --- */

// Use __DIR__ for robust path handling when included by index.php
require_once __DIR__ . '/database.php'; // Defines getDbConnection()

// Initialize $db to avoid errors in catch block if connection fails early
$db = null;

try {
    $db = getDbConnection();
    if ($db === null) {
        http_response_code(503); // Service Unavailable
        echo json_encode(['success' => false, 'error' => 'Database connection unavailable.']); // Added success:false
        error_log("inventory.php Error: Failed to get DB connection.");
        exit;
    }

    // Query items that are currently stowed
    // *** CORRECTED WHERE CLAUSE ***
    $sql = "SELECT
                itemId, name, containerId,
                positionX, positionY, positionZ,
                dimensionW, dimensionH, dimensionD,
                placedDimensionW, placedDimensionH, placedDimensionD,
                mass, status, expiryDate, remainingUses, preferredZone, lastUpdated
            FROM items
            WHERE status = 'stowed'
            ORDER BY containerId, itemId"; // Optional ordering
    $stmt = $db->prepare($sql);
    $stmt->execute();

    $inventory = [];

    // Loop through results and format them
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Optionally rename itemId to id if frontend expects 'id'
        // $row['id'] = $row['itemId'];
        // unset($row['itemId']);

        // Nest position data
        $row['position'] = [
            'x' => isset($row['positionX']) ? (float)$row['positionX'] : null,
            'y' => isset($row['positionY']) ? (float)$row['positionY'] : null,
            'z' => isset($row['positionZ']) ? (float)$row['positionZ'] : null,
        ];
        // Nest placed dimensions, using original dimensions as fallback
         $row['placedDimensions'] = [
             'w' => isset($row['placedDimensionW']) ? (float)$row['placedDimensionW'] : (isset($row['dimensionW']) ? (float)$row['dimensionW'] : null),
             'h' => isset($row['placedDimensionH']) ? (float)$row['placedDimensionH'] : (isset($row['dimensionH']) ? (float)$row['dimensionH'] : null),
             'd' => isset($row['placedDimensionD']) ? (float)$row['placedDimensionD'] : (isset($row['dimensionD']) ? (float)$row['dimensionD'] : null)
         ];

        // Remove redundant top-level keys
        unset($row['positionX'], $row['positionY'], $row['positionZ']);
        unset($row['placedDimensionW'], $row['placedDimensionH'], $row['placedDimensionD']);
        // Optionally unset original dimensions if only placed are needed by frontend
        // unset($row['dimensionW'], $row['dimensionH'], $row['dimensionD']);

        $inventory[] = $row;
    }

    // Note: Content-Type header is already set by index.php
    http_response_code(200); // Explicitly set OK status

    // --- MODIFICATION: Return consistent object structure ---
    // It's generally better practice for APIs to return a consistent object envelope
    echo json_encode(['success' => true, 'inventory' => $inventory]);
    // --- END MODIFICATION ---
    // Original might have been: echo json_encode($inventory);

} catch (Exception $e) {
    // Note: Content-Type header is already set by index.php
    http_response_code(500); // Internal Server Error
    // Log the detailed error for the server admin
    error_log("inventory.php Error: " . $e->getMessage());
    // Send a generic error message to the client, including success:false
    echo json_encode(['success' => false, 'error' => 'Failed to fetch inventory data']);
    exit; // Exit script execution after sending error
} finally {
    // Close the connection
    $db = null;
}

// No exit() needed here for successful completion, index.php handles it.
?>