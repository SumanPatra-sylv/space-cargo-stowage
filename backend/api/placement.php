<?php // backend/api/placement.php

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
require_once __DIR__ . '/../database.php'; 
error_log("Placement API: Attempting DB connection...");
$db = getDbConnection(); 

if ($db === null) {
    error_log("Placement API: Failed DB connection.");
    http_response_code(500); 
    echo json_encode(['success' => false, 'message' => 'Database connection failed.', 'placements' => [], 'rearrangements' => []]);
    exit; 
}
error_log("Placement API: DB connection successful.");
// --- End Database Connection --- 


// --- Response Structure ---
$response = [
    'success' => false, 
    'placements' => [],
    'rearrangements' => [], 
    'message' => '',
    'errors' => [] 
];


// --- Handle Input ---
$rawData = file_get_contents('php://input');
$requestData = json_decode($rawData, true); 

if ($requestData === null || !isset($requestData['items']) || !isset($requestData['containers']) || !is_array($requestData['items']) || !is_array($requestData['containers'])) {
    http_response_code(400); 
    $response['message'] = 'Invalid JSON input. "items" and "containers" arrays are required.';
    error_log("Placement Error: Invalid JSON input received: " . $rawData);
    echo json_encode($response);
    exit;
}

$itemsToPlaceInput = $requestData['items'];     
$containersInput = $requestData['containers']; 
error_log("Placement request received for " . count($itemsToPlaceInput) . " items using " . count($containersInput) . " containers.");


// --- Get Existing Item Placements from DB ---
$existingPlacedItemsByContainer = []; 
try {
    $sqlPlaced = "SELECT itemId, name, currentContainerId, width, depth, height, pos_w, pos_d, pos_h 
                  FROM items 
                  WHERE currentContainerId IS NOT NULL 
                  AND pos_w IS NOT NULL AND pos_d IS NOT NULL AND pos_h IS NOT NULL";
                  
    $stmtPlaced = $db->prepare($sqlPlaced);
    $stmtPlaced->execute();
    $placedItemsResult = $stmtPlaced->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($placedItemsResult as $item) {
         $containerId = $item['currentContainerId'];
         if (!isset($existingPlacedItemsByContainer[$containerId])) {
             $existingPlacedItemsByContainer[$containerId] = [];
         }
         $existingPlacedItemsByContainer[$containerId][] = [
             'id' => $item['itemId'], 'name' => $item['name'], 
             'x' => (float)$item['pos_w'], 'y' => (float)$item['pos_d'], 'z' => (float)$item['pos_h'], 
             'w' => (float)$item['width'], 'd' => (float)$item['depth'], 'h' => (float)$item['height'] 
         ];
    }
     error_log("Found existing placements in " . count($existingPlacedItemsByContainer) . " containers.");

} catch (PDOException $e) {
     http_response_code(500);
     $response['message'] = 'Error fetching existing item placements: ' . $e->getMessage();
     error_log("Placement DB Error: " . $e->getMessage());
     echo json_encode($response);
     exit;
}


// --- Placement Algorithm Logic ---
$currentPlacementState = $existingPlacedItemsByContainer; 

