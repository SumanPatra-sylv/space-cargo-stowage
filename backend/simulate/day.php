<?php // backend/simulate/day.php (Corrected V4 - 'expired' retains location, uses 'consumed')

/**
 * Simulates the passage of time, updating item usage and status.
 * - Sets status to 'expired' if expiryDate is passed (retains location info).
 * - Sets status to 'consumed' if remainingUses reaches 0 (and not already expired).
 *
 * EXPECTS JSON POST DATA: (Same as before)
 * RESPONDS with JSON:
 * {
 *   "success": true|false,
 *   "newDate": "YYYY-MM-DD",
 *   "changes": {
 *     "used": [ {"itemId": "ID", "name": "Name", "quantityUsed": Q, "remainingUses": R } ],
 *     "expired": [ {"itemId": "ID", "name": "Name" } ],
 *     "consumed": [ {"itemId": "ID", "name": "Name" } ], // Renamed from depleted
 *     "unusable": [ {"itemId": "ID", "name": "Name", "reason": "expired|consumed" } ]
 *   },
 *   "message": "Status message"
 * }
 *
 * PREREQUISITE: The database schema for 'items' MUST allow 'expired' AND 'consumed'
 *              in the status CHECK constraint.
 */

// --- Error Reporting and Headers ---
ini_set('display_errors', 0);
ini_set('log_errors', 1);
if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }

require_once __DIR__ . '/../database.php';

// --- Globals and Response Init ---
$db = null;
$response = [
    'success' => false, 'newDate' => '',
    'changes' => [ 'used' => [], 'expired' => [], 'consumed' => [], 'unusable' => [] ], // Key 'consumed' added
    'message' => ''
];
$simulationEndDate = null;

// --- Database Connection (Same as previous version) ---
error_log("Simulate API: Attempting DB connection...");
try {
    $db = getDbConnection();
    if ($db === null) { throw new Exception("Database connection failed. Check logs.", 503); }
    error_log("Simulate API: DB connection successful.");
} catch (Exception $e) {
    http_response_code(503); $response['message'] = "FATAL: Could not connect to the database. " . $e->getMessage();
    error_log("Simulate API Error: " . $response['message']); echo json_encode($response); exit;
}

// --- Input Handling & Validation (Same as previous version) ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); $response['message'] = 'Invalid request method. Only POST is accepted.';
    echo json_encode($response); exit;
}
$rawData = file_get_contents('php://input'); $requestData = json_decode($rawData, true);
if ($requestData === null || (!isset($requestData['numOfDays']) && !isset($requestData['toTimestamp'])) || !isset($requestData['itemsToBeUsedPerDay'])) {
    http_response_code(400); $response['message'] = 'Invalid input: Missing required fields (numOfDays/toTimestamp, itemsToBeUsedPerDay).';
    error_log("Simulate API Error: " . $response['message'] . " Raw Data: " . $rawData); echo json_encode($response); exit;
}
if (!is_array($requestData['itemsToBeUsedPerDay'])) {
    http_response_code(400); $response['message'] = 'Invalid input: itemsToBeUsedPerDay must be an array.';
    error_log("Simulate API Error: " . $response['message']); echo json_encode($response); exit;
}
foreach ($requestData['itemsToBeUsedPerDay'] as $i => $itemUse) {
    if (!is_array($itemUse) || !isset($itemUse['itemId']) || empty(trim($itemUse['itemId'])) || !isset($itemUse['quantity']) || !is_numeric($itemUse['quantity']) || $itemUse['quantity'] < 0 || floor($itemUse['quantity']) != $itemUse['quantity']) {
        http_response_code(400); $response['message'] = "Invalid input: Invalid structure or values in itemsToBeUsedPerDay at index $i.";
        error_log("Simulate API Error: " . $response['message'] . " Item Data: " . json_encode($itemUse)); echo json_encode($response); exit;
    }
}
$itemsUsedPerDayInput = $requestData['itemsToBeUsedPerDay'];
$userId = isset($requestData['userId']) && !empty(trim($requestData['userId'])) ? trim($requestData['userId']) : 'System_Simulation';

