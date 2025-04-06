<?php // backend/search.php (Revised to find non-stowed items)

require_once __DIR__ . '/database.php'; // Defines getDbConnection()

// --- Helper Function for Y/Z Overlap ---
// (Keep this function exactly as it was - it's needed for obstruction checks)
function doBoxesOverlapYZ(array $box1, array $box2): bool {
    $keys = ['y', 'z', 'd', 'h'];
    foreach ($keys as $key) { if (!isset($box1[$key]) || !isset($box2[$key])) { error_log("doBoxesOverlapYZ Warning: Missing key '$key'."); return false; } }
    $epsilon = 0.001;
    $yOverlap = ($box1['y'] < $box2['y'] + $box2['d'] - $epsilon) && ($box1['y'] + $box1['d'] > $box2['y'] + $epsilon);
    $zOverlap = ($box1['z'] < $box2['z'] + $box2['h'] - $epsilon) && ($box1['z'] + $box1['h'] > $box2['z'] + $epsilon);
    return $yOverlap && $zOverlap;
}
// --- End Helper Function ---


// --- Helper Function to Format Position for API ---
// (Keep this function exactly as it was - it's needed for response formatting)
function formatPositionForApi(array $internalBoxData): array {
     $startW = $internalBoxData['x']; $startD = $internalBoxData['y']; $startH = $internalBoxData['z'];
     $placedW = $internalBoxData['w']; $placedD = $internalBoxData['d']; $placedH = $internalBoxData['h'];
     return [
         'startCoordinates' => ['width' => $startW, 'depth' => $startD, 'height' => $startH],
         'endCoordinates' => ['width' => $startW + $placedW, 'depth' => $startD + $placedD, 'height' => $startH + $placedH]
     ];
}
// --- End Helper Function ---


// --- Main Search Logic ---
$itemId = $_GET['itemId'] ?? null;
$itemName = $_GET['itemName'] ?? null;
$userId = $_GET['userId'] ?? null; // Optional user context
$db = null; // Initialize DB variable