foreach ($itemsToPlaceInput as $itemToPlace) {
    $itemPlaced = false;
    $currentItemId = $itemToPlace['itemId'] ?? 'Unknown'; 
    error_log("Attempting to place item: " . $currentItemId . " (Priority: " . ($itemToPlace['priority'] ?? 'N/A') . ")");

    // Validate item data
    if (!isset($itemToPlace['itemId'], $itemToPlace['width'], $itemToPlace['depth'], $itemToPlace['height'])) { 
         error_log("Skipping item " . $currentItemId . " - Missing dimension data.");
         $response['errors'][] = ['itemId' => $currentItemId, 'message' => 'Missing dimension data for placement.'];
         continue; 
    }
     if (!is_numeric($itemToPlace['width']) || !is_numeric($itemToPlace['depth']) || !is_numeric($itemToPlace['height']) ||
         $itemToPlace['width'] <= 0 || $itemToPlace['depth'] <= 0 || $itemToPlace['height'] <= 0) { 
         error_log("Skipping item " . $currentItemId . " - Invalid dimensions.");
         $response['errors'][] = ['itemId' => $currentItemId, 'message' => 'Invalid dimensions provided for placement.'];
         continue; 
     }

    // --- Start of Integrated Preferred Zone Logic ---
    $preferredZone = $itemToPlace['preferredZone'] ?? null;
    $triedPreferred = false; 

    // Phase 1: Try Preferred Zone Containers
    if ($preferredZone !== null) {
        error_log("Item $currentItemId: Preferred zone is '$preferredZone'. Trying preferred containers first.");
        foreach ($containersInput as $container) {
            // Validate container data (including zone)
            if (!isset($container['containerId'], $container['width'], $container['depth'], $container['height'], $container['zone'])) { 
                 error_log("Skipping preferred check for container " . ($container['containerId'] ?? 'Unknown') . " - Missing data.");
                 continue; 
            }
            if (!is_numeric($container['width']) || !is_numeric($container['depth']) || !is_numeric($container['height'])) { 
                 error_log("Skipping preferred check for container " . $container['containerId'] . " - Invalid dimensions.");
                 continue; 
            }

            // Check if this container is in the preferred zone
            if ($container['zone'] === $preferredZone) {
                $triedPreferred = true; 
                $containerId = $container['containerId'];
                error_log("Item $currentItemId: Trying PREFERRED container: $containerId (Zone: $preferredZone)");

                $itemsCurrentlyInContainer = $currentPlacementState[$containerId] ?? [];
                $bestCoords = findSpaceForItem($itemToPlace, $container, $itemsCurrentlyInContainer); // Use best-fit function

                if ($bestCoords !== null) {
                     $posW = (float)$bestCoords['pos_w']; $posD = (float)$bestCoords['pos_d']; $posH = (float)$bestCoords['pos_h'];
                     $itemPlacedWidth = (float)$bestCoords['ori_w']; $itemPlacedDepth = (float)$bestCoords['ori_d']; $itemPlacedHeight = (float)$bestCoords['ori_h'];
                    error_log("Found BEST space for item $currentItemId in PREFERRED container $containerId at W:$posW, D:$posD, H:$posH (using dims W:$itemPlacedWidth, D:$itemPlacedDepth, H:$itemPlacedHeight)");
                    
                    // Add to response placements
                    $response['placements'][] = [
                        'itemId' => $currentItemId,
                        'containerId' => $containerId,
                        'position' => [
                            'startCoordinates' => ['width' => $posW, 'depth' => $posD, 'height' => $posH],
                            'endCoordinates' => [
                                 'width' => $posW + $itemPlacedWidth, 
                                 'depth' => $posD + $itemPlacedDepth,
                                 'height' => $posH + $itemPlacedHeight
                             ]
                        ]
                    ];
                    // Update temporary state
                    if (!isset($currentPlacementState[$containerId])) { $currentPlacementState[$containerId] = []; }
                    $currentPlacementState[$containerId][] = [
                         'id' => $currentItemId, 'name' => $itemToPlace['name'] ?? 'Unknown', 
                         'x' => $posW, 'y' => $posD, 'z' => $posH, 
                         'w' => $itemPlacedWidth, 'd' => $itemPlacedDepth, 'h' => $itemPlacedHeight 
                    ];
                    
                    $itemPlaced = true;
                    break; // Exit preferred container loop
                } else { error_log("No space found for item $currentItemId in PREFERRED container $containerId."); }
            } 
        } 
    } else { error_log("Item $currentItemId: No preferred zone specified."); }
    // --- End Phase 1 ---


    // Phase 2: Try Other Containers IF NOT placed in preferred zone
    if (!$itemPlaced) {
        if ($triedPreferred) { error_log("Item $currentItemId: Did not fit in preferred zone. Trying other containers."); } 
        else { error_log("Item $currentItemId: No preferred zone specified or no preferred containers found. Trying all available containers."); }
        
        foreach ($containersInput as $container) {
             // Validate container data
             if (!isset($container['containerId'], $container['width'], $container['depth'], $container['height'], $container['zone'])) { 
                  error_log("Skipping other check for container " . ($container['containerId'] ?? 'Unknown') . " - Missing data.");
                  continue; 
             }
             if (!is_numeric($container['width']) || !is_numeric($container['depth']) || !is_numeric($container['height'])) { 
                  error_log("Skipping other check for container " . $container['containerId'] . " - Invalid dimensions.");
                  continue; 
             }
             // Skip if this container IS the preferred zone (already tried or wasn't preferred)
             if ($preferredZone !== null && $container['zone'] === $preferredZone) { continue; }

             $containerId = $container['containerId'];
             error_log("Item $currentItemId: Trying OTHER container: $containerId (Zone: " . $container['zone'] . ")");

             $itemsCurrentlyInContainer = $currentPlacementState[$containerId] ?? [];
             $bestCoords = findSpaceForItem($itemToPlace, $container, $itemsCurrentlyInContainer); // Use best-fit function

             if ($bestCoords !== null) {
                  $posW = (float)$bestCoords['pos_w']; $posD = (float)$bestCoords['pos_d']; $posH = (float)$bestCoords['pos_h'];
                  $itemPlacedWidth = (float)$bestCoords['ori_w']; $itemPlacedDepth = (float)$bestCoords['ori_d']; $itemPlacedHeight = (float)$bestCoords['ori_h'];
                 error_log("Found BEST space for item $currentItemId in OTHER container $containerId at W:$posW, D:$posD, H:$posH (using dims W:$itemPlacedWidth, D:$itemPlacedDepth, H:$itemPlacedHeight)");

                 // Add to response placements
                 $response['placements'][] = [
                     'itemId' => $currentItemId,
                     'containerId' => $containerId,
                     'position' => [
                         'startCoordinates' => ['width' => $posW, 'depth' => $posD, 'height' => $posH],
                         'endCoordinates' => [
                              'width' => $posW + $itemPlacedWidth, 
                              'depth' => $posD + $itemPlacedDepth,
                              'height' => $posH + $itemPlacedHeight
                          ]
                     ]
                 ];
                 // Update temporary state
                 if (!isset($currentPlacementState[$containerId])) { $currentPlacementState[$containerId] = []; }
                 $currentPlacementState[$containerId][] = [
                      'id' => $currentItemId, 'name' => $itemToPlace['name'] ?? 'Unknown', 
                      'x' => $posW, 'y' => $posD, 'z' => $posH, 
                      'w' => $itemPlacedWidth, 'd' => $itemPlacedDepth, 'h' => $itemPlacedHeight 
                 ];

                 $itemPlaced = true;
                 break; // Exit other container loop
             } else { error_log("No space found for item $currentItemId in OTHER container $containerId."); }
        } 
    } 
    // --- End Phase 2 ---

    if (!$itemPlaced) {
        error_log("Could not find placement for item: " . $currentItemId . " in any container.");
        $response['errors'][] = ['itemId' => $currentItemId, 'message' => 'Could not find suitable placement space.'];
    }

} // End loop through items to place


