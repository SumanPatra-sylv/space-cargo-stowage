<?php
// backend/inventory.php (Modified for central index.php handling)

/* --- REMOVED/COMMENTED OUT - Handled by index.php ---
// header("Content-Type: application/json"); // Handled by index.php
// header("Access-Control-Allow-Origin: *"); // Handled by index.php
// header("Access-Control-Allow-Methods: GET, OPTIONS"); // Handled by index.php
// header("Access-Control-Allow-Headers: Content-Type"); // Handled by index.php
//
// if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
//     http_response_code(204); // Note: index.php uses 200 for OPTIONS
//     exit;
// }
--- END REMOVED/COMMENTED OUT --- */

// Use __DIR__ for robust path handling when included by index.php
require_once __DIR__ . '/database.php'; // Defines getDbConnection()

// Initialize $db to avoid errors in catch block if connection fails early
$db = null;

try {
    $db = getDbConnection();
    if ($db === null) {
        // If DB connection fails, send an error response
        // index.php already set Content-Type
        http_response_code(503); // Service Unavailable
        echo json_encode(['error' => 'Database connection unavailable.']);
        error_log("inventory.php Error: Failed to get DB connection.");
        exit; // Exit script execution here
    }

    // Query items that are placed
    // *** FIXED: Changed 'id' to 'itemId' ***
    // Use prepare/execute even for queries without parameters for consistency and potential future needs
    $sql = "SELECT
                itemId, name, containerId, -- Changed id to itemId
                positionX, positionY, positionZ,
                dimensionW, dimensionH, dimensionD,
                placedDimensionW, placedDimensionH, placedDimensionD,
                mass, status, expiryDate, remainingUses, preferredZone, lastUpdated
            FROM items
            WHERE containerId IS NOT NULL AND positionX IS NOT NULL";
    $stmt = $db->prepare($sql);
    $stmt->execute();

    $inventory = [];
    // Use fetchAll for simplicity if memory is not a concern for the expected inventory size
    // $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // foreach ($results as $row) { ... }

    // Or keep the while loop for potentially very large inventories
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Rename itemId to id in the output JSON if frontend expects 'id'
        $row['id'] = $row['itemId']; // Add this line if needed
        // unset($row['itemId']); // Optional: remove original itemId field if frontend doesn't need it

        // Nest position data
        $row['position'] = [
            // Cast to float/int if necessary for JSON type consistency
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
        // Also optionally remove original dimensions if only placedDimensions are needed
        // unset($row['dimensionW'], $row['dimensionH'], $row['dimensionD']);

        $inventory[] = $row;
    }

    // Note: Content-Type header is already set by index.php
    http_response_code(200); // Explicitly set OK status
    echo json_encode($inventory);

} catch (Exception $e) {
    // Note: Content-Type header is already set by index.php
    http_response_code(500); // Internal Server Error
    // Log the detailed error for the server admin
    error_log("inventory.php Error: " . $e->getMessage());
    // Send a generic error message to the client
    echo json_encode(['error' => 'Failed to fetch inventory data']);
    exit; // Exit script execution after sending error
} finally {
    // Close the connection
    $db = null;
}

// No exit() needed here for successful completion, index.php handles it.
?>