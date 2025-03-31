<?php
// backend/api/waste/complete_undocking.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// --- Database Connection ---
require_once __DIR__ . '/../../database.php'; // Up two levels
error_log("Complete Undocking API (Specific Container): Initializing database connection...");
$db = getDbConnection();

if ($db === null) {
    error_log("Complete Undocking API (Specific Container): Failed DB connection.");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'itemsRemoved' => 0,
        'message' => 'Database connection failed'
    ]);
    exit;
}
error_log("Complete Undocking API (Specific Container): Database connected successfully.");

// --- Initialize Response ---
$response = [
    'success' => false,
    'itemsRemoved' => 0,
    'containerId' => null, // Add requested container ID to response
    'timestamp' => null,   // Add requested timestamp to response
    'message' => ''
];

// --- Validate Request Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Only POST requests are allowed';
    error_log("Complete Undocking Error: Invalid method " . $_SERVER['REQUEST_METHOD']);
    echo json_encode($response);
    $db = null;
    exit;
}

// --- Process Input ---
$rawData = file_get_contents('php://input');
$requestData = json_decode($rawData, true);

// Validate JSON structure
if ($requestData === null || !isset($requestData['undockingContainerId']) || !isset($requestData['timestamp'])) { // Check for timestamp too
    http_response_code(400);
    $response['message'] = 'Invalid JSON: undockingContainerId and timestamp required';
    error_log("Complete Undocking Error: Invalid JSON input " . $rawData);
    echo json_encode($response);
    $db = null;
    exit;
}

// Sanitize and validate inputs
$undockingContainerId = trim($requestData['undockingContainerId']);
$userId = $requestData['userId'] ?? 'System'; // Optional user
$rawTimestamp = $requestData['timestamp']; // Timestamp is required

// Validate timestamp format (ISO8601 or similar SQL compatible)
try {
    // Format for storage and potential comparison
    $timestamp = (new DateTime($rawTimestamp))->format('Y-m-d H:i:s'); 
    $response['timestamp'] = $timestamp; // Add to response
} catch (Exception $e) {
    http_response_code(400);
    $response['message'] = 'Invalid timestamp format. Use ISO8601 compatible format (e.g., YYYY-MM-DD HH:MM:SS or YYYY-MM-DDTHH:MM:SSZ).';
    error_log("Complete Undocking Error: Invalid timestamp format: " . $rawTimestamp);
    echo json_encode($response);
    $db = null;
    exit;
}

// Validate container ID
if (empty($undockingContainerId)) {
    http_response_code(400);
    $response['message'] = 'Container ID cannot be empty';
    error_log("Complete Undocking Error: Empty container ID.");
    echo json_encode($response);
    $db = null;
    exit;
}
$response['containerId'] = $undockingContainerId; // Add to response
error_log("Complete Undocking Request: Container: $undockingContainerId, Timestamp: $timestamp, User: $userId");


// --- Core Transaction ---
$db->beginTransaction();