// --- Update Database with Successful Placements --- 
error_log("Attempting to update database with " . count($response['placements']) . " successful placements.");
$updateSql = "UPDATE items SET currentContainerId = :containerId, pos_w = :pos_w, pos_d = :pos_d, pos_h = :pos_h, width = :ori_w, depth = :ori_d, height = :ori_h, status = 'stowed' WHERE itemId = :itemId";
try {
    $updateStmt = $db->prepare($updateSql);
    $updatedCount = 0;
    if (!$db->inTransaction()) { $db->beginTransaction(); } 

    foreach ($response['placements'] as $placement) {
         $itemId = $placement['itemId']; $containerId = $placement['containerId'];
         $posW = $placement['position']['startCoordinates']['width']; $posD = $placement['position']['startCoordinates']['depth']; $posH = $placement['position']['startCoordinates']['height'];
         $placedDims = null;
         if (isset($currentPlacementState[$containerId])) { foreach ($currentPlacementState[$containerId] as $placedItem) { if ($placedItem['id'] === $itemId) { $placedDims = $placedItem; break; } } }
         if ($placedDims) {
             $oriW = $placedDims['w']; $oriD = $placedDims['d']; $oriH = $placedDims['h'];
             error_log("Updating DB for itemId: $itemId -> container: $containerId, pos:($posW,$posD,$posH), dims:($oriW,$oriD,$oriH)");
             $updateStmt->bindParam(':containerId', $containerId);
             $updateStmt->bindParam(':pos_w', $posW); $updateStmt->bindParam(':pos_d', $posD); $updateStmt->bindParam(':pos_h', $posH);
             $updateStmt->bindParam(':ori_w', $oriW); $updateStmt->bindParam(':ori_d', $oriD); $updateStmt->bindParam(':ori_h', $oriH);
             $updateStmt->bindParam(':itemId', $itemId);
             if ($updateStmt->execute()) { $updatedCount++; } 
             else { error_log("Failed DB update for $itemId"); $response['errors'][] = ['itemId' => $itemId, 'message' => 'Failed DB update.']; }
         } else { error_log("Skipping DB update for $itemId: Could not find temp state."); $response['errors'][] = ['itemId' => $itemId, 'message' => 'Internal error: Temp state missing.']; }
    } 
    if ($db->inTransaction()) { $db->commit(); error_log("Committed DB updates for $updatedCount items."); }
} catch (PDOException $e) {
    error_log("Database UPDATE Error: " . $e->getMessage());
    if ($db->inTransaction()) { $db->rollBack(); error_log("Rolled back DB updates."); }
    $response['errors'][] = ['itemId' => 'DB Update', 'message' => 'Failed to save placements: ' . $e->getMessage()];
    $response['message'] = "Placement calculation finished, but saving failed.";
}
// --- End Database Update ---