// --- Simulation Date Management (Same as previous version) ---
$dateFile = __DIR__ . '/../simulation_date.txt'; $dateFormat = 'Y-m-d'; $currentSimDateStr = '';
try {
    if (file_exists($dateFile) && is_readable($dateFile)) {
        $currentSimDateStr = trim(file_get_contents($dateFile));
        if (empty($currentSimDateStr)) { throw new Exception("Date file is empty."); }
    } else { throw new Exception("Date file missing."); }
    $currentSimDate = DateTime::createFromFormat($dateFormat, $currentSimDateStr);
    if (!$currentSimDate) { throw new Exception("Invalid date format '$currentSimDateStr' in date file."); }
    $currentSimDate->setTime(0,0,0); $simulationEndDate = clone $currentSimDate;
    error_log("Simulate API: Current Simulation Date: " . $currentSimDate->format($dateFormat));
} catch (Exception $e) {
    error_log("Simulate API Warning: Error reading simulation date file ($dateFile): " . $e->getMessage() . ". Attempting to initialize.");
    try {
        $currentSimDate = new DateTime(); $currentSimDate->setTime(0,0,0);
        $currentSimDateStr = $currentSimDate->format($dateFormat);
        if (@file_put_contents($dateFile, $currentSimDateStr) === false) {
            throw new Exception("Failed to write initial date to $dateFile. Check permissions.");
        }
        $simulationEndDate = clone $currentSimDate;
        error_log("Simulate API: Initialized simulation date file to $currentSimDateStr.");
    } catch (Exception $initEx) {
        http_response_code(500); $response['message'] = "FATAL: Cannot read or initialize simulation date file. " . $initEx->getMessage();
        error_log("Simulate API Error: " . $response['message']); echo json_encode($response); exit;
    }
}

// --- Calculate Simulation Duration (Same as previous version) ---
$numOfDays = null; $toTimestamp = null;
try {
    if (isset($requestData['numOfDays']) && !isset($requestData['toTimestamp'])) {
        if (!is_numeric($requestData['numOfDays']) || $requestData['numOfDays'] <= 0 || floor($requestData['numOfDays']) != $requestData['numOfDays'] ) {
             throw new Exception("Invalid numOfDays value: must be a positive integer.");
        }
        $numOfDays = (int)$requestData['numOfDays']; error_log("Simulate API: Simulating $numOfDays days.");
    } elseif (isset($requestData['toTimestamp']) && !isset($requestData['numOfDays'])) {
        $toTimestamp = $requestData['toTimestamp'];
        $endDate = DateTime::createFromFormat($dateFormat, $toTimestamp);
        if (!$endDate) { throw new Exception("Invalid toTimestamp format: Use YYYY-MM-DD."); }
        $endDate->setTime(0,0,0);
        if ($endDate <= $currentSimDate) { throw new Exception("toTimestamp must be after the current simulation date (" . $currentSimDate->format($dateFormat) . ")."); }
        $interval = $currentSimDate->diff($endDate); $numOfDays = $interval->days;
        if ($numOfDays <= 0) { throw new Exception("Calculated duration is zero or negative days."); }
         error_log("Simulate API: Simulating up to $toTimestamp ($numOfDays days).");
    } else { throw new Exception("Provide either numOfDays OR toTimestamp, not both or neither."); }
} catch (Exception $e) {
    http_response_code(400); $response['message'] = 'Invalid input: ' . $e->getMessage();
    error_log("Simulate API Error: " . $response['message']); echo json_encode($response); exit;
}