try {
    // 1. Verify container exists (Optional but good check)
    // Note: This checks the actual containers table, not just if items are assigned to it.
    /* 
    $containerCheck = $db->prepare("SELECT 1 FROM containers WHERE containerId = :containerId");
    $containerCheck->execute([':containerId' => $undockingContainerId]);
    if (!$containerCheck->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception("Undocking Container '$undockingContainerId' not found in containers table.", 404);
    }
    error_log("Complete Undocking: Verified undocking container $undockingContainerId exists.");
    */ 
    // Skipping container existence check for simplicity now, assuming valid ID is passed.

    // 2. Get list of waste items *currently in the specified container* for logging
    $itemsQuery = $db->prepare("
        SELECT itemId, name, status
        FROM items 
        WHERE status IN ('waste_expired', 'waste_depleted')
        AND currentContainerId = :containerId 
    "); // Filter by currentContainerId
    $itemsQuery->execute([':containerId' => $undockingContainerId]);
    $wasteItems = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
    $itemCount = count($wasteItems);
    error_log("Complete Undocking: Found $itemCount waste items currently listed in container $undockingContainerId.");

    // It's okay if no waste items are found in that specific container, just means 0 updates.
    // The original code threw a 404 here, changing to proceed and report 0.
    // if ($itemCount === 0) {
    //     throw new Exception("No waste items found in $undockingContainerId", 404);
    // }

    $updatedCount = 0;
    if ($itemCount > 0) {
        // 3. Update items located in the specified container that are waste
        $updateStmt = $db->prepare("
            UPDATE items
            SET
                currentContainerId = NULL, -- Clear location
                pos_w = NULL,
                pos_d = NULL,
                pos_h = NULL,
                status = 'disposed',      -- Set final status
                lastUpdated = :timestamp  -- Update timestamp
            WHERE status IN ('waste_expired', 'waste_depleted')
            AND currentContainerId = :containerId -- Only items IN this container
        ");
        $updateSuccess = $updateStmt->execute([
            ':containerId' => $undockingContainerId,
            ':timestamp' => $timestamp
        ]);

        if (!$updateSuccess) {
             $errorInfo = $updateStmt->errorInfo();
             error_log("Complete Undocking Error: Failed DB update for container $undockingContainerId. Error Code: " . ($errorInfo[1] ?? 'N/A') . " Msg: " . ($errorInfo[2] ?? 'N/A'));
             throw new Exception("Database update failed during undocking completion.");
        }
        
        $updatedCount = $updateStmt->rowCount();
        error_log("Complete Undocking: Successfully updated $updatedCount items from container $undockingContainerId to disposed.");
    } else {
         error_log("Complete Undocking: No waste items found in $undockingContainerId to update.");
    }


    // 4. Log the operation 
    $logDetails = json_encode([
        'event' => 'complete_undocking', // Changed from 'operation'
        'undockingContainerId' => $undockingContainerId, // Changed from 'container'
        'itemsDisposed' => $updatedCount, // Changed from 'disposedItems'
        'itemsList' => array_map(function($item) { // Include list even if count is 0
            return ['id' => $item['itemId'], 'name' => $item['name'], 'prior_status' => $item['status']];
        }, $wasteItems),
        'processedBy' => $userId // Changed from 'system' maybe?
    ]);

    $logStmt = $db->prepare("
        INSERT INTO logs (userId, actionType, detailsJson, timestamp)
        VALUES (:userId, 'disposal', :details, :timestamp) -- Changed actionType
    ");
    $logSuccess = $logStmt->execute([
        ':userId' => $userId,
        ':details' => $logDetails,
        ':timestamp' => $timestamp
    ]);
     if (!$logSuccess) {
        error_log("Complete Undocking Error: Failed to insert log event. Error: " . print_r($logStmt->errorInfo(), true));
        // Don't fail the whole operation for logging error
    } else {
         error_log("Complete Undocking: Log entry created.");
    }

    // Commit transaction
    $db->commit();
    error_log("Complete Undocking: Transaction committed.");

    // Build success response
    $response = [
        'success' => true,
        'itemsRemoved' => $updatedCount, // Use itemsRemoved as per spec
        // 'containerId' => $undockingContainerId, // Not in spec response
        // 'timestamp' => $timestamp, // Not in spec response
        'message' => "Successfully processed undocking for $undockingContainerId. $updatedCount items marked as disposed."
    ];
    http_response_code(200);

} catch (PDOException $e) { // Catch specific DB errors
    if ($db->inTransaction()) { $db->rollBack(); error_log("Complete Undocking: Rolled back on PDOException."); }
    http_response_code(500);
    $response['message'] = "Database error during undocking: " . $e->getMessage();
    $response['itemsRemoved'] = 0;
    error_log("Complete Undocking PDOException: " . $e->getMessage());
} catch (Exception $e) { // Catch other general errors (like invalid container ID)
    $code = $e->getCode();
    if ($db->inTransaction()) { $db->rollBack(); error_log("Complete Undocking: Rolled back on Exception."); }
    $statusCode = $code >= 400 && $code < 600 ? $code : 500; // Use exception code if valid HTTP error
    http_response_code($statusCode);
    $response['message'] = "Error during undocking: " . $e->getMessage();
     $response['itemsRemoved'] = 0;
    error_log("Complete Undocking Exception ({$statusCode}): " . $e->getMessage());
} finally {
    $db = null;
}

// --- Final Output ---
echo json_encode($response, JSON_PRETTY_PRINT);
error_log("Complete Undocking API: Request processed");
exit; // Ensure script exits properly
?>