// --- Finalize Response ---
$response['success'] = true; 
if (count($response['errors']) > 0 && count($response['placements']) === 0) { $response['message'] = "Placement calculation finished. No items could be placed."; } 
elseif (count($response['errors']) > 0) { $placementErrors = array_filter($response['errors'], function($err) { return $err['itemId'] !== 'DB Update'; }); if (count($placementErrors) > 0 && count($response['placements']) === 0) { $response['message'] = "Placement calculation finished. No items could be placed."; } elseif (count($placementErrors) > 0) { $response['message'] = "Placement calculation finished. Some items could not be placed."; } elseif (count($response['placements']) > 0) { $response['message'] = "Placement calculation finished, but saving some results failed."; } else { $response['message'] = "Placement calculation finished. Saving results failed."; } } 
else { $response['message'] = "Placement calculation finished successfully."; }

echo json_encode($response, JSON_PRETTY_PRINT); 
$db = null; 
error_log("Placement API: Finished.");


// --- !!! HELPER FUNCTIONS !!! --- 
// Keep the findSpaceForItem, generateOrientations, boxesOverlap functions from the AI generation

/**
 * Find the optimal space ...
 */
function findSpaceForItem(array $itemToPlace, array $container, array $existingItemsInContainer): ?array
{
    // ... (Code generated by other AI - unchanged) ...
    $itemId = $itemToPlace['itemId'];
    error_log("Finding space for item $itemId in container " . $container['containerId']);
    $orientations = generateOrientations($itemToPlace);
    $bestPlacement = null;
    $lowestScore = PHP_FLOAT_MAX; 
    $stepSize = 5; // Using Step Size 5 to avoid timeout
    $containerW = (float)$container['width']; $containerD = (float)$container['depth']; $containerH = (float)$container['height'];
    foreach ($orientations as $index => $orientation) {
        $itemW = (float)$orientation['width']; $itemD = (float)$orientation['depth']; $itemH = (float)$orientation['height'];
        error_log("Item $itemId: Trying orientation $index (W:$itemW, D:$itemD, H:$itemH)");
        if ($itemW > $containerW || $itemD > $containerD || $itemH > $containerH) { error_log("Item $itemId: Orientation $index doesn't fit container bounds."); continue; }
        for ($x = 0; $x <= $containerW - $itemW; $x += $stepSize) {
            for ($y = 0; $y <= $containerD - $itemD; $y += $stepSize) { 
                for ($z = 0; $z <= $containerH - $itemH; $z += $stepSize) {
                    $potentialPlacement = ['x' => $x, 'y' => $y, 'z' => $z, 'w' => $itemW, 'd' => $itemD, 'h' => $itemH];
                    $hasCollision = false;                    
                    foreach ($existingItemsInContainer as $existingItem) {
                        if (boxesOverlap($potentialPlacement, $existingItem)) {
                            error_log("Item $itemId @ (".implode(',', $potentialPlacement)."): Collision DETECTED with existing item " . $existingItem['id'] . " @ (".implode(',', array_intersect_key($existingItem, array_flip(['x','y','z','w','d','h']))).")");
                            $hasCollision = true; break;
                        }
                    }                    
                    if (!$hasCollision) {
                         error_log("Item $itemId @ (".implode(',', array_intersect_key($potentialPlacement, array_flip(['x','y','z'])))."): NO collision found.");
                        $score = ($y * 1000000) + ($z * 1000) + $x;                         
                        if ($score < $lowestScore) {
                             error_log("Item $itemId: Found NEW BEST space at (X:$x Y:$y Z:$z) Orientation $index with score $score (Old score: $lowestScore)");
                            $lowestScore = $score;
                            $bestPlacement = [ 'pos_w' => $x, 'pos_d' => $y, 'pos_h' => $z, 'ori_w' => $itemW, 'ori_d' => $itemD, 'ori_h' => $itemH ];
                        }
                    }
                } // End Z
            } // End Y
        } // End X
    } // End Orientation
    if ($bestPlacement === null) { $logItemId = $itemToPlace['itemId'] ?? 'Unknown'; $logContainerId = $container['containerId'] ?? 'Unknown'; error_log("Item " . $logItemId . ": No valid placement found in container " . $logContainerId . " after checking all positions/orientations."); }
    return $bestPlacement; 
}

