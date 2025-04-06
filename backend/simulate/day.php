<?php // backend/simulate/day.php (Corrected - Added Debug Log Before Usage Loop)

/* --- REMOVED/COMMENTED OUT - Handled by index.php --- */

require_once __DIR__ . '/../database.php';

$db = null;
$response = [
    'success' => false, 'newDate' => '',
    'changes' => [ 'used' => [], 'expired' => [], 'depleted' => [], 'set_to_waste' => [] ],
    'message' => ''
];

error_log("Simulate API: Attempting DB connection...");
try {
    $db = getDbConnection();
    if ($db === null) { throw new Exception("Database connection failed.", 503); }
    error_log("Simulate API: DB connection successful.");
} catch (Exception $e) { /* ... handle DB connection error ... */ exit; }

// --- Input Handling ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { /* ... handle method error ... */ exit; }
$rawData = file_get_contents('php://input');
$requestData = json_decode($rawData, true);
// --- Stricter Input Validation ---
if ($requestData === null) { /* ... handle invalid json ... */ exit; }
if ((!isset($requestData['numOfDays']) && !isset($requestData['toTimestamp'])) || !isset($requestData['itemsToBeUsedPerDay'])) { /* ... handle missing fields ... */ exit; }
if (!is_array($requestData['itemsToBeUsedPerDay'])) { /* ... handle non-array items ... */ exit; }
foreach ($requestData['itemsToBeUsedPerDay'] as $itemUse) {
    if (!is_array($itemUse) || !isset($itemUse['itemId']) || !isset($itemUse['quantity']) || !is_numeric($itemUse['quantity']) || $itemUse['quantity'] < 0) { /* ... handle invalid item structure ... */ exit; }
}
$itemsUsedPerDayInput = $requestData['itemsToBeUsedPerDay'];
$userId = $requestData['userId'] ?? 'Simulation';
// --- End Input Validation ---

// --- Simulation Date Management ---
$dateFile = __DIR__ . '/../simulation_date.txt'; $dateFormat = 'Y-m-d'; $currentSimDateStr = '';
if (file_exists($dateFile) && is_readable($dateFile)) { $currentSimDateStr = trim(file_get_contents($dateFile)); } else { $currentSimDateStr = date($dateFormat); file_put_contents($dateFile, $currentSimDateStr); }
$currentSimDate = DateTime::createFromFormat($dateFormat, $currentSimDateStr);
if (!$currentSimDate) { $currentSimDate = new DateTime(); $currentSimDateStr = $currentSimDate->format($dateFormat); file_put_contents($dateFile, $currentSimDateStr); }
$currentSimDate->setTime(0,0,0); error_log("Simulate API: Current Simulation Date: $currentSimDateStr");
// --- End Date Management ---

// --- Calculate Simulation Duration ---
$numOfDays = null; $toTimestamp = null;
if (isset($requestData['numOfDays']) && !isset($requestData['toTimestamp'])) {
    if (!is_numeric($requestData['numOfDays']) || $requestData['numOfDays'] <= 0 || floor($requestData['numOfDays']) != $requestData['numOfDays'] ) { /* ... handle invalid numDays ... */ exit; }
    $numOfDays = (int)$requestData['numOfDays']; error_log("Simulate API: Simulating $numOfDays days.");
} elseif (isset($requestData['toTimestamp']) && !isset($requestData['numOfDays'])) {
    $toTimestamp = $requestData['toTimestamp']; try { /* ... calculate $numOfDays ... */ } catch (Exception $e) { /* ... handle invalid toTimestamp ... */ exit; }
} else { /* ... handle both/neither error ... */ exit; }
if ($numOfDays === null || $numOfDays <= 0) { /* ... handle invalid duration ... */ exit; }
// --- End Duration Calculation ---

