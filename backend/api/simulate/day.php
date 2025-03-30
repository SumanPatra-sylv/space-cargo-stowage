<?php // backend/api/simulate/day.php

ini_set('display_errors', 1); 
error_reporting(E_ALL); 

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: POST, OPTIONS'); 
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// --- Database Connection ---
require_once __DIR__ . '/../../database.php'; // Up two levels
error_log("Simulate API: Attempting DB connection...");
$db = getDbConnection(); 
if ($db === null) { /* ... Handle DB connection error ... */ exit; }
error_log("Simulate API: DB connection successful.");
// --- End Database Connection --- 

// --- Response Structure ---
// Structure matches API spec
$response = [
    'success' => false, 
    'newDate' => '', // Will be updated
    'changes' => [
        'itemsUsed' => [],
        'itemsExpired' => [],
        'itemsDepletedToday' => [] 
    ],
    'message' => ''
];

// --- Handle Input ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { /* ... Handle 405 ... */ exit; }

$rawData = file_get_contents('php://input');
$requestData = json_decode($rawData, true); 

// Validate input: Need either numOfDays OR toTimestamp, and itemsToBeUsedPerDay
if ($requestData === null || 
    (!isset($requestData['numOfDays']) && !isset($requestData['toTimestamp'])) || 
    !isset($requestData['itemsToBeUsedPerDay']) || !is_array($requestData['itemsToBeUsedPerDay'])
   ) {
    http_response_code(400); 
    $response['message'] = 'Invalid JSON input. Required: (numOfDays OR toTimestamp) AND itemsToBeUsedPerDay array.';
    error_log("Simulate API: Invalid JSON structure: " . $rawData);
    echo json_encode($response); $db = null; exit;
}

// Determine simulation duration
$numOfDays = null;
$toTimestamp = null;
if (isset($requestData['numOfDays'])) {
    if (!is_numeric($requestData['numOfDays']) || $requestData['numOfDays'] <= 0 || floor($requestData['numOfDays']) != $requestData['numOfDays'] ) {
         http_response_code(400); $response['message'] = 'Invalid numOfDays. Must be a positive integer.'; echo json_encode($response); $db = null; exit;
    }
    $numOfDays = (int)$requestData['numOfDays'];
    error_log("Simulate API: Simulating $numOfDays days.");
} else { // toTimestamp must be set
    // TODO: Add validation for toTimestamp format (should be ISO 8601 compatible)
    $toTimestamp = $requestData['toTimestamp'];
     error_log("Simulate API: Simulating until $toTimestamp.");
     // We'll calculate numOfDays relative to current sim date later
}

$itemsUsedPerDayInput = $requestData['itemsToBeUsedPerDay']; // Array of {itemId or name}
$userId = $requestData['userId'] ?? 'Simulation'; // Optional user for logging usage

// --- End Input Handling ---
// --- Simulation Date Management ---
$dateFile = __DIR__ . '/../simulation_date.txt'; // Store date in backend/api folder
$currentSimDateStr = '';
$dateFormat = 'Y-m-d'; // Use consistent date format

// Read current simulation date
if (file_exists($dateFile)) {
    $currentSimDateStr = trim(file_get_contents($dateFile));
    error_log("Simulate API: Read current sim date: $currentSimDateStr");
} else {
     error_log("Simulate API: Date file not found. Initializing simulation date.");
     // Initialize if file doesn't exist (e.g., to current real date or a fixed start date)
     $currentSimDateStr = date($dateFormat); 
     file_put_contents($dateFile, $currentSimDateStr); 
}

// Validate date format read from file
$currentSimDate = DateTime::createFromFormat($dateFormat, $currentSimDateStr);
if (!$currentSimDate) {
    error_log("Simulate API FATAL: Invalid date format '$currentSimDateStr' in $dateFile. Resetting to today.");
    // Handle error - maybe reset the file to today's date?
    $currentSimDate = new DateTime(); // Use current real time object
    $currentSimDateStr = $currentSimDate->format($dateFormat);
    file_put_contents($dateFile, $currentSimDateStr); 
    // Or maybe exit with error? For hackathon, resetting might be okay.
    // http_response_code(500); $response['message'] = 'Internal error: Invalid simulation date stored.'; echo json_encode($response); $db = null; exit;
}
$currentSimDate->setTime(0,0,0); // Ensure we work with dates only, no time component