/**
 * Generate all possible orientations ...
 */
function generateOrientations(array $item): array
{
    // ... (Code generated by other AI - unchanged) ...
     $width = (float)$item['width']; $depth = (float)$item['depth']; $height = (float)$item['height'];
    return [
        ['width' => $width, 'depth' => $depth, 'height' => $height], ['width' => $width, 'depth' => $height, 'height' => $depth],
        ['width' => $depth, 'depth' => $width, 'height' => $height], ['width' => $depth, 'depth' => $height, 'height' => $width],
        ['width' => $height, 'depth' => $width, 'height' => $depth], ['width' => $height, 'depth' => $depth, 'height' => $width]
    ];
}

/**
 * Check if two 3D boxes overlap ...
 */
function boxesOverlap(array $box1, array $box2): bool
{
    // ... (Code generated by other AI - unchanged) ...
    $noOverlapX = ($box1['x'] >= $box2['x'] + $box2['w']) || ($box2['x'] >= $box1['x'] + $box1['w']);
    $noOverlapY = ($box1['y'] >= $box2['y'] + $box2['d']) || ($box2['y'] >= $box1['y'] + $box1['d']);
    $noOverlapZ = ($box1['z'] >= $box2['z'] + $box2['h']) || ($box2['z'] >= $box1['z'] + $box1['h']);
    return !($noOverlapX || $noOverlapY || $noOverlapZ);
}

?>