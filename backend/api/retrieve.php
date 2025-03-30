<?php // backend/api/retrieve.php

ini_set('display_errors', 1); 
error_reporting(E_ALL); 

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: POST, OPTIONS'); // Allow POST
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// --- Database Connection ---
require_once __DIR__ . '/../database.php'; 
error_log("Retrieve API: Attempting DB connection...");
$db = getDbConnection(); 

if ($db === null) {
    error_log("Retrieve API: Failed DB connection.");
    http_response_code(500); 
    // Keep response structure consistent on error
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit; 
}
error_log("Retrieve API: DB connection successful.");
// --- End Database Connection --- 

// --- Response Structure ---
$response = ['success' => false, 'message' => ''];

// --- Handle Input ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); 
    $response['message'] = 'Invalid request method. Only POST is allowed.';
    error_log("Retrieve API: Received method " . $_SERVER['REQUEST_METHOD']);
    echo json_encode($response);
    $db = null; exit; 
}

$rawData = file_get_contents('php://input');
$requestData = json_decode($rawData, true); 

// Validate input JSON and required fields
if ($requestData === null || !isset($requestData['itemId'])) {
    http_response_code(400); 
    $response['message'] = 'Invalid JSON input or missing required "itemId".';
    error_log("Retrieve API: Invalid JSON input received: " . $rawData);
    echo json_encode($response);
    $db = null; exit;
}

$itemId = trim($requestData['itemId']); // Trim whitespace
$userId = isset($requestData['userId']) ? trim($requestData['userId']) : 'Unknown'; // Optional user, default Unknown
$timestamp = $requestData['timestamp'] ?? date('c'); // Use provided or generate current ISO 8601 time

if (empty($itemId)) {
     http_response_code(400); 
     $response['message'] = '"itemId" cannot be empty.';
     error_log("Retrieve API: Empty itemId received.");
     echo json_encode($response);
     $db = null; exit;
}
 error_log("Retrieve API: Request for itemId: $itemId, User: $userId, Timestamp: $timestamp");