// --- Simulation Loop ---
$db->beginTransaction();
try {
    for ($day = 1; $day <= $numOfDays; $day++) {
        $currentSimDate->modify('+1 day');
        $currentSimDateStr = $currentSimDate->format($dateFormat);
        $simulationEndDate = clone $currentSimDate;
        error_log("--- Simulating Day $day: $currentSimDateStr ---");

        $itemsMadeUnusableToday = [];

        // --- 1. Process Expiry FIRST ---
        // Items that expire keep their location information but become 'expired'.
        error_log("Simulate Day $day: Checking expiry on/before $currentSimDateStr");
        $expiryCheckSql = "SELECT itemId, name
                           FROM items
                           WHERE status = 'stowed' -- Only currently usable items expire this way
                             AND expiryDate IS NOT NULL
                             AND DATE(expiryDate) <= DATE(:currentDateStr)";
        $expiryStmt = $db->prepare($expiryCheckSql);
        $expiryStmt->bindParam(':currentDateStr', $currentSimDateStr);
        $expiryStmt->execute();
        $newlyExpiredItems = $expiryStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($newlyExpiredItems)) {
            $expiredIdsToUpdate = array_column($newlyExpiredItems, 'itemId');
            error_log("Simulate Day $day: Found 'stowed' items to mark as 'expired': " . implode(', ', $expiredIdsToUpdate));
            $placeholders = rtrim(str_repeat('?,', count($expiredIdsToUpdate)), ',');

            // *** MODIFIED SQL: Only update status and lastUpdated ***
            $updateExpirySql = "UPDATE items
                                SET status = 'expired',
                                    lastUpdated = CURRENT_TIMESTAMP
                                    -- Removed containerId, positionX, etc. setters
                                WHERE itemId IN ($placeholders)
                                  AND status = 'stowed'"; // Ensure still stowed
            $updateExpiryStmt = $db->prepare($updateExpirySql);

            if ($updateExpiryStmt->execute($expiredIdsToUpdate)) {
                $updatedCount = $updateExpiryStmt->rowCount();
                error_log("Simulate Day $day: Marked $updatedCount items as 'expired' (retaining location).");
                if ($updatedCount > 0) {
                    foreach ($newlyExpiredItems as $expItem) {
                        $response['changes']['expired'][] = ['itemId' => $expItem['itemId'], 'name' => $expItem['name']];
                        $response['changes']['unusable'][] = ['itemId' => $expItem['itemId'], 'name' => $expItem['name'], 'reason' => 'expired'];
                        $itemsMadeUnusableToday[$expItem['itemId']] = 'expired';
                    }
                }
            } else {
                error_log("Simulate Day $day Error: Failed to execute update for 'expired' items.");
            }
        } else {
            error_log("Simulate Day $day: No 'stowed' items became 'expired' today.");
        }


        // --- 2. Process Item Usage (for items NOT already expired today) ---
        // Items that run out of uses become 'consumed'.
        error_log("Simulate Day $day: Checking items to use. Input array content: " . json_encode($itemsUsedPerDayInput));
        foreach ($itemsUsedPerDayInput as $itemUse) {
            $itemIdToUse = $itemUse['itemId'];
            $quantityToUse = max(1, (int)$itemUse['quantity']);

            // Skip if this item was already marked 'expired' today
            if (isset($itemsMadeUnusableToday[$itemIdToUse]) && $itemsMadeUnusableToday[$itemIdToUse] === 'expired') {
                error_log("Simulate Day $day: Item $itemIdToUse was already marked 'expired' today, skipping usage processing.");
                continue;
            }

            error_log("Simulate Day $day: Processing usage for requested item ID: $itemIdToUse (Quantity: $quantityToUse)");

            $findSql = "SELECT itemId, name, remainingUses, status, expiryDate
                        FROM items
                        WHERE itemId = :id
                          AND status = 'stowed' -- Must be stowed to be used
                          AND (remainingUses > 0 OR remainingUses IS NULL)
                        ORDER BY CASE WHEN expiryDate IS NULL THEN 0 ELSE 1 END, DATE(expiryDate) ASC
                        LIMIT 1";
            $findStmt = $db->prepare($findSql);
            $findStmt->bindParam(':id', $itemIdToUse);
            $findStmt->execute();
            $itemToUse = $findStmt->fetch(PDO::FETCH_ASSOC);

            if ($itemToUse) {
                $itemId = $itemToUse['itemId'];
                $initialRemainingUses = $itemToUse['remainingUses'];
                $usesActuallyConsumed = $quantityToUse;
                $newRemainingUses = $initialRemainingUses;
                $newStatus = $itemToUse['status']; // 'stowed'
                $unusableReason = null;

                if ($initialRemainingUses !== null) {
                    $usesActuallyConsumed = min($quantityToUse, $initialRemainingUses);
                    $newRemainingUses = $initialRemainingUses - $usesActuallyConsumed;

                    // Check for depletion - set status to 'consumed'
                    if ($newRemainingUses <= 0) {
                        $newRemainingUses = 0;
                        $newStatus = 'consumed'; // *** CHANGED STATUS ***
                        $unusableReason = 'consumed'; // *** CHANGED REASON ***
                        $itemsMadeUnusableToday[$itemId] = 'consumed'; // Track with new status
                        error_log("Simulate Day $day: Item $itemId uses depleted. Setting status to 'consumed'."); // *** UPDATED LOG ***
                    }
                } else {
                    $usesActuallyConsumed = $quantityToUse;
                    $newRemainingUses = null;
                }

                if ($newRemainingUses !== $initialRemainingUses || $newStatus !== $itemToUse['status']) {
                    $updateUsageSql = "UPDATE items
                                       SET remainingUses = :remUses, status = :status, lastUpdated = CURRENT_TIMESTAMP
                                       WHERE itemId = :id AND status = 'stowed'";
                    $updateStmt = $db->prepare($updateUsageSql);
                    $updateStmt->bindValue(':remUses', $newRemainingUses, ($newRemainingUses === null) ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $updateStmt->bindParam(':status', $newStatus); // Will be 'stowed' or 'consumed'
                    $updateStmt->bindParam(':id', $itemId);

                    if ($updateStmt->execute() && $updateStmt->rowCount() > 0) {
                        error_log("Simulate Day $day: Updated $itemId, Remaining: " . ($newRemainingUses ?? 'Unlimited') . ", Status: $newStatus");
                        $response['changes']['used'][] = [
                            'itemId' => $itemId, 'name' => $itemToUse['name'],
                            'quantityUsed' => $usesActuallyConsumed, 'remainingUses' => $newRemainingUses
                        ];
                        // If status changed to 'consumed'
                        if ($unusableReason === 'consumed') {
                            // Add to 'consumed' specific list and generic 'unusable' list
                            $response['changes']['consumed'][] = ['itemId' => $itemId, 'name' => $itemToUse['name']]; // *** RENAMED KEY ***
                            $response['changes']['unusable'][] = ['itemId' => $itemId, 'name' => $itemToUse['name'], 'reason' => $unusableReason];
                        }
                    } else {
                        error_log("Simulate Day $day Error/Warning: Failed to update usage for $itemId or item status changed concurrently.");
                    }
                } else {
                     error_log("Simulate Day $day: Item $itemId found (unlimited uses or no change needed), no DB update for usage.");
                     $response['changes']['used'][] = [
                         'itemId' => $itemId, 'name' => $itemToUse['name'],
                         'quantityUsed' => $usesActuallyConsumed, 'remainingUses' => $newRemainingUses
                     ];
                }
            } else {
                error_log("Simulate Day $day Warning: Could not find 'stowed' item for itemId: " . $itemIdToUse . " (or it's already not stowed).");
            }
        } // End foreach item used today

    } // End main simulation loop

    // --- Update simulation date file (Same as previous version) ---
    $newDateStr = $simulationEndDate->format($dateFormat);
    if (@file_put_contents($dateFile, $newDateStr) === false) {
        $response['message'] .= " WARNING: Failed to save simulation end date to $dateFile.";
        error_log("Simulate API Warning: Failed to write simulation end date '$newDateStr' to '$dateFile'. Check permissions.");
    } else { error_log("Simulate API: Updated simulation date file to $newDateStr"); }
    $response['newDate'] = $newDateStr;

    // --- Commit Transaction (Same as previous version) ---
    $db->commit();
    $response['success'] = true;
    if (empty($response['message'])) { $response['message'] = "Simulation completed successfully for $numOfDays day(s)."; }
    http_response_code(200); error_log("Simulate API: Transaction committed.");

} catch (PDOException $e) { // --- Rollback on SQL Error (Same as previous version) ---
    if ($db && $db->inTransaction()) { $db->rollBack(); error_log("Simulate API: Transaction rolled back due to PDOException."); }
    http_response_code(500); $response['success'] = false;
    $response['message'] = 'Error during simulation: Database operation failed. ' . $e->getMessage();
    error_log("Simulate API PDOException: " . $e->getMessage() . " (Code: " . $e->getCode() . ") Trace: " . $e->getTraceAsString());
    $response['changes'] = ['used' => [], 'expired' => [], 'consumed' => [], 'unusable' => []]; // Updated key
} catch (Exception $e) { // --- Rollback on General Error (Same as previous version) ---
    if ($db && $db->inTransaction()) { $db->rollBack(); error_log("Simulate API: Transaction rolled back due to general Exception."); }
    $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($statusCode); $response['success'] = false;
    $response['message'] = 'Error during simulation: ' . $e->getMessage();
    error_log("Simulate API Exception: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
    $response['changes'] = ['used' => [], 'expired' => [], 'consumed' => [], 'unusable' => []]; // Updated key
} finally { // --- Close Connection (Same as previous version) ---
    if ($db !== null) { $db = null; error_log("Simulate API: Database connection closed."); }
}

// --- Final Output (Same as previous version) ---
if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
error_log("Simulate API: Finished. Response sent.");

?>