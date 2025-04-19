<?php // backend/search.php (Corrected V3 - Returns position data for non-stowed items if available)

require_once __DIR__ . '/database.php'; // Defines getDbConnection()

// ---> START: Helper Function for X/Z Overlap <---
/**
 * Checks if two boxes overlap in the X (width) and Z (height) dimensions.
 * Assumes boxes are arrays with 'x', 'z', 'w', 'h' keys.
 */
function doBoxesOverlapXZ(array $box1, array $box2): bool {
    $keys = ['x', 'z', 'w', 'h'];
    foreach ($keys as $key) {
        if (!isset($box1[$key]) || !isset($box2[$key])) {
             error_log("doBoxesOverlapXZ Warning: Missing key '$key'. Box1: " . print_r($box1, true) . " Box2: " . print_r($box2, true));
             return false;
        }
    }
    $epsilon = 0.001;
    $xOverlap = ($box1['x'] < $box2['x'] + $box2['w'] - $epsilon) && ($box1['x'] + $box1['w'] > $box2['x'] + $epsilon);
    $zOverlap = ($box1['z'] < $box2['z'] + $box2['h'] - $epsilon) && ($box1['z'] + $box1['h'] > $box2['z'] + $epsilon);
    return $xOverlap && $zOverlap;
}
// ---> END: Helper Function <---

// ---> START: Helper Function to Format Position for API ---
/**
 * Creates the 'position' object for the API response using internal box data.
 * Returns null if essential data is missing or not numeric.
 */
function formatPositionForApi(?array $internalBoxData): ?array {
     if ($internalBoxData === null) return null;

     // Check if all required keys exist and are numeric
     $keys = ['x', 'y', 'z', 'w', 'd', 'h'];
     foreach ($keys as $key) {
         if (!isset($internalBoxData[$key]) || !is_numeric($internalBoxData[$key])) {
              error_log("formatPositionForApi: Returning null due to missing/invalid numeric key '$key' in: " . print_r($internalBoxData, true));
              return null;
         }
     }

     // All keys are present and numeric, proceed
     $startW = (float)$internalBoxData['x'];
     $startD = (float)$internalBoxData['y'];
     $startH = (float)$internalBoxData['z'];
     $placedW = (float)$internalBoxData['w'];
     $placedD = (float)$internalBoxData['d'];
     $placedH = (float)$internalBoxData['h'];

     return [
         'startCoordinates' => ['width' => $startW, 'depth' => $startD, 'height' => $startH],
         'endCoordinates' => ['width' => $startW + $placedW, 'depth' => $startD + $placedD, 'height' => $startH + $placedH]
     ];
}
// ---> END: Helper Function ---


// --- Main Search Logic ---
$itemId = isset($_GET['itemId']) ? trim($_GET['itemId']) : null;
$itemName = isset($_GET['itemName']) ? trim($_GET['itemName']) : null;
$userId = isset($_GET['userId']) ? trim($_GET['userId']) : null;

$db = null;

