<?php // backend/search.php (Corrected Obstruction Logic & Debug Logging)

require_once __DIR__ . '/database.php'; // Defines getDbConnection()

// --- Helper Function for Y/Z Overlap (Used for original incorrect logic, might remove later if unused) ---
function doBoxesOverlapYZ(array $box1, array $box2): bool {
    $keys = ['y', 'z', 'd', 'h'];
    foreach ($keys as $key) { if (!isset($box1[$key]) || !isset($box2[$key])) { error_log("doBoxesOverlapYZ Warning: Missing key '$key'."); return false; } }
    $epsilon = 0.001;
    $yOverlap = ($box1['y'] < $box2['y'] + $box2['d'] - $epsilon) && ($box1['y'] + $box1['d'] > $box2['y'] + $epsilon);
    $zOverlap = ($box1['z'] < $box2['z'] + $box2['h'] - $epsilon) && ($box1['z'] + $box1['h'] > $box2['z'] + $epsilon);
    return $yOverlap && $zOverlap;
}
// --- End Helper Function ---

// ---> START: New Helper Function for X/Z Overlap <---
/**
 * Checks if two boxes overlap in the X (width) and Z (height) dimensions.
 * Assumes boxes are arrays with 'x', 'z', 'w', 'h' keys.
 */
function doBoxesOverlapXZ(array $box1, array $box2): bool {
    // Ensure necessary keys exist
    $keys = ['x', 'z', 'w', 'h'];
    foreach ($keys as $key) {
        if (!isset($box1[$key]) || !isset($box2[$key])) {
             error_log("doBoxesOverlapXZ Warning: Missing key '$key'. Box1: " . print_r($box1, true) . " Box2: " . print_r($box2, true));
             return false; // Cannot determine overlap if keys are missing
        }
    }
    $epsilon = 0.001; // Tolerance for floating point comparisons

    // Check for overlap on X axis
    $xOverlap = ($box1['x'] < $box2['x'] + $box2['w'] - $epsilon) && ($box1['x'] + $box1['w'] > $box2['x'] + $epsilon);

    // Check for overlap on Z axis
    $zOverlap = ($box1['z'] < $box2['z'] + $box2['h'] - $epsilon) && ($box1['z'] + $box1['h'] > $box2['z'] + $epsilon);

    // Overlap occurs if they overlap on BOTH X and Z axes
    return $xOverlap && $zOverlap;
}
// ---> END: New Helper Function <---


// --- Helper Function to Format Position for API ---
function formatPositionForApi(?array $internalBoxData): ?array { // Allow null input
     if ($internalBoxData === null) return null; // Return null if no box data

     // Ensure keys exist before using them
     $startW = $internalBoxData['x'] ?? null; $startD = $internalBoxData['y'] ?? null; $startH = $internalBoxData['z'] ?? null;
     $placedW = $internalBoxData['w'] ?? null; $placedD = $internalBoxData['d'] ?? null; $placedH = $internalBoxData['h'] ?? null;

     // Only return position if all parts are valid numbers
     if (is_numeric($startW) && is_numeric($startD) && is_numeric($startH) &&
         is_numeric($placedW) && is_numeric($placedD) && is_numeric($placedH) ) {
         return [
             'startCoordinates' => ['width' => (float)$startW, 'depth' => (float)$startD, 'height' => (float)$startH],
             'endCoordinates' => ['width' => (float)$startW + (float)$placedW, 'depth' => (float)$startD + (float)$placedD, 'height' => (float)$startH + (float)$placedH]
         ];
     }
     return null; // Return null if data is incomplete/invalid
}
// --- End Helper Function ---


// --- Main Search Logic ---
// ---> FIX: Trim input parameters <---
$itemId = isset($_GET['itemId']) ? trim($_GET['itemId']) : null;
$itemName = isset($_GET['itemName']) ? trim($_GET['itemName']) : null;
$userId = isset($_GET['userId']) ? trim($_GET['userId']) : null; // Optional user context
// ---> END FIX <---

$db = null; // Initialize DB variable

