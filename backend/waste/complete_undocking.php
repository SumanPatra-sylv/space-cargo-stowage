<?php // backend/waste/complete_undocking.php (Corrected Version - No Direct Logging)

// --- Database Connection ---
require_once __DIR__ . '/../database.php'; // Correct path
// Removed: require_once for log_action.php

$db = null;

try {
    $db = getDbConnection();
    if ($db === null) {
        error_log("Complete Undocking API (Specific Container): Failed DB connection.");
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'itemsRemoved' => 0,
            'message' => 'Database connection failed'
        ]);
        exit;
    }
} catch (Exception $e) {
     error_log("Complete Undocking API (Specific Container): DB connection exception - " . $e->getMessage());
     http_response_code(503);
     echo json_encode([
         'success' => false,
         'itemsRemoved' => 0,
         'message' => 'Database connection error'
     ]);
     exit;
}

// --- Initialize Response ---
$response = [
    'success' => false,
    'itemsRemoved' => 0,
    'containerId' => null,
    'timestamp' => null,
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
if ($requestData === null || !isset($requestData['undockingContainerId']) || !isset($requestData['timestamp'])) {
    http_response_code(400);
    $response['message'] = 'Invalid JSON: undockingContainerId and timestamp required';
    error_log("Complete Undocking Error: Invalid JSON input " . $rawData);
    echo json_encode($response);
    $db = null;
    exit;
}

// Sanitize and validate inputs
$undockingContainerId = trim($requestData['undockingContainerId']);
$userId = $requestData['userId'] ?? 'System_Undock';
$rawTimestamp = $requestData['timestamp'];

// Validate timestamp format
try {
    $timestamp = (new DateTime($rawTimestamp))->format('Y-m-d H:i:s');
    $response['timestamp'] = $timestamp;
} catch (Exception $e) {
    http_response_code(400);
    $response['message'] = 'Invalid timestamp format. Use ISO8601 compatible format.';
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
$response['containerId'] = $undockingContainerId;
error_log("Processing Complete Undocking Request: Container: $undockingContainerId, Timestamp: $timestamp, User: $userId");

// --- Core Transaction ---
$db->beginTransaction();
$itemsInContainer = []; // Initialize for logging details later
$updatedCount = 0;     // Initialize count

try {
    // 1. Get list of ALL items *currently in the specified container* for potential use (e.g., logging from frontend)
    $itemsQuery = $db->prepare("
        SELECT itemId, name, status
        FROM items
        WHERE containerId = :containerId
    ");
    $itemsQuery->execute([':containerId' => $undockingContainerId]);
    $itemsInContainer = $itemsQuery->fetchAll(PDO::FETCH_ASSOC); // Store for potential frontend logging
    $itemCount = count($itemsInContainer);
    error_log("Complete Undocking: Found $itemCount total items currently listed in container $undockingContainerId.");

    if ($itemCount > 0) { // Only proceed if there are items in the container
        // 2. Update ALL items located in the specified container to 'disposed'
        $updateStmt = $db->prepare("
            UPDATE items
            SET
                containerId = NULL, positionX = NULL, positionY = NULL, positionZ = NULL,
                placedDimensionW = NULL, placedDimensionD = NULL, placedDimensionH = NULL,
                status = 'disposed',
                lastUpdated = :timestamp
            WHERE containerId = :containerId
        ");
        $updateSuccess = $updateStmt->execute([
            ':containerId' => $undockingContainerId,
            ':timestamp' => $timestamp
        ]);

        if (!$updateSuccess) {
             $errorInfo = $updateStmt->errorInfo();
             error_log("Complete Undocking Error: Failed DB update for container $undockingContainerId. Error: " . ($errorInfo[2] ?? 'Unknown error'));
             throw new Exception("Database update failed during undocking completion.");
        }
        $updatedCount = $updateStmt->rowCount();
        error_log("Complete Undocking: Successfully updated $updatedCount items from container $undockingContainerId to disposed.");
    } else {
         error_log("Complete Undocking: No items found in $undockingContainerId to update.");
    }

    // --- NO DIRECT LOGGING CALL HERE ---

    // Commit transaction
    $db->commit();
    error_log("Complete Undocking: Transaction committed.");

    // Build success response
    $response = [
        'success' => true,
        'itemsRemoved' => $updatedCount,
        'message' => "Successfully processed undocking for $undockingContainerId. $updatedCount items marked as disposed."
        // We might need to send $itemsInContainer back if frontend needs it for logging details
        // 'disposedItemsDetails' => $itemsInContainer // Example: Add this if needed for frontend log
    ];
    http_response_code(200);

} catch (PDOException $e) {
    if ($db->inTransaction()) { $db->rollBack(); error_log("Complete Undocking: Rolled back on PDOException."); }
    http_response_code(500);
    $response['success'] = false;
    $response['itemsRemoved'] = 0;
    $response['message'] = "Database error during undocking: " . $e->getMessage();
    error_log("Complete Undocking PDOException: " . $e->getMessage());
    echo json_encode($response);
    $db = null; exit;

} catch (Exception $e) {
    $code = $e->getCode();
    if ($db->inTransaction()) { $db->rollBack(); error_log("Complete Undocking: Rolled back on Exception."); }
    $statusCode = is_int($code) && $code >= 400 && $code < 600 ? $code : 500;
    http_response_code($statusCode);
    $response['success'] = false;
    $response['itemsRemoved'] = 0;
    $response['message'] = "Error during undocking: " . $e->getMessage();
    error_log("Complete Undocking Exception ({$statusCode}): " . $e->getMessage());
    echo json_encode($response);
    $db = null; exit;

} finally {
    $db = null;
}

// --- Final Output ---
if ($response['success']) {
    echo json_encode($response);
}
// error_log("Complete Undocking API: Request processing finished.");
?>