// Calculate end date if toTimestamp was given
if ($toTimestamp !== null) {
    try {
        $endDate = new DateTime($toTimestamp);
        $endDate->setTime(0,0,0);
        if ($endDate <= $currentSimDate) {
             http_response_code(400); $response['message'] = 'toTimestamp must be after the current simulation date (' . $currentSimDateStr . ').'; echo json_encode($response); $db = null; exit;
        }
        // Calculate number of days difference
        $dateDiff = $currentSimDate->diff($endDate);
        $numOfDays = $dateDiff->days; 
        error_log("Simulate API: Calculated $numOfDays days until $toTimestamp.");
    } catch (Exception $e) {
         http_response_code(400); $response['message'] = 'Invalid toTimestamp format.'; echo json_encode($response); $db = null; exit;
    }
}

if ($numOfDays === null || $numOfDays <= 0) {
     http_response_code(400); $response['message'] = 'Invalid simulation duration calculated.'; echo json_encode($response); $db = null; exit;
}
// --- End Date Management ---
// --- Simulation Loop ---
$db->beginTransaction(); // Transaction for all updates within simulation period
try {
    $simulationEndDate = clone $currentSimDate; // Keep track of final date

    for ($day = 1; $day <= $numOfDays; $day++) {
        // Advance simulation date by one day
        $currentSimDate->modify('+1 day');
        $currentSimDateStr = $currentSimDate->format($dateFormat);
        $simulationEndDate = clone $currentSimDate; // Update end date
        error_log("--- Simulating Day $day: $currentSimDateStr ---");

        // --- 1. Process Item Usage for Today ---
        foreach ($itemsUsedPerDayInput as $itemIdentifier) {
            $itemIdUsed = $itemIdentifier['itemId'] ?? null;
            $itemNameUsed = $itemIdentifier['name'] ?? null;
            $itemFound = false;

            if (empty($itemIdUsed) && empty($itemNameUsed)) continue; // Skip empty identifiers

            // Find the specific item to use (prioritize non-waste, maybe soonest expiry?)
            // For simplicity now, just find the first available stowed item matching ID or name
            $findSql = "SELECT itemId, name, usageLimit, remainingUses, status FROM items 
                        WHERE status = 'stowed' AND ";
            if (!empty($itemIdUsed)) {
                $findSql .= "itemId = :id LIMIT 1";
                $findStmt = $db->prepare($findSql);
                $findStmt->bindParam(':id', $itemIdUsed);
            } else {
                $findSql .= "name = :name LIMIT 1";
                 $findStmt = $db->prepare($findSql);
                $findStmt->bindParam(':name', $itemNameUsed);
            }
            $findStmt->execute();
            $itemToUse = $findStmt->fetch(PDO::FETCH_ASSOC);

            if ($itemToUse) {
                $itemId = $itemToUse['itemId'];
                error_log("Simulate Day $day: Processing usage for item $itemId.");
                $itemFound = true;
                $needsUsageUpdate = false;
                $newRemainingUses = $itemToUse['remainingUses'];
                $newStatus = $itemToUse['status'];

                if ($itemToUse['usageLimit'] !== null && is_numeric($itemToUse['usageLimit'])) {
                     if ($itemToUse['remainingUses'] === null || !is_numeric($itemToUse['remainingUses']) || $itemToUse['remainingUses'] <= 0) {
                          // Already depleted or invalid state
                          error_log("Simulate Day $day Warning: Item $itemId used but remainingUses is already <= 0 or invalid.");
                          if ($itemToUse['status'] === 'stowed') { // Correct status if needed
                              $newStatus = 'waste_depleted';
                              $needsUsageUpdate = true; // Need to update status
                          }
                          $newRemainingUses = 0;
                     } else {
                         $newRemainingUses = $itemToUse['remainingUses'] - 1;
                         $needsUsageUpdate = true;
                         if ($newRemainingUses <= 0) {
                             $newStatus = 'waste_depleted';
                             error_log("Simulate Day $day: Item $itemId depleted.");
                             // Add to today's depleted list for response
                             $response['changes']['itemsDepletedToday'][] = ['itemId' => $itemId, 'name' => $itemToUse['name']];
                         }
                     }
                     
                     // Update DB if needed
                     if ($needsUsageUpdate) {
                        $updateUsageSql = "UPDATE items SET remainingUses = :remUses, status = :status WHERE itemId = :id AND status = 'stowed'"; // Check status to avoid race condition
                        $updateStmt = $db->prepare($updateUsageSql);
                        $updateStmt->bindValue(':remUses', $newRemainingUses, PDO::PARAM_INT);
                        $updateStmt->bindParam(':status', $newStatus);
                        $updateStmt->bindParam(':id', $itemId);
                        if (!$updateStmt->execute()) { error_log("Simulate Day $day Error: Failed to update usage for $itemId"); }
                        else { error_log("Simulate Day $day: Updated $itemId, Remaining: $newRemainingUses, Status: $newStatus"); }
                     }
                } else {
                    error_log("Simulate Day $day: Item $itemId used, but has no usage limit.");
                }
                // Add to used items list for response
                $response['changes']['itemsUsed'][] = ['itemId' => $itemId, 'name' => $itemToUse['name'], 'remainingUses' => ($itemToUse['usageLimit'] !== null) ? $newRemainingUses : null];
            
            } else { // Item not found or none available
                 error_log("Simulate Day $day Warning: Could not find available stowed item for identifier: " . ($itemIdUsed ?? $itemNameUsed));
                 // Optionally add to an error list in the response
            }

             // Log individual usage? Could get verbose. Maybe log summary at end.
             // $logSql = "INSERT INTO logs ... actionType='usage_simulated' ...";

        } // End foreach item used today

        // --- 2. Process Expiry for Today ---
        $expiryCheckSql = "SELECT itemId, name FROM items WHERE status = 'stowed' AND expiryDate IS NOT NULL AND DATE(expiryDate) < :currentDateStr";
        $expiryStmt = $db->prepare($expiryCheckSql);
        $expiryStmt->bindParam(':currentDateStr', $currentSimDateStr);
        $expiryStmt->execute();
        $newlyExpiredItems = $expiryStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($newlyExpiredItems)) {
            $expiredIds = array_column($newlyExpiredItems, 'itemId');
            error_log("Simulate Day $day: Found " . count($expiredIds) . " newly expired items: " . implode(', ', $expiredIds));
            
            // Add to response list
            foreach($newlyExpiredItems as $expItem) {
                 $response['changes']['itemsExpired'][] = ['itemId' => $expItem['itemId'], 'name' => $expItem['name']];
            }

            // Update status in DB (using IN clause for efficiency)
            $placeholders = rtrim(str_repeat('?,', count($expiredIds)), ',');
            $updateExpirySql = "UPDATE items SET status = 'waste_expired' WHERE itemId IN ($placeholders) AND status = 'stowed'";
            $updateExpiryStmt = $db->prepare($updateExpirySql);
            if (!$updateExpiryStmt->execute($expiredIds)) {
                 error_log("Simulate Day $day Error: Failed to update status for expired items.");
            } else {
                 error_log("Simulate Day $day: Updated status for " . $updateExpiryStmt->rowCount() . " expired items.");
            }
        } else {
             error_log("Simulate Day $day: No newly expired items found.");
        }

    } // End for loop through days

    // --- Update the stored simulation date ---
    $newDateStr = $simulationEndDate->format($dateFormat);
    if (file_put_contents($dateFile, $newDateStr) === false) {
         error_log("Simulate API CRITICAL ERROR: Failed to write new simulation date $newDateStr to $dateFile");
         // Don't commit if we can't save the date? Or commit anyway? For now, commit.
         $response['message'] .= " WARNING: Failed to save simulation end date.";
    } else {
         error_log("Simulate API: Successfully updated simulation date file to $newDateStr");
    }
    $response['newDate'] = $simulationEndDate->format(DATE_ISO8601); // Return ISO format


    // Commit all changes for the simulation period
    $db->commit();
    $response['success'] = true;
    if (empty($response['message'])) { // Set default success message if no warning occurred
        $response['message'] = "Simulation completed successfully.";
    }
    http_response_code(200);

} catch (Exception $e) {
    if ($db->inTransaction()) { $db->rollBack(); } 
    http_response_code(500);
    $response['message'] = 'Error during simulation: ' . $e->getMessage();
    error_log("Simulate API Exception: " . $e->getMessage());
    // Clear changes array on error
    $response['changes'] = ['itemsUsed' => [], 'itemsExpired' => [], 'itemsDepletedToday' => []]; 
} finally {
    $db = null; 
}
// --- End Simulation Loop ---
// --- Final Output ---
echo json_encode($response, JSON_PRETTY_PRINT); 
error_log("Simulate API: Finished.");
?>