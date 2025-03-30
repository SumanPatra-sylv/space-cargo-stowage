<?php // backend/api/waste/complete_undocking.php

ini_set('display_errors', 1); 
error_reporting(E_ALL); 

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: POST, OPTIONS'); 
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// --- Database Connection ---
require_once __DIR__ . '/../../database.php'; // Up two levels
error_log("Complete Undocking API: Attempting DB connection...");
$db = getDbConnection(); 

if ($db === null) { /* ... Handle DB connection error ... */ exit; }
error_log("Complete Undocking API: DB connection successful.");
// --- End Database Connection --- 

// --- Response Structure ---
$response = ['success' => false, 'itemsRemoved' => 0, 'message' => ''];

// --- Handle Input ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { /* ... Handle 405 ... */ exit; }

$rawData = file_get_contents('php://input');
$requestData = json_decode($rawData, true); 

// Validate input JSON Structure - Needs undockingContainerId and timestamp
if ($requestData === null || !isset($requestData['undockingContainerId']) || !isset($requestData['timestamp'])) {
    http_response_code(400); 
    $response['message'] = 'Invalid JSON input. Required: undockingContainerId, timestamp.';
    error_log("Complete Undocking Error: Invalid JSON input: " . $rawData);
    echo json_encode($response); $db = null; exit;
}

$undockingContainerId = trim($requestData['undockingContainerId']);
$timestamp = trim($requestData['timestamp']); // Use provided timestamp for logging
$userId = $requestData['userId'] ?? 'System'; // Optional user, default System

if (empty($undockingContainerId) || empty($timestamp)) { // TODO: Add timestamp validation if needed
     http_response_code(400); 
     $response['message'] = 'Invalid input values. undockingContainerId and timestamp cannot be empty.';
     error_log("Complete Undocking Error: Invalid input values.");
     echo json_encode($response); $db = null; exit;
}
error_log("Complete Undocking request received for Container: $undockingContainerId, Timestamp: $timestamp, User: $userId");
// --- End Input Handling ---
// --- Core Logic ---
$db->beginTransaction(); 
try {
    // Define the final status for disposed items
    $finalStatus = 'disposed'; 

    // Prepare statement to find and update waste items
    // We find items currently marked as waste and clear their location / set final status
    // We target items regardless of their currentContainerId, assuming they *should* have been moved
    $updateSql = "UPDATE items 
                  SET 
                      currentContainerId = NULL, 
                      pos_w = NULL, 
                      pos_d = NULL, 
                      pos_h = NULL,
                      status = :finalStatus 
                  WHERE status = 'waste_expired' OR status = 'waste_depleted'";
                  
    $updateStmt = $db->prepare($updateSql);
    $updateStmt->bindParam(':finalStatus', $finalStatus);
    
    if (!$updateStmt->execute()) {
         $errorInfo = $updateStmt->errorInfo();
         error_log("Complete Undocking Error: Failed DB update. Error Code: " . ($errorInfo[1] ?? 'N/A') . " Msg: " . ($errorInfo[2] ?? 'N/A'));
         throw new Exception("Database update failed during undocking completion.");
    }
    
    $updatedCount = $updateStmt->rowCount(); // Get number of items updated
    error_log("Complete Undocking: Marked $updatedCount waste items as '$finalStatus' and cleared location.");

    // Log the Undocking Event (could log affected item IDs if needed)
    $logSql = "INSERT INTO logs (userId, actionType, detailsJson, timestamp) VALUES (:userId, 'disposal', :details, :timestamp)"; // Using 'disposal' type
    $logStmt = $db->prepare($logSql);
    $detailsArray = [
        'event' => 'complete_undocking',
        'undockingContainerId' => $undockingContainerId,
        'itemsProcessed' => $updatedCount 
        // Could fetch item IDs affected if needed: SELECT itemId FROM items WHERE status = 'disposed' AND lastUpdateTime = now()? Tricky.
    ];
    $detailsJson = json_encode($detailsArray);
    
    $logStmt->bindParam(':userId', $userId);
    $logStmt->bindParam(':details', $detailsJson);
    $logStmt->bindParam(':timestamp', $timestamp); 

    if (!$logStmt->execute()) {
        error_log("Complete Undocking Error: Failed to insert log event. Error: " . print_r($logStmt->errorInfo(), true));
        // Decide if this failure should cause a rollback - likely not critical
    } else {
        error_log("Complete Undocking: Successfully logged event for container $undockingContainerId.");
    }

    // Commit transaction
    $db->commit();
    $response['success'] = true;
    $response['itemsRemoved'] = $updatedCount; // Return how many items were logically removed/disposed
    $response['message'] = "Undocking completed for container '$undockingContainerId'. Processed $updatedCount waste items.";
    http_response_code(200);

} catch (Exception $e) {
    if ($db->inTransaction()) { $db->rollBack(); } 
    http_response_code(500);
    $response['message'] = 'Error completing undocking: ' . $e->getMessage();
    error_log("Complete Undocking Exception: " . $e->getMessage());
} finally {
    $db = null; 
}
// --- End Core Logic ---
// --- Final Output ---
echo json_encode($response, JSON_PRETTY_PRINT); 
error_log("Complete Undocking API: Finished.");
?>