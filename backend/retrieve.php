<?php // backend/retrieve.php (WORKAROUND: Update Only - Added Final Log)

require_once __DIR__ . '/database.php';
$db = null; $response = ['success' => false, 'message' => ''];

try { $db = getDbConnection(); if ($db === null) throw new Exception("DB Connection Failed", 503); }
catch (Exception $e) { /* Handle DB Error */ exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { /* Handle method error */ exit; }
$requestData = json_decode(file_get_contents('php://input'), true);
if ($requestData === null || !isset($requestData['itemId'])) { /* Handle input error */ exit; }
$itemId = trim($requestData['itemId']); $userId = $requestData['userId'] ?? 'System_Retrieve'; $timestamp = date('Y-m-d H:i:s');
if (empty($itemId)) { /* Handle empty itemId error */ exit; }
error_log("Retrieve API (Update Only): Request for itemId: $itemId");

// --- NO TRANSACTION ---
try {
    $findSql = "SELECT itemId, usageLimit, remainingUses, status FROM items WHERE itemId = :itemId";
    $findStmt = $db->prepare($findSql); $findStmt->bindParam(':itemId', $itemId); $findStmt->execute(); $item = $findStmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) { /* Handle not found error */ exit; }
    if ($item['status'] !== 'stowed') { /* Handle wrong status error */ exit; }
    error_log("Retrieve API (Update Only): Found item $itemId. Current Uses: " . $item['remainingUses']);

    // Calculate new status and remaining uses
    $isUsageLimited = ($item['usageLimit'] !== null && is_numeric($item['usageLimit']));
    $newRemainingUses = $item['remainingUses']; // Start with original
    $newStatus = 'retrieved';
    if ($isUsageLimited) {
        $hasUsesRemaining = ($item['remainingUses'] !== null && is_numeric($item['remainingUses']) && $item['remainingUses'] > 0);
        if ($hasUsesRemaining) {
            $newRemainingUses = $item['remainingUses'] - 1; // Actual calculation
            if ($newRemainingUses <= 0) { $newRemainingUses = 0; $newStatus = 'waste_depleted'; }
            error_log("Retrieve API (Update Only): Calculated newRemainingUses: " . $newRemainingUses); // Log calculated value
        } else { $newStatus = 'waste_depleted'; $newRemainingUses = 0; error_log("Retrieve API (Update Only): Item already depleted but stowed?"); }
    }

    // Execute FULL update statement
    $updateSql = "UPDATE items SET remainingUses = :remainingUses, status = :newStatus, containerId = NULL, positionX = NULL, positionY = NULL, positionZ = NULL, placedDimensionW = NULL, placedDimensionD = NULL, placedDimensionH = NULL, lastUpdated = :lastUpdated WHERE itemId = :itemId AND status = 'stowed'";
    $updateStmt = $db->prepare($updateSql);
    // Bind using the calculated $newRemainingUses
    $updateStmt->bindValue(':remainingUses', $newRemainingUses, ($newRemainingUses === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
    $updateStmt->bindParam(':newStatus', $newStatus, PDO::PARAM_STR);
    $updateStmt->bindParam(':lastUpdated', $timestamp);
    $updateStmt->bindParam(':itemId', $itemId);
    if (!$updateStmt->execute()) { throw new PDOException("Failed to execute full update."); }
    if ($updateStmt->rowCount() === 0) { throw new Exception("Item '$itemId' status changed before full update could be applied.", 409); }
    error_log("Retrieve API (Update Only): Successfully executed FULL update for item $itemId status to '$newStatus' with remainingUses set to $newRemainingUses.");

    // --- Success response ---
    $response['success'] = true;
    $response['message'] = "Item '$itemId' updated successfully.";
    $response['newState'] = [
        'status' => $newStatus,
        'remainingUses' => $newRemainingUses // Use the calculated value
    ];
    http_response_code(200); // OK

    // --- <<< ADD DEBUG LOGGING HERE >>> ---
    error_log("Retrieve API (Update Only): Sending response: " . json_encode($response));
    // --- <<< END DEBUG LOGGING >>> ---


} catch (PDOException | Exception $e) { /* ... Keep existing catch block ... */ exit; }
finally { /* ... Keep existing finally block ... */ }

if ($response['success'] && !headers_sent()) {
    // Send the response (already logged above)
    echo json_encode($response);
}
?>