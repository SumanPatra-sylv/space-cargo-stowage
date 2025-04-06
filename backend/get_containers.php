<?php
// --- File: backend/get_containers.php (Modified for central index.php handling) ---

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

/* --- TEMPORARILY REMOVED as requested ---
// Keep error reporting settings if needed per script, or manage centrally in index.php
// ini_set('display_errors', 0); // Production: 0, Development: 1 (as set in index.php)
// error_reporting(E_ALL);      // Production: E_ALL & ~E_DEPRECATED & ~E_STRICT, Development: E_ALL (as set in index.php)
--- END TEMPORARILY REMOVED --- */


// 2. Include Database Connection Function Definition
// Make sure this path is relative to index.php or use absolute path if necessary
require_once __DIR__ . '/database.php'; // Use __DIR__ for robustness when included by index.php

// 3. Prepare Error Response Structure
function send_error($statusCode, $message) {
    // Note: Headers (like Content-Type) are already set by index.php before this script is included
    http_response_code($statusCode);
    // Ensure the response structure matches what the frontend expects for errors
    echo json_encode(['success' => false, 'message' => $message, 'containers' => []]); // Added containers: [] for consistency?
    error_log("get_containers.php Error: Status=$statusCode, Message=$message");
    exit(); // Exit after sending error
}

// 4. Get Database Connection & Perform Logic
try {
    $pdo = getDbConnection();

    if ($pdo === null) {
        // index.php might already have set Content-Type, but we still need the status and message
        send_error(503, "Service Unavailable: Could not connect to the database.");
    }

    // --- CORRECTED QUERY ---
    // Select only the columns that actually exist in the 'containers' table
    $sql = "SELECT
                containerId,
                zone,
                width,   -- Column exists as 'width'
                depth,   -- Column exists as 'depth'
                height   -- Column exists as 'height'
                -- 'name' and 'maxWeight' columns do not exist in the table
            FROM containers";

    $stmt = $pdo->prepare($sql);

    if ($stmt->execute()) {
        $containers_from_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $containers_output = [];

        // --- SIMPLIFIED MAPPING (No translation needed for dimensions) ---
        foreach ($containers_from_db as $container) {
            // Directly map the existing columns.
            // API expects 'name' and 'maxWeight', but they are not in the DB.
            // We will omit them from the response for now.
            $output_container = [
                'containerId' => $container['containerId'],
                // 'name' => $container['name'], // Cannot include - no 'name' column in DB
                'zone' => $container['zone'],
                // Cast to float for JSON consistency as numbers
                'width' => isset($container['width']) ? (float)$container['width'] : null,
                'depth' => isset($container['depth']) ? (float)$container['depth'] : null,
                'height' => isset($container['height']) ? (float)$container['height'] : null,
                // 'maxWeight' => $container['maxWeight'], // Cannot include - no 'maxWeight' column in DB
            ];
            $containers_output[] = $output_container;
        }

        // 5. Send Success Response
        // Note: Headers (like Content-Type) are already set by index.php
        http_response_code(200); // Still set the status code
        // Ensure the success response structure matches frontend expectations
        echo json_encode(['success' => true, 'containers' => $containers_output]);

    } else {
        $errorInfo = $stmt->errorInfo();
        send_error(500, "Failed to retrieve containers. DB Error: " . ($errorInfo[2] ?? 'Unknown error'));
    }

} catch (PDOException $e) {
    send_error(500, "Database query error: " . $e->getMessage());
} catch (Exception $e) {
    send_error(500, "An unexpected error occurred: " . $e->getMessage());
} finally {
     $pdo = null; // Close connection
}

// No need for explicit exit() here if the script naturally ends,
// but the exit() in send_error is important.
// The index.php router will exit after including this script.

?>