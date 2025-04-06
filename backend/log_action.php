<?php // backend/log_action.php

require_once __DIR__ . '/database.php';

error_log("Log Action API: Attempting DB connection...");
$db = getDbConnection();
if ($db === null) {
    // Simplified error handling - relying on appropriate HTTP status code
    http_response_code(503); // Service Unavailable
    echo json_encode(['success' => false, 'message' => 'Log Action Error: Database connection failed']);
    exit;
}
error_log("Log Action API: DB connection successful.");

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    $response['message'] = 'Only POST requests are allowed for logging';
    error_log("Log Action Error: Invalid method " . $_SERVER['REQUEST_METHOD']);
    echo json_encode($response);
    $db = null;
    exit;
}

$rawData = file_get_contents('php://input');
$requestData = json_decode($rawData, true);

// --- MODIFIED VALIDATION ---
// Validate input for logging - itemId is now OPTIONAL
if (
    $requestData === null ||
    !isset($requestData['userId']) ||       // Required
    !isset($requestData['actionType']) ||  // Required
    // REMOVED: !isset($requestData['itemId']) || // itemId is no longer strictly required here
    !isset($requestData['details'])        // Required
) {
    http_response_code(400);
    // Updated message
    $response['message'] = 'Invalid JSON input or missing required fields (userId, actionType, details are mandatory).';
    error_log("Log Action API: Invalid JSON input received: " . $rawData);
    echo json_encode($response);
    $db = null;
    exit;
}

// --- MODIFIED VARIABLE ASSIGNMENT ---
$userId = trim($requestData['userId']); // Added trim
$actionType = trim($requestData['actionType']); // Added trim
// Get itemId if present, otherwise set to null
$itemId = isset($requestData['itemId']) ? trim($requestData['itemId']) : null;
$details = $requestData['details']; // Expect details object/array

// Basic validation for non-empty required strings
if (empty($userId) || empty($actionType)) {
     http_response_code(400);
     $response['message'] = 'userId and actionType cannot be empty.';
     error_log("Log Action Error: Empty userId or actionType.");
     echo json_encode($response);
     $db = null; exit;
}

// Encode the details into JSON string for storage
$detailsJson = json_encode($details);
if ($detailsJson === false) {
     http_response_code(400);
     $response['message'] = 'Failed to encode details into JSON.';
     error_log("Log Action Error: Failed to JSON encode details: " . print_r($details, true));
     echo json_encode($response);
     $db = null; exit;
}

$timestamp = date('Y-m-d H:i:s'); // Log with current server time

$logItemIdForDebug = $itemId ?? 'N/A'; // Use null coalescing for debug log
error_log("Log Action API: Logging request for itemId: $logItemIdForDebug, Action: $actionType, User: $userId");

// --- NO TRANSACTION NEEDED FOR SINGLE INSERT ---
try {
    $logSql = "INSERT INTO logs (userId, actionType, itemId, detailsJson, timestamp)
               VALUES (:userId, :actionType, :itemId, :details, :timestamp)";
    $logStmt = $db->prepare($logSql);

    // Bind parameters - works correctly even if $itemId is null (assuming DB column allows NULL)
    $logStmt->bindParam(':userId', $userId);
    $logStmt->bindParam(':actionType', $actionType);
    $logStmt->bindParam(':itemId', $itemId); // PDO handles binding NULL correctly
    $logStmt->bindParam(':details', $detailsJson);
    $logStmt->bindParam(':timestamp', $timestamp);

    if (!$logStmt->execute()) {
        error_log("Log Action API Error: Failed to insert log. Error: " . print_r($logStmt->errorInfo(), true));
        // Use a more specific exception or just rethrow
        throw new Exception("Failed to insert log record.", 500);
    }

    error_log("Log Action API: Successfully logged action '$actionType' for itemId: $logItemIdForDebug by user $userId.");
    $response['success'] = true;
    $response['message'] = "Action '$actionType' logged successfully.";
    http_response_code(201); // Use 201 Created for successful resource creation (log entry)

} catch (PDOException | Exception $e) {
    $errCode = $e->getCode() ?: 500;
    $errMsg = ($errCode >= 500) ? 'An internal server error occurred during logging.' : $e->getMessage(); // Don't expose too much on 500 errors
    http_response_code($errCode < 400 ? 500 : $errCode); // Ensure valid HTTP status code
    $response['success'] = false;
    $response['message'] = "Log Action Error: " . $errMsg;
    error_log("Log Action API Exception: " . $e->getMessage()); // Log full error server-side

    // No need to echo here, final echo handles it
} finally {
    $db = null; // Ensure connection is closed
    // Removed redundant logging from here
}

// --- Final Output ---
// Echo the final response regardless of success/failure (catch block sets response details)
// Ensure headers weren't already sent (though shouldn't happen with exit calls)
if (!headers_sent()) {
    echo json_encode($response);
}
error_log("Log Action API: Finished processing request."); // Simple finish message

?>