// --- Core Logic ---
$db->beginTransaction(); // Use transaction for find & update consistency
try {
    // Find the item and its relevant details
    $findSql = "SELECT itemId, usageLimit, remainingUses, status FROM items WHERE itemId = :itemId";
    $findStmt = $db->prepare($findSql);
    $findStmt->bindParam(':itemId', $itemId);
    $findStmt->execute();
    $item = $findStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        http_response_code(404); // Not Found
        $response['message'] = "Item with ID '$itemId' not found.";
        error_log("Retrieve API: Item not found: $itemId");
        $db->rollBack(); // Rollback transaction
        echo json_encode($response);
        $db = null; exit;
    }
    
    error_log("Retrieve API: Found item $itemId. Status: " . $item['status'] . ", UsageLimit: " . ($item['usageLimit'] ?? 'N/A') . ", Remaining: " . ($item['remainingUses'] ?? 'N/A'));

    // Prevent retrieval if not currently stowed
    if ($item['status'] !== 'stowed') {
         http_response_code(400); // Bad Request - trying to retrieve non-stowed item
         $response['message'] = "Item '$itemId' cannot be retrieved, its status is currently '" . $item['status'] . "'.";
         error_log("Retrieve API: Attempted to retrieve non-stowed item: $itemId (Status: " . $item['status'] . ")");
         $db->rollBack();
         echo json_encode($response);
         $db = null; exit;
    }

    $newRemainingUses = $item['remainingUses']; // Initialize with current value
    $newStatus = $item['status']; // Initialize with current value
    $needsUpdate = false; // Flag to track if DB update is needed

    // Decrement remaining uses if applicable
    if ($item['usageLimit'] !== null && is_numeric($item['usageLimit'])) { // Check if item has a numeric usage limit
         if ($item['remainingUses'] === null || !is_numeric($item['remainingUses'])) {
              error_log("Retrieve API Warning: Item $itemId has usageLimit but remainingUses is invalid (" . $item['remainingUses'] . "). Assuming full uses remaining.");
              $newRemainingUses = $item['usageLimit'] - 1; // Start decrementing from full limit
              $needsUpdate = true;
         } elseif ($item['remainingUses'] <= 0) {
              error_log("Retrieve API Warning: Item $itemId has usageLimit but remainingUses is already zero or less. Status should be waste.");
              $newStatus = 'waste_depleted'; // Correct status if needed
              $newRemainingUses = 0; 
              if ($newStatus !== $item['status']) { $needsUpdate = true; } // Update if status changed
         } else {
             $newRemainingUses = $item['remainingUses'] - 1;
             $needsUpdate = true; // Usage changed, so update is needed
             error_log("Retrieve API: Decrementing remaining uses for $itemId to $newRemainingUses");
             if ($newRemainingUses <= 0) {
                 $newStatus = 'waste_depleted'; // Mark as waste if uses run out
                 error_log("Retrieve API: Item $itemId is now depleted. Setting status to waste_depleted.");
                 // Status changed, update is definitely needed (already covered by needsUpdate=true)
             }
         }

         // Prepare and execute update statement ONLY if needed
         if ($needsUpdate) {
             $updateSql = "UPDATE items SET remainingUses = :remainingUses, status = :status WHERE itemId = :itemId";
             $updateStmt = $db->prepare($updateSql);
             // Ensure correct type binding (integer or null)
             $updateStmt->bindValue(':remainingUses', $newRemainingUses, PDO::PARAM_INT); 
             $updateStmt->bindParam(':status', $newStatus);
             $updateStmt->bindParam(':itemId', $itemId);
             
             if (!$updateStmt->execute()) {
                  error_log("Retrieve API Error: Failed to execute item usage/status update for $itemId. Error: " . print_r($updateStmt->errorInfo(), true));
                 throw new Exception("Failed to update item usage/status."); // Trigger rollback
             }
              error_log("Retrieve API: Successfully updated item $itemId status/usage.");
         } else {
              error_log("Retrieve API: No status/usage update required for $itemId.");
         }
         
    } else {
         error_log("Retrieve API: Item $itemId has no usage limit. No usage update needed.");
    }
    
    // --- Log the Retrieval Action ---
    $logSql = "INSERT INTO logs (userId, actionType, itemId, detailsJson, timestamp) VALUES (:userId, 'retrieval', :itemId, :details, :timestamp)";
    $logStmt = $db->prepare($logSql);
    
    $detailsArray = [
        'retrievedBy' => $userId, // Include user if available
        'previousStatus' => $item['status'], 
        'previousRemainingUses' => $item['remainingUses'],
        'newStatus' => $newStatus,
        'newRemainingUses' => ($item['usageLimit'] !== null) ? $newRemainingUses : null // Only relevant if usage limited
    ];
    $detailsJson = json_encode($detailsArray);
    
    $logStmt->bindParam(':userId', $userId);
    $logStmt->bindParam(':itemId', $itemId);
    $logStmt->bindParam(':details', $detailsJson);
    $logStmt->bindParam(':timestamp', $timestamp); 

    if (!$logStmt->execute()) {
         error_log("Retrieve API Error: Failed to insert retrieval log for itemId: $itemId. Error: " . print_r($logStmt->errorInfo(), true));
         // Decide whether to rollback or just log the error. For now, just log.
         // throw new Exception("Failed to log retrieval action."); 
    } else {
          error_log("Retrieve API: Successfully logged retrieval for $itemId by user $userId.");
    }

    // If all database operations were successful, commit transaction
    $db->commit();
    $response['success'] = true;
    $response['message'] = "Item '$itemId' retrieved successfully.";
    if ($needsUpdate && $item['usageLimit'] !== null) { // Add details only if usage was updated
         $response['message'] .= " Remaining uses: $newRemainingUses.";
         if ($newStatus !== $item['status']) {
              $response['message'] .= " Status updated to '$newStatus'.";
         }
    }
    http_response_code(200);

} catch (Exception $e) {
    // Rollback transaction on any exception during the process
    if ($db->inTransaction()) { 
        error_log("Retrieve API: Rolling back transaction due to exception.");
        $db->rollBack(); 
    } 
    http_response_code(500);
    $response['message'] = 'Error processing retrieval: ' . $e->getMessage();
    error_log("Retrieve API Exception: " . $e->getMessage());
} finally {
    $db = null; // Ensure connection is closed
}
// --- End Core Logic ---

// --- Final Output ---
echo json_encode($response, JSON_PRETTY_PRINT); 
error_log("Retrieve API: Finished.");

?> // Ensure this is the absolute last line