// --- Simulation Loop ---
$db->beginTransaction();
try {
    $simulationEndDate = clone $currentSimDate;

    for ($day = 1; $day <= $numOfDays; $day++) {
        $currentSimDate->modify('+1 day');
        $currentSimDateStr = $currentSimDate->format($dateFormat);
        $simulationEndDate = clone $currentSimDate;
        error_log("--- Simulating Day $day: $currentSimDateStr ---");

        // --- 1. Process Item Usage ---

        // <<< ADD DEBUG LOG HERE >>>
        error_log("Simulate Day $day: Checking items to use. Input array content: " . json_encode($itemsUsedPerDayInput));
        // <<< END DEBUG LOG >>>

        foreach ($itemsUsedPerDayInput as $itemUse) {
            // --- Add this log back INSIDE the loop to confirm entry ---
            error_log("Simulate Day $day: Processing usage for requested item ID: " . ($itemUse['itemId'] ?? 'N/A'));
            // ---

            // ... (Rest of the usage logic: find item, update status/uses) ...
            $itemIdToUse = $itemUse['itemId']; $quantityToUse = max(1, (int)($itemUse['quantity'] ?? 1));
            $findSql = "SELECT itemId, name, remainingUses, status FROM items WHERE itemId = :id AND status = 'stowed' AND remainingUses > 0 LIMIT 1";
            $findStmt = $db->prepare($findSql); $findStmt->bindParam(':id', $itemIdToUse); $findStmt->execute(); $itemToUse = $findStmt->fetch(PDO::FETCH_ASSOC);
            if ($itemToUse) {
                $itemId = $itemToUse['itemId']; $usesActuallyConsumed = min($quantityToUse, $itemToUse['remainingUses']); $newRemainingUses = $itemToUse['remainingUses'] - $usesActuallyConsumed; $newStatus = $itemToUse['status']; $wasteReason = null;
                if ($newRemainingUses <= 0) { $newRemainingUses = 0; $newStatus = 'waste_depleted'; $wasteReason = 'depleted'; }
                if ($newStatus != $itemToUse['status'] || $newRemainingUses != $itemToUse['remainingUses']) {
                    $updateUsageSql = "UPDATE items SET remainingUses = :remUses, status = :status, lastUpdated = datetime('now', 'localtime') WHERE itemId = :id AND status = 'stowed'";
                    $updateStmt = $db->prepare($updateUsageSql); $updateStmt->bindValue(':remUses', $newRemainingUses, PDO::PARAM_INT); $updateStmt->bindParam(':status', $newStatus); $updateStmt->bindParam(':id', $itemId);
                    if ($updateStmt->execute() && $updateStmt->rowCount() > 0) {
                        error_log("Simulate Day $day: Updated $itemId, Remaining: $newRemainingUses, Status: $newStatus");
                        $response['changes']['used'][] = ['itemId' => $itemId, 'name' => $itemToUse['name'], 'quantityUsed' => $usesActuallyConsumed, 'remainingUses' => $newRemainingUses];
                        if ($wasteReason !== null) {
                            $response['changes']['depleted'][] = ['itemId' => $itemId, 'name' => $itemToUse['name']];
                            $response['changes']['set_to_waste'][] = ['itemId' => $itemId, 'name' => $itemToUse['name'], 'reason' => $wasteReason];
                        }
                    } else { error_log("Simulate Day $day Error/Warning: Failed to update usage for $itemId or item status changed concurrently."); }
                }
            } else { error_log("Simulate Day $day Warning: Could not find available stowed item for itemId: " . $itemIdToUse); }
        } // End foreach item used today

        // --- 2. Process Expiry ---
        // (Keep the corrected logic from previous version)
        error_log("Simulate Day $day: Checking expiry on/before $currentSimDateStr");
        // ... (expiry check SQL and update logic) ...
        $expiryCheckSql = "SELECT itemId, name FROM items WHERE status = 'stowed' AND expiryDate IS NOT NULL AND DATE(expiryDate) <= DATE(:currentDateStr)";
        $expiryStmt = $db->prepare($expiryCheckSql); $expiryStmt->bindParam(':currentDateStr', $currentSimDateStr); $expiryStmt->execute(); $newlyExpiredItems = $expiryStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($newlyExpiredItems)) {
            $expiredIds = array_column($newlyExpiredItems, 'itemId'); error_log("Simulate Day $day: Found expired: " . implode(', ', $expiredIds));
            $placeholders = rtrim(str_repeat('?,', count($expiredIds)), ',');
            $updateExpirySql = "UPDATE items SET status = 'waste_expired', lastUpdated = datetime('now', 'localtime'), containerId = NULL, positionX = NULL, positionY = NULL, positionZ = NULL, placedDimensionW = NULL, placedDimensionD = NULL, placedDimensionH = NULL WHERE itemId IN ($placeholders) AND status = 'stowed'";
            $updateExpiryStmt = $db->prepare($updateExpirySql);
            if ($updateExpiryStmt->execute($expiredIds)) {
                $updatedCount = $updateExpiryStmt->rowCount(); error_log("Simulate Day $day: Marked $updatedCount items as waste_expired.");
                if ($updatedCount > 0) {
                    foreach($newlyExpiredItems as $expItem) {
                        $isAlreadyWaste = false; foreach($response['changes']['set_to_waste'] as $waste) { if ($waste['itemId'] === $expItem['itemId']) {$isAlreadyWaste = true; break;} }
                        if (!$isAlreadyWaste) {
                            $response['changes']['expired'][] = ['itemId' => $expItem['itemId'], 'name' => $expItem['name']];
                            $response['changes']['set_to_waste'][] = ['itemId' => $expItem['itemId'], 'name' => $expItem['name'], 'reason' => 'expired'];
                        }
                    }
                }
            } else { error_log("Simulate Day $day Error: Failed to update status for expired items."); }
        } else { error_log("Simulate Day $day: No items expired."); }


    } // End for loop

    // Update simulation date file
    // ... (keep existing logic) ...
    $newDateStr = $simulationEndDate->format($dateFormat);
    if (@file_put_contents($dateFile, $newDateStr) === false) { $response['message'] .= " WARNING: Failed to save simulation end date."; } else { error_log("Simulate API: Updated simulation date file to $newDateStr"); }
    $response['newDate'] = $newDateStr;

    // Commit
    $db->commit();
    $response['success'] = true;
    if (empty($response['message'])) { $response['message'] = "Simulation completed successfully."; }
    http_response_code(200);

} catch (Exception $e) {
    // Rollback and handle errors
    // ... (keep existing catch logic) ...
    if ($db && $db->inTransaction()) { $db->rollBack(); error_log("Simulate API: Transaction rolled back."); }
    $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500; http_response_code($statusCode);
    $response['success'] = false; $response['message'] = 'Error during simulation: ' . $e->getMessage(); error_log("Simulate API Exception: " . $e->getMessage()); $response['changes'] = ['used' => [], 'expired' => [], 'depleted' => [], 'set_to_waste' => []];
    if (!headers_sent()) { echo json_encode($response); } $db = null; exit;
} finally {
    // ... (keep existing finally logic) ...
    if ($db !== null) { $db = null; }
}
// --- End Simulation Loop ---

// --- Final Output ---
// ... (keep existing final output logic) ...
if ($response['success']) {
    if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
    echo json_encode($response);
}
error_log("Simulate API: Finished.");

?>