try {
    $db = getDbConnection();
    if ($db === null) { throw new Exception("Database connection unavailable.", 503); }
    if (empty($itemId) && empty($itemName)) { throw new Exception("Missing required parameter: itemId or itemName", 400); }

    // Step 1: Find item by ID or Name, regardless of status
    $findSql = "SELECT i.*, c.zone
                FROM items i
                LEFT JOIN containers c ON i.containerId = c.containerId
                WHERE ";
    $params = [];
    if (!empty($itemId)) {
        $findSql .= "i.itemId = :itemId";
        $params[':itemId'] = $itemId;
    } else {
        $findSql .= "i.name LIKE :itemName";
        $params[':itemName'] = '%' . $itemName . '%';
    }
    $findSql .= " LIMIT 1";

    $stmt = $db->prepare($findSql);
    $stmt->execute($params);
    $itemData = $stmt->fetch(PDO::FETCH_ASSOC);

    // Step 2: Check if item was found
    if (!$itemData) {
        http_response_code(200); // Still success, just not found
        echo json_encode(['success' => true, 'found' => false, 'item' => null, 'retrievalSteps' => []]);
        exit;
    }
    error_log("Search Debug: Fetched Data for ID/Name [" . ($itemId ?? $itemName) . "]: " . print_r($itemData, true));


    // Step 3: Calculate Retrieval Steps ONLY IF STOWED
    $retrievalSteps = [];
    $targetItemBox = null; // Define variable scope outside the if

    // Check if the item is currently 'stowed' and has the necessary data for obstruction calculation
    $isStowed = ($itemData['status'] === 'stowed' &&
                 $itemData['containerId'] !== null &&
                 $itemData['positionX'] !== null && $itemData['positionY'] !== null && $itemData['positionZ'] !== null &&
                 $itemData['placedDimensionW'] !== null && $itemData['placedDimensionD'] !== null && $itemData['placedDimensionH'] !== null);

    if ($isStowed) {
        error_log("Search API: Item {$itemData['itemId']} is stowed in {$itemData['containerId']}. Calculating obstructions...");

        // Build target item's box data for calculations (using placed dimensions)
        $targetItemBox = [ // Populate the box data here since it's needed for calculations
             'id'   => $itemData['itemId'], 'name' => $itemData['name'],
             'x'    => (float)$itemData['positionX'], 'y'    => (float)$itemData['positionY'], 'z'    => (float)$itemData['positionZ'],
             'w'    => (float)$itemData['placedDimensionW'], 'd'    => (float)$itemData['placedDimensionD'], 'h'    => (float)$itemData['placedDimensionH']
        ];
        $containerId = $itemData['containerId'];

        // Fetch all other stowed items in the same container
        $sqlAllOthers = "SELECT itemId, name, positionX, positionY, positionZ,
                                placedDimensionW, placedDimensionD, placedDimensionH
                         FROM items
                         WHERE containerId = :cid
                           AND itemId != :tid
                           AND status = 'stowed'
                           AND positionX IS NOT NULL AND positionY IS NOT NULL AND positionZ IS NOT NULL
                           AND placedDimensionW IS NOT NULL AND placedDimensionD IS NOT NULL AND placedDimensionH IS NOT NULL";
        $stmtAllOthers = $db->prepare($sqlAllOthers);
        $stmtAllOthers->execute([':cid' => $containerId, ':tid' => $itemData['itemId']]);
        $otherItemsInContainer = $stmtAllOthers->fetchAll(PDO::FETCH_ASSOC);

        $obstructionItemsData = []; $epsilon = 0.001;

        foreach ($otherItemsInContainer as $otherItem) {
             $otherItemBox = [
                 'id'   => $otherItem['itemId'], 'name' => $otherItem['name'],
                 'x'    => (float)$otherItem['positionX'], 'y'    => (float)$otherItem['positionY'], 'z'    => (float)$otherItem['positionZ'],
                 'w'    => (float)$otherItem['placedDimensionW'], 'd'    => (float)$otherItem['placedDimensionD'], 'h'    => (float)$otherItem['placedDimensionH']
             ];

            // Obstruction Check: 'other' is IN FRONT (SMALLER Y) and overlaps in X/Z
            if ($otherItemBox['y'] < ($targetItemBox['y'] - $epsilon)) {
                 if (doBoxesOverlapXZ($targetItemBox, $otherItemBox)) {
                      error_log("Search API: --> Item {$targetItemBox['id']} obstructed by {$otherItemBox['id']} (OtherY: {$otherItemBox['y']}, TargetY: {$targetItemBox['y']})");
                      $obstructionItemsData[] = ['boxData' => $otherItemBox, 'itemId' => $otherItem['itemId'], 'itemName' => $otherItem['name']];
                 }
             }
        }
        // Sort obstructions by Y ascending (closest to opening first)
         usort($obstructionItemsData, fn($a, $b) => $a['boxData']['y'] <=> $b['boxData']['y']);

        // Build retrieval steps array
        $stepCounter = 1;
        foreach ($obstructionItemsData as $obstructor) {
             $retrievalSteps[] = [
                 'step' => $stepCounter++, 'action' => 'remove',
                 'itemId' => $obstructor['itemId'], 'itemName' => $obstructor['itemName'],
                 'containerId' => $containerId, 'position' => formatPositionForApi($obstructor['boxData'])
             ];
        }
        // Add the final step to retrieve the target item
        $retrievalSteps[] = [
            'step' => $stepCounter++, 'action' => 'retrieve',
            'itemId' => $targetItemBox['id'], 'itemName' => $targetItemBox['name'],
            'containerId' => $containerId, 'position' => formatPositionForApi($targetItemBox) // Use target box data
        ];

    } else { // Item found but not stowed or missing placement data
         error_log("Search API: Item {$itemData['itemId']} found but status is not 'stowed' ({$itemData['status']}) or lacks complete placement data. No retrieval steps generated.");
         // $targetItemBox remains null here if not stowed
    }
    // --- End Step 3 ---


    // --- Step 4: Format the final API item object ---
    // *** MODIFIED LOGIC: Attempt to format position REGARDLESS OF STATUS if data exists ***
    $displayDimensions = null;
    // Check if the necessary placement keys exist in the fetched data
    if (isset($itemData['positionX'], $itemData['positionY'], $itemData['positionZ'],
              $itemData['placedDimensionW'], $itemData['placedDimensionD'], $itemData['placedDimensionH']))
    {
        // Create a temporary box array from the main $itemData
        $boxDataFromItem = [
            'x' => $itemData['positionX'],
            'y' => $itemData['positionY'],
            'z' => $itemData['positionZ'],
            'w' => $itemData['placedDimensionW'],
            'd' => $itemData['placedDimensionD'],
            'h' => $itemData['placedDimensionH']
        ];
        // Try to format it using the helper function (which checks for numeric values)
        $displayDimensions = formatPositionForApi($boxDataFromItem);
        if ($displayDimensions === null) {
            error_log("Search API: Position data found for item {$itemData['itemId']} but failed numeric checks in formatPositionForApi.");
        } else {
             error_log("Search API: Successfully formatted position data for non-stowed item {$itemData['itemId']}.");
        }
    } else {
         error_log("Search API: Placement data keys (positionX/Y/Z, placedDimensionW/D/H) missing for item {$itemData['itemId']}. Position field will be null.");
    }


    $apiItem = [
        'itemId' => $itemData['itemId'],
        'name' => $itemData['name'],
        'containerId' => $itemData['containerId'], // Still shows container ID if present
        'zone' => $itemData['zone'],               // Still shows zone if present
        'position' => $displayDimensions, // Use the potentially calculated dimensions or null
        'status' => $itemData['status'],
        'expiryDate' => $itemData['expiryDate'],
        'remainingUses' => isset($itemData['remainingUses']) ? (int)$itemData['remainingUses'] : null,
        'mass' => isset($itemData['mass']) ? (float)$itemData['mass'] : null,
        'priority' => isset($itemData['priority']) ? (int)$itemData['priority'] : null,
        'preferredZone' => $itemData['preferredZone'],
        'lastUpdated' => $itemData['lastUpdated'],
        'createdAt' => $itemData['createdAt'],
        // Include original dimensions for reference
        'originalDimensions' => [
            'width' => (float)($itemData['dimensionW'] ?? 0),
            'depth' => (float)($itemData['dimensionD'] ?? 0),
            'height' => (float)($itemData['dimensionH'] ?? 0),
        ],
    ];
    // --- End Step 4 ---

    // Step 5: Construct final success response (Same as before)
    $responseData = [ 'success' => true, 'found' => true, 'item' => $apiItem, 'retrievalSteps' => $retrievalSteps ];
    http_response_code(200);
    echo json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); // Added pretty print


} catch (PDOException | Exception $e) { // Catch DB or other exceptions
    $statusCode = ($e instanceof PDOException) ? 500 : ($e->getCode() >= 400 && $e->getCode() < 600 ? (int)$e->getCode() : 500);
    http_response_code($statusCode);
    error_log("Search API Error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString()); // Added trace
    // Avoid echoing detailed internal errors to the client in production
    $clientErrorMessage = ($statusCode === 400) ? $e->getMessage() : 'Server error during search.';
    echo json_encode(['success' => false, 'found' => false, 'error' => $clientErrorMessage]);
} finally {
    $db = null; // Ensure DB connection closed
}

?>