try {
    $db = getDbConnection();
    if ($db === null) { throw new Exception("Database connection unavailable.", 503); }
    if (empty($itemId) && empty($itemName)) { throw new Exception("Missing required parameter: itemId or itemName", 400); }

    // --- Step 1: Find item by ID or Name, regardless of status ---
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
    // --- End Step 1 ---

    // --- Step 2: Check if item was found ---
    if (!$itemData) {
        http_response_code(200);
        echo json_encode(['success' => true, 'found' => false, 'item' => null, 'retrievalSteps' => []]);
        exit;
    }
    // ---> FIX: Add Debug Logging <---
    error_log("Search Debug: Fetched Data for ID/Name [" . ($itemId ?? $itemName) . "]: " . print_r($itemData, true));
    // ---> END FIX <---
    // --- End Step 2 ---


    // --- Step 3: Item Found - Check Status and Calculate Retrieval Steps if Needed ---
    $retrievalSteps = []; $obstructionCount = 0; $targetItemBox = null;

    // Check if the found item is currently stowed and has necessary PLACEMENT data
    $isStowed = ($itemData['status'] === 'stowed' &&
                 $itemData['containerId'] !== null &&
                 $itemData['positionX'] !== null && $itemData['positionY'] !== null && $itemData['positionZ'] !== null &&
                 $itemData['placedDimensionW'] !== null && $itemData['placedDimensionD'] !== null && $itemData['placedDimensionH'] !== null
                 // We must use placed dimensions for obstruction checks if they exist
                );

    if ($isStowed) {
        error_log("Search API: Item {$itemData['itemId']} is stowed in {$itemData['containerId']}. Calculating obstructions...");

        // Build target item's box data for calculations (using placed dimensions)
        $targetItemBox = [
             'id'   => $itemData['itemId'], 'name' => $itemData['name'],
             'x'    => (float)$itemData['positionX'], 'y'    => (float)$itemData['positionY'], 'z'    => (float)$itemData['positionZ'],
             'w'    => (float)$itemData['placedDimensionW'], 'd'    => (float)$itemData['placedDimensionD'], 'h'    => (float)$itemData['placedDimensionH']
        ];
        $containerId = $itemData['containerId'];

        // Fetch all other stowed items in the same container with valid placement data
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
             // Build potential obstructor's box data (already filtered for NOT NULL)
             $otherItemBox = [
                 'id'   => $otherItem['itemId'], 'name' => $otherItem['name'],
                 'x'    => (float)$otherItem['positionX'], 'y'    => (float)$otherItem['positionY'], 'z'    => (float)$otherItem['positionZ'],
                 'w'    => (float)$otherItem['placedDimensionW'], 'd'    => (float)$otherItem['placedDimensionD'], 'h'    => (float)$otherItem['placedDimensionH']
             ];

            // ---> FIX: Corrected Obstruction Check <---
            // Check if 'other' is IN FRONT (SMALLER Y) and overlaps in X/Z
            if ($otherItemBox['y'] < ($targetItemBox['y'] - $epsilon)) {
                 if (doBoxesOverlapXZ($targetItemBox, $otherItemBox)) { // Use new XZ overlap check
                      error_log("Search API: --> Item {$targetItemBox['id']} obstructed by {$otherItemBox['id']} (OtherY: {$otherItemBox['y']}, TargetY: {$targetItemBox['y']})");
                      $obstructionItemsData[] = ['boxData' => $otherItemBox, 'itemId' => $otherItem['itemId'], 'itemName' => $otherItem['name']];
                 }
             }
             // ---> END FIX <---
        }
        // ---> FIX: Corrected Obstruction Sort <---
        // Sort obstructions by Y ascending (closest to opening first)
         usort($obstructionItemsData, fn($a, $b) => $a['boxData']['y'] <=> $b['boxData']['y']);
        // ---> END FIX <---
         $obstructionCount = count($obstructionItemsData);

        // Build retrieval steps array
        $stepCounter = 1;
        foreach ($obstructionItemsData as $obstructor) {
             $retrievalSteps[] = [
                 'step' => $stepCounter++, 'action' => 'remove', // Or 'setAside'
                 'itemId' => $obstructor['itemId'], 'itemName' => $obstructor['itemName'],
                 'containerId' => $containerId, 'position' => formatPositionForApi($obstructor['boxData'])
             ];
        }
        $retrievalSteps[] = [
            'step' => $stepCounter++, 'action' => 'retrieve',
            'itemId' => $targetItemBox['id'], 'itemName' => $targetItemBox['name'],
            'containerId' => $containerId, 'position' => formatPositionForApi($targetItemBox)
        ];
        // Optional 'placeBack' steps could be added here

    } else { // Item found but not stowed or missing placement data
         error_log("Search API: Item {$itemData['itemId']} found but is not stowed or lacks complete placement data (Status: {$itemData['status']}). No retrieval steps generated.");
    }
    // --- End Step 3 ---


    // --- Step 4: Format the final API item object ---
    // Use placed dimensions if available and item is stowed, otherwise maybe original?
    $displayDimensions = null;
    if ($isStowed && $targetItemBox !== null) {
        $displayDimensions = [ // Format consistent with API response spec (start/end)
            'startCoordinates' => ['width' => $targetItemBox['x'], 'depth' => $targetItemBox['y'], 'height' => $targetItemBox['z']],
            'endCoordinates' => ['width' => $targetItemBox['x'] + $targetItemBox['w'], 'depth' => $targetItemBox['y'] + $targetItemBox['d'], 'height' => $targetItemBox['z'] + $targetItemBox['h']]
        ];
    } // else: dimensions remain null if not stowed/placed

    $apiItem = [
        'itemId' => $itemData['itemId'],
        'name' => $itemData['name'],
        'containerId' => $itemData['containerId'], // Null if not stowed
        'zone' => $itemData['zone'], // Null if not stowed or container missing
        'position' => $displayDimensions, // Use the formatted position/dimensions or null
        'status' => $itemData['status'],
        'expiryDate' => $itemData['expiryDate'],
        'remainingUses' => isset($itemData['remainingUses']) ? (int)$itemData['remainingUses'] : null,
        'mass' => isset($itemData['mass']) ? (float)$itemData['mass'] : null,
        'priority' => isset($itemData['priority']) ? (int)$itemData['priority'] : null,
        'preferredZone' => $itemData['preferredZone'],
        'lastUpdated' => $itemData['lastUpdated'],
        'createdAt' => $itemData['createdAt'],
        // Keep original dimensions for reference if needed by frontend
        'originalDimensions' => [
            'width' => (float)($itemData['dimensionW'] ?? 0),
            'depth' => (float)($itemData['dimensionD'] ?? 0),
            'height' => (float)($itemData['dimensionH'] ?? 0),
        ],
    ];
    // --- End Step 4 ---

    // --- Step 5: Construct final success response ---
    $responseData = [ 'success' => true, 'found' => true, 'item' => $apiItem, 'retrievalSteps' => $retrievalSteps ];
    http_response_code(200);
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
// Let index.php handle exit (remove final closing tag if possible)
?>