try {
    $db = getDbConnection();
    if ($db === null) { throw new Exception("Database connection unavailable.", 503); }
    if (empty($itemId) && empty($itemName)) { throw new Exception("Missing required parameter: itemId or itemName", 400); }

    // --- Step 1: Find item by ID or Name, regardless of status ---
    // Join with containers to get zone information if available
    $findSql = "SELECT i.*, c.zone
                FROM items i
                LEFT JOIN containers c ON i.containerId = c.containerId
                WHERE ";
    $params = [];
    if (!empty($itemId)) {
        $findSql .= "i.itemId = :itemId";
        $params[':itemId'] = $itemId;
    } else {
        $findSql .= "i.name LIKE :itemName"; // Use LIKE for flexible name search
        $params[':itemName'] = '%' . $itemName . '%';
    }
    $findSql .= " LIMIT 1"; // Usually expect one item per ID/Name for this search type

    $stmt = $db->prepare($findSql);
    $stmt->execute($params);
    $itemData = $stmt->fetch(PDO::FETCH_ASSOC); // Fetch the single potential item
    // --- End Step 1 ---

    // --- Step 2: Check if item was found ---
    if (!$itemData) {
        http_response_code(200); // Request OK, just no results
        echo json_encode(['success' => true, 'found' => false, 'item' => null, 'retrievalSteps' => []]);
        exit; // Important to exit after sending response
    }
    // --- End Step 2 ---


    // --- Step 3: Item Found - Check Status and Calculate Retrieval Steps if Needed ---
    $retrievalSteps = []; // Default to no steps
    $obstructionCount = 0; // Default count
    $targetItemBox = null; // Initialize target box data

    // Check if the found item is currently stowed and has necessary data
    $isStowed = ($itemData['status'] === 'stowed' &&
                 $itemData['containerId'] !== null &&
                 $itemData['positionX'] !== null && $itemData['positionY'] !== null && $itemData['positionZ'] !== null &&
                 ($itemData['placedDimensionW'] !== null || $itemData['dimensionW'] !== null) && // Need width
                 ($itemData['placedDimensionD'] !== null || $itemData['dimensionD'] !== null) && // Need depth
                 ($itemData['placedDimensionH'] !== null || $itemData['dimensionH'] !== null)    // Need height
                );

    if ($isStowed) {
        error_log("Search API: Item {$itemData['itemId']} is stowed in {$itemData['containerId']}. Calculating obstructions...");

        // Build target item's box data for calculations
        $targetItemBox = [
             'id'   => $itemData['itemId'],
             'name' => $itemData['name'],
             'x'    => (float)$itemData['positionX'],
             'y'    => (float)$itemData['positionY'],
             'z'    => (float)$itemData['positionZ'],
             'w'    => (float)($itemData['placedDimensionW'] ?? $itemData['dimensionW']), // Placed first, fallback to original
             'd'    => (float)($itemData['placedDimensionD'] ?? $itemData['dimensionD']),
             'h'    => (float)($itemData['placedDimensionH'] ?? $itemData['dimensionH'])
        ];
        $containerId = $itemData['containerId'];

        // Fetch all other stowed items in the same container
        $sqlAllOthers = "SELECT itemId, name, positionX, positionY, positionZ,
                                placedDimensionW, placedDimensionD, placedDimensionH,
                                dimensionW, dimensionD, dimensionH
                         FROM items
                         WHERE containerId = :cid
                           AND itemId != :tid
                           AND status = 'stowed'
                           AND positionX IS NOT NULL"; // Ensure they have position
        $stmtAllOthers = $db->prepare($sqlAllOthers);
        $stmtAllOthers->execute([':cid' => $containerId, ':tid' => $itemData['itemId']]);
        $otherItemsInContainer = $stmtAllOthers->fetchAll(PDO::FETCH_ASSOC);

        $obstructionItemsData = []; // Store data of items confirmed as obstructions
        $epsilon = 0.001;

        foreach ($otherItemsInContainer as $otherItem) {
             // Check if position data exists for the potential obstructor
             if (!isset($otherItem['positionX'], $otherItem['positionY'], $otherItem['positionZ'])) continue;

             // Build potential obstructor's box data
             $otherItemBox = [
                 'id'   => $otherItem['itemId'],
                 'name' => $otherItem['name'],
                 'x'    => (float)$otherItem['positionX'],
                 'y'    => (float)$otherItem['positionY'],
                 'z'    => (float)$otherItem['positionZ'],
                 'w'    => (float)($otherItem['placedDimensionW'] ?? $otherItem['dimensionW'] ?? 0),
                 'd'    => (float)($otherItem['placedDimensionD'] ?? $otherItem['dimensionD'] ?? 0),
                 'h'    => (float)($otherItem['placedDimensionH'] ?? $otherItem['dimensionH'] ?? 0)
             ];

            // Check if 'other' is IN FRONT (larger X) and overlaps in Y/Z
            if ($otherItemBox['x'] > ($targetItemBox['x'] + $epsilon)) {
                 if (doBoxesOverlapYZ($targetItemBox, $otherItemBox)) {
                      error_log("Search API: --> Item {$targetItemBox['id']} obstructed by {$otherItemBox['id']}");
                      // Store necessary data for retrieval steps
                      $obstructionItemsData[] = ['boxData' => $otherItemBox, 'itemId' => $otherItem['itemId'], 'itemName' => $otherItem['name']];
                 }
             }
        }
        // Sort obstructions by X descending (front-most first)
         usort($obstructionItemsData, fn($a, $b) => $b['boxData']['x'] <=> $a['boxData']['x']);
         $obstructionCount = count($obstructionItemsData);

        // Build retrieval steps array according to API spec
        $stepCounter = 1;
        // Add 'remove' steps for obstructions
        foreach ($obstructionItemsData as $obstructor) {
             $retrievalSteps[] = [
                 'step' => $stepCounter++,
                 'action' => 'remove', // Or 'setAside'
                 'itemId' => $obstructor['itemId'],
                 'itemName' => $obstructor['itemName'],
                 'containerId' => $containerId, // Add container context
                 'position' => formatPositionForApi($obstructor['boxData']) // Add position context
             ];
        }
        // Add the 'retrieve' step for the target
        $retrievalSteps[] = [
            'step' => $stepCounter++,
            'action' => 'retrieve',
            'itemId' => $targetItemBox['id'],
            'itemName' => $targetItemBox['name'],
            'containerId' => $containerId,
            'position' => formatPositionForApi($targetItemBox)
        ];
        // Optional 'placeBack' steps could be added here if needed
        // ...

    } else { // Item found but not stowed
         error_log("Search API: Item {$itemData['itemId']} found but is not stowed (Status: {$itemData['status']}). No retrieval steps generated.");
         // $retrievalSteps remains empty []
    }
    // --- End Step 3 ---


    // --- Step 4: Format the final API item object (always done if item found) ---
    $apiItem = [
        'itemId' => $itemData['itemId'],
        'name' => $itemData['name'],
        'containerId' => $itemData['containerId'], // Will be NULL if not stowed
        'zone' => $itemData['zone'], // From LEFT JOIN, might be NULL if not stowed/container deleted
        // Include position ONLY if stowed and calculable
        'position' => $isStowed && $targetItemBox !== null ? formatPositionForApi($targetItemBox) : null,
        // Include other useful details
        'status' => $itemData['status'],
        'expiryDate' => $itemData['expiryDate'],
        'remainingUses' => isset($itemData['remainingUses']) ? (int)$itemData['remainingUses'] : null,
        'mass' => isset($itemData['mass']) ? (float)$itemData['mass'] : null,
        'priority' => isset($itemData['priority']) ? (int)$itemData['priority'] : null,
        'preferredZone' => $itemData['preferredZone'],
        'lastUpdated' => $itemData['lastUpdated'],
        'createdAt' => $itemData['createdAt'],
        'originalDimensions' => [
            'width' => (float)($itemData['dimensionW'] ?? 0),
            'depth' => (float)($itemData['dimensionD'] ?? 0),
            'height' => (float)($itemData['dimensionH'] ?? 0),
        ],
    ];
    // --- End Step 4 ---

    // --- Step 5: Construct final success response ---
    $responseData = [
        'success' => true,
        'found' => true,
        'item' => $apiItem,
        'retrievalSteps' => $retrievalSteps // Send steps (will be empty if not stowed)
    ];

    http_response_code(200); // OK
    echo json_encode($responseData);
    // --- End Step 5 ---


} catch (PDOException | Exception $e) { // Catch DB or other exceptions
    $statusCode = ($e instanceof PDOException) ? 500 : ($e->getCode() >= 400 && $e->getCode() < 600 ? (int)$e->getCode() : 500);
    http_response_code($statusCode);
    error_log("Search API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'found' => false, 'error' => 'Server error during search: ' . $e->getMessage()]);
} finally {
    $db = null; // Ensure DB connection closed
}
// Let index.php handle exit
?>