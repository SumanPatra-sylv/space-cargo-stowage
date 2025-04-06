<?php
// --- File: backend/place.php ---
// Handles manual placement of an item (POST /api/place)

// Use __DIR__ for robustness when included by index.php
require_once __DIR__ . '/database.php'; // Defines getDbConnection()

// Initialize response structure
$response = ['success' => false, 'message' => 'An unknown error occurred.'];

// --- 1. Establish Database Connection ---
error_log("Place API: Attempting DB connection...");
$db = getDbConnection();
if ($db === null) {
    error_log("Place API FATAL: Failed to get DB connection.");
    http_response_code(503); // Service Unavailable
    $response['message'] = 'Database connection failed.';
    echo json_encode($response);
    exit;
}
error_log("Place API: DB connection successful.");

// --- 2. Validate Request Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    $response['message'] = 'Invalid request method. Only POST is allowed.';
    error_log("Place API: Received method " . $_SERVER['REQUEST_METHOD']);
    echo json_encode($response);
    $db = null; // Close connection
    exit;
}

// --- 3. Get and Validate Input Data ---
$rawData = file_get_contents('php://input');
$requestData = json_decode($rawData, true);

// Check for JSON decoding errors or missing payload
if ($requestData === null && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400); // Bad Request
    $response['message'] = 'Invalid JSON input: ' . json_last_error_msg();
    error_log("Place API: Invalid JSON received: " . $rawData);
    echo json_encode($response);
    $db = null;
    exit;
}

// Validate presence of required top-level fields
$requiredFields = ['itemId', 'containerId', 'position', 'userId'];
foreach ($requiredFields as $field) {
    if (!isset($requestData[$field]) || (is_string($requestData[$field]) && trim($requestData[$field]) === '')) {
        http_response_code(400);
        $response['message'] = "Missing or empty required field: '$field'.";
        error_log("Place API: Missing required field '$field' in payload: " . $rawData);
        echo json_encode($response);
        $db = null;
        exit;
    }
}

$itemId = trim($requestData['itemId']);
$containerId = trim($requestData['containerId']);
$positionData = $requestData['position'];
$userId = trim($requestData['userId']); // Used primarily for logging context later
$timestamp = date('Y-m-d H:i:s'); // Server-side timestamp for lastUpdated

// Validate position structure and coordinate presence/type
$requiredCoords = ['startCoordinates', 'endCoordinates'];
$coordKeys = ['width', 'depth', 'height'];

foreach ($requiredCoords as $coordType) {
    if (!isset($positionData[$coordType]) || !is_array($positionData[$coordType])) {
        http_response_code(400);
        $response['message'] = "Invalid or missing position structure: '$coordType' must be an object.";
        error_log("Place API: Invalid position structure ('$coordType'): " . json_encode($positionData));
        echo json_encode($response);
        $db = null;
        exit;
    }
    foreach ($coordKeys as $key) {
        if (!isset($positionData[$coordType][$key]) || !is_numeric($positionData[$coordType][$key])) {
            http_response_code(400);
            $response['message'] = "Invalid or missing coordinate: '$coordType.$key' must be a number.";
            error_log("Place API: Invalid coordinate ('$coordType.$key'): " . json_encode($positionData));
            echo json_encode($response);
            $db = null;
            exit;
        }
    }
}

// --- 4. Extract Coordinates and Calculate Placed Dimensions ---
// Translate API names (width, depth, height) to DB names (X, Y, Z) for position
$posX = (float)$positionData['startCoordinates']['width'];  // API Width -> DB X
$posY = (float)$positionData['startCoordinates']['depth'];  // API Depth -> DB Y
$posZ = (float)$positionData['startCoordinates']['height']; // API Height -> DB Z

// Calculate placed dimensions (DB W, D, H) using API width/depth/height axes
// placedW = endX - startX = endWidth - startWidth
$placedW = (float)$positionData['endCoordinates']['width'] - $posX;
// placedD = endY - startY = endDepth - startDepth
$placedD = (float)$positionData['endCoordinates']['depth'] - $posY;
// placedH = endZ - startZ = endHeight - startHeight
$placedH = (float)$positionData['endCoordinates']['height'] - $posZ;

