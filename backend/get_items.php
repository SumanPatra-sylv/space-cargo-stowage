<?php
// --- File: backend/get_items.php (Modified for central index.php handling) ---

/* --- REMOVED/COMMENTED OUT - Handled by index.php ---
// 1. Set Headers
// header("Access-Control-Allow-Origin: http://localhost:5173"); // Adjust if needed
// header("Content-Type: application/json; charset=UTF-8"); // Handled by index.php
// header("Access-Control-Allow-Methods: GET, OPTIONS");
// header("Access-Control-Max-Age: 3600");
// header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
//
// if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
//     http_response_code(200);
//     exit();
// }
--- END REMOVED/COMMENTED OUT --- */

// Keep error reporting settings if needed per script, or manage centrally in index.php
ini_set('display_errors', 0); // Production: 0, Development: 1 (as set in index.php)
error_reporting(E_ALL);      // Production: E_ALL & ~E_DEPRECATED & ~E_STRICT, Development: E_ALL (as set in index.php)

// 2. Include Database Connection Function Definition
// Make sure this path is relative to index.php or use absolute path if necessary
require_once __DIR__ . '/database.php'; // Use __DIR__ for robustness when included by index.php

// 3. Prepare Error Response Structure
function send_item_error($statusCode, $message) {
    // Note: Headers (like Content-Type) are already set by index.php before this script is included
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'message' => $message]);
    error_log("get_items.php Error: Status=$statusCode, Message=$message");
    exit(); // Exit after sending error
}

// 4. Get Database Connection & Perform Logic
try {
    $pdo = getDbConnection();

    if ($pdo === null) {
        send_item_error(503, "Service Unavailable: Could not connect to the database.");
    }

    // Base SQL query - selecting columns needed for placement + mass
    // Using DB column names (dimensionW/D/H)
    $sql = "SELECT
                itemId,
                name,
                dimensionW,
                dimensionD,
                dimensionH,
                mass,
                priority,
                expiryDate,
                usageLimit,
                preferredZone,
                status -- Include status for potential filtering/debugging
            FROM items";

    // Check for status filter
    $params = [];
    if (isset($_GET['status']) && !empty($_GET['status'])) {
        $sql .= " WHERE status = :status";
        $params[':status'] = $_GET['status'];
    }

    $stmt = $pdo->prepare($sql);

    // Execute with parameters if filtering, otherwise without
    if ($stmt->execute($params)) { // Pass params array (empty if no filter)
        $items_from_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items_output = [];

        // Translate DB columns (dimensionW/D/H) to API fields (width/depth/height)
        foreach ($items_from_db as $item) {
            $translated_item = [
                'itemId' => $item['itemId'],
                'name' => $item['name'],
                // Perform the translation for dimensions
                'width' => isset($item['dimensionW']) ? (float)$item['dimensionW'] : null,
                'depth' => isset($item['dimensionD']) ? (float)$item['dimensionD'] : null,
                'height' => isset($item['dimensionH']) ? (float)$item['dimensionH'] : null,
                // Cast others to appropriate types for JSON
                'mass' => isset($item['mass']) ? (float)$item['mass'] : null,
                'priority' => isset($item['priority']) ? (int)$item['priority'] : null,
                'expiryDate' => $item['expiryDate'], // Assuming it's already string/null
                'usageLimit' => isset($item['usageLimit']) ? (int)$item['usageLimit'] : null,
                'preferredZone' => $item['preferredZone'],
                // We don't strictly need status in the placement payload, but can keep it
                // 'status' => $item['status']
            ];
            $items_output[] = $translated_item;
        }

        // 5. Send Success Response
        // Note: Headers (like Content-Type) are already set by index.php
        http_response_code(200); // Still set the status code
        echo json_encode(['success' => true, 'items' => $items_output]);

    } else {
        $errorInfo = $stmt->errorInfo();
        send_item_error(500, "Failed to retrieve items. DB Error: " . ($errorInfo[2] ?? 'Unknown error'));
    }

} catch (PDOException $e) {
    send_item_error(500, "Database query error: " . $e->getMessage());
} catch (Exception $e) {
    send_item_error(500, "An unexpected error occurred: " . $e->getMessage());
}

// The index.php router will exit after including this script.

?>