// Basic validation for calculated dimensions (must be non-negative)
$epsilon = 0.0001; // Tolerance for float comparison
if ($placedW < -$epsilon || $placedD < -$epsilon || $placedH < -$epsilon) {
    http_response_code(400);
    $response['message'] = 'Invalid placement: Calculated dimensions cannot be negative. Check start/end coordinates.';
    error_log("Place API: Negative dimensions calculated: W=$placedW, D=$placedD, H=$placedH from " . json_encode($positionData));
    echo json_encode($response);
    $db = null;
    exit;
}
// Ensure non-negative values close to zero are treated as zero
$placedW = max(0.0, $placedW);
$placedD = max(0.0, $placedD);
$placedH = max(0.0, $placedH);

error_log("Place API: Processing placement for Item: $itemId, Container: $containerId, User: $userId");
error_log("Place API: Coords (X,Y,Z): ($posX, $posY, $posZ), Placed Dims (W,D,H): ($placedW, $placedD, $placedH)");


// --- 5. Perform Database Update ---
$db->beginTransaction();
try {
    // Verify the target container exists
    $containerCheckSql = "SELECT COUNT(*) FROM containers WHERE containerId = :containerId";
    $containerStmt = $db->prepare($containerCheckSql);
    $containerStmt->bindParam(':containerId', $containerId);
    $containerStmt->execute();
    if ($containerStmt->fetchColumn() == 0) {
        throw new Exception("Target container '$containerId' does not exist.", 404); // Not Found
    }

    // Prepare the UPDATE statement for the item
    $updateSql = "UPDATE items SET
                   containerId = :containerId,
                   positionX = :posX,
                   positionY = :posY,
                   positionZ = :posZ,
                   placedDimensionW = :placedW,
                   placedDimensionD = :placedD,
                   placedDimensionH = :placedH,
                   status = 'stowed', -- Explicitly set status
                   lastUpdated = :lastUpdated
                 WHERE itemId = :itemId"; // Update specific item
                 // Could add AND status != 'disposed' if needed, but manual place might override

    $updateStmt = $db->prepare($updateSql);

    // Bind all parameters
    $updateStmt->bindParam(':containerId', $containerId);
    $updateStmt->bindParam(':posX', $posX);
    $updateStmt->bindParam(':posY', $posY);
    $updateStmt->bindParam(':posZ', $posZ);
    $updateStmt->bindParam(':placedW', $placedW);
    $updateStmt->bindParam(':placedD', $placedD);
    $updateStmt->bindParam(':placedH', $placedH);
    $updateStmt->bindParam(':lastUpdated', $timestamp);
    $updateStmt->bindParam(':itemId', $itemId);

    // Execute the update
    if (!$updateStmt->execute()) {
        // Throw exception on execution failure
        throw new PDOException("Database error during item update execution.");
    }

    // Check if the item was actually found and updated
    if ($updateStmt->rowCount() === 0) {
        // Item ID might not exist, or maybe status prevented update (if WHERE clause was stricter)
        throw new Exception("Item with ID '$itemId' not found or could not be updated.", 404); // Not Found or Conflict
    }

    // --- Logging is NOT done here ---
    // Logging responsibility moved to frontend calling /api/log-action after success.

    // Commit the transaction if update was successful
    $db->commit();

    $response['success'] = true;
    $response['message'] = "Item '$itemId' placed successfully in container '$containerId'.";
    http_response_code(200); // OK
    error_log("Place API: Successfully placed item $itemId.");

} catch (PDOException $e) {
    // Handle database-specific errors
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500); // Internal Server Error
    $response['message'] = "Database error during placement: " . $e->getMessage();
    error_log("Place API Database Exception: " . $e->getMessage());

} catch (Exception $e) {
    // Handle application-level errors (validation, item/container not found)
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // Use code from exception if set and valid, otherwise default to 500 or 400 based on context
    $statusCode = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 ? (int)$e->getCode() : 400; // Default to Bad Request for app errors unless specified
    http_response_code($statusCode);
    $response['message'] = "Placement error: " . $e->getMessage();
    error_log("Place API Application Exception (Code: $statusCode): " . $e->getMessage());

} finally {
    // Ensure DB connection is closed
    $db = null;
    error_log("Place API: Finished processing itemId: $itemId");
}

// --- 6. Output Final JSON Response ---
// Ensure headers haven't already been sent (e.g., by previous errors)
if (!headers_sent()) {
    echo json_encode($response);
}

?>