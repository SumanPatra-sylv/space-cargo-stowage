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

// Optional: Sort items (e.g., by priority)
// usort($itemsToPlaceInput, function($a, $b) { return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0); }); 

foreach ($itemsToPlaceInput as $itemToPlace) {
    $itemPlaced = false;
    $currentItemId = $itemToPlace['itemId'] ?? 'Unknown'; // Get ID for logging
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

    // ** TODO: Implement preferred zone logic here **

    foreach ($containersInput as $container) {
        // Validate container data
         if (!isset($container['containerId'], $container['width'], $container['depth'], $container['height'])) {
            error_log("Skipping container " . ($container['containerId'] ?? 'Unknown') . " - Missing dimension data.");
            continue; 
        }
         if (!is_numeric($container['width']) || !is_numeric($container['depth']) || !is_numeric($container['height'])) {
            error_log("Skipping container " . $container['containerId'] . " - Invalid dimensions.");
            continue; 
         }

        $containerId = $container['containerId'];
        error_log("Trying container: " . $containerId . " for item " . $currentItemId);

        $itemsCurrentlyInContainer = $currentPlacementState[$containerId] ?? [];

        // --- !!! CORE PLACEMENT FUNCTION CALL (using new best-fit logic) !!! ---
        $bestCoords = findSpaceForItem($itemToPlace, $container, $itemsCurrentlyInContainer);
        // --- !!! ---------------------------------------------------------- !!! ---

        if ($bestCoords !== null) {
            // Extract details from the best placement found
            $posW = (float)$bestCoords['pos_w'];
            $posD = (float)$bestCoords['pos_d'];
            $posH = (float)$bestCoords['pos_h'];
            $itemPlacedWidth = (float)$bestCoords['ori_w']; 
            $itemPlacedDepth = (float)$bestCoords['ori_d'];
            $itemPlacedHeight = (float)$bestCoords['ori_h'];

            error_log("Found BEST space for item " . $currentItemId . " in container " . $containerId . " at W:" . $posW . ", D:" . $posD . ", H:" . $posH . " (using dims W:$itemPlacedWidth, D:$itemPlacedDepth, H:$itemPlacedHeight)");

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
                // Optional: Add orientation details if needed by frontend
                // 'orientation_dimensions' => ['width' => $itemPlacedWidth, 'depth' => $itemPlacedDepth, 'height' => $itemPlacedHeight]
            ];

            // Update the temporary placement state for collision checking of subsequent items
            if (!isset($currentPlacementState[$containerId])) {
                $currentPlacementState[$containerId] = [];
            }
            $currentPlacementState[$containerId][] = [
                 'id' => $currentItemId,
                 'name' => $itemToPlace['name'] ?? 'Unknown', 
                 'x' => $posW, 'y' => $posD, 'z' => $posH, 
                 'w' => $itemPlacedWidth, 'd' => $itemPlacedDepth, 'h' => $itemPlacedHeight  
            ];

            $itemPlaced = true;
            break; // Exit container loop, move to next item
        } else {
             error_log("No space found for item " . $currentItemId . " in container " . $containerId . " (checked all positions/orientations).");
        }
    } // End loop through containers

    if (!$itemPlaced) {
        error_log("Could not find placement for item: " . $currentItemId . " in any container.");
        $response['errors'][] = ['itemId' => $currentItemId, 'message' => 'Could not find suitable placement space.'];
        // ** TODO: Implement rearrangement suggestion logic here? **
    }

} // End loop through items to place


// --- Update Database with Successful Placements --- 
//  (Keep the database update block you added previously - it uses $response['placements'])
error_log("Attempting to update database with " . count($response['placements']) . " successful placements.");
$updateSql = "UPDATE items SET currentContainerId = :containerId, pos_w = :pos_w, pos_d = :pos_d, pos_h = :pos_h, width = :ori_w, depth = :ori_d, height = :ori_h, status = 'stowed' WHERE itemId = :itemId";
try {
    $updateStmt = $db->prepare($updateSql);
    $updatedCount = 0;
    if (!$db->inTransaction()) { $db->beginTransaction(); } // Start transaction if needed

    foreach ($response['placements'] as $placement) {
        $itemId = $placement['itemId'];
        $containerId = $placement['containerId'];
        $posW = $placement['position']['startCoordinates']['width'];
        $posD = $placement['position']['startCoordinates']['depth'];
        $posH = $placement['position']['startCoordinates']['height'];
        
        // Retrieve dimensions used for placement (stored in $currentPlacementState)
        $placedDims = null;
        if (isset($currentPlacementState[$containerId])) {
            foreach ($currentPlacementState[$containerId] as $placedItem) {
                 if ($placedItem['id'] === $itemId) { $placedDims = $placedItem; break; }
            }
        }

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
$response['success'] = true; // Still true if API ran, even with errors
if (count($response['errors']) > 0 && count($response['placements']) === 0) {
     $response['message'] = "Placement calculation finished. No items could be placed.";
} elseif (count($response['errors']) > 0) {
    // Filter out DB update errors from placement errors for message
    $placementErrors = array_filter($response['errors'], function($err) { return $err['itemId'] !== 'DB Update'; });
     if (count($placementErrors) > 0 && count($response['placements']) === 0) {
          $response['message'] = "Placement calculation finished. No items could be placed.";
     } elseif (count($placementErrors) > 0) {
         $response['message'] = "Placement calculation finished. Some items could not be placed.";
     } elseif (count($response['placements']) > 0) {
          // Only DB errors occurred
          $response['message'] = "Placement calculation finished, but saving some results failed.";
     } else {
          // Only DB errors occurred, and nothing was placed anyway
          $response['message'] = "Placement calculation finished. Saving results failed.";
     }
} else {
     $response['message'] = "Placement calculation finished successfully.";
}


echo json_encode($response, JSON_PRETTY_PRINT); 
$db = null; 
error_log("Placement API: Finished.");


// --- !!! HELPER FUNCTIONS !!! ---

/**
 * Find the optimal space for placing an item in a container with existing items
 * Uses "Best Fit" heuristic based on score (lowest Y, then Z, then X).
 * Includes rotation and proper collision check.
 * 
 * @param array $itemToPlace Associative array with 'itemId', 'width', 'depth', 'height'
 * @param array $container Associative array with 'containerId', 'width', 'depth', 'height'
 * @param array $existingItemsInContainer Array of associative arrays, each with 'x', 'y', 'z', 'w', 'd', 'h'
 * @return array|null Associative array with position and orientation details, or null if no valid placement found
 */
function findSpaceForItem(array $itemToPlace, array $container, array $existingItemsInContainer): ?array
{
    $itemId = $itemToPlace['itemId'];
    error_log("Finding space for item $itemId in container " . $container['containerId']);

    // Generate all possible orientations of the item
    $orientations = generateOrientations($itemToPlace);
    
    $bestPlacement = null;
    $lowestScore = PHP_FLOAT_MAX; // Use float max for score comparison
    
    // Step size for position iteration
    $stepSize = 1; // Check every 1 unit
    
    $containerW = (float)$container['width'];
    $containerD = (float)$container['depth'];
    $containerH = (float)$container['height'];
    
    // Try each orientation
    foreach ($orientations as $index => $orientation) {
        $itemW = (float)$orientation['width'];
        $itemD = (float)$orientation['depth'];
        $itemH = (float)$orientation['height'];
        error_log("Item $itemId: Trying orientation $index (W:$itemW, D:$itemD, H:$itemH)");
        
        // Skip orientations that don't fit in the container
        if ($itemW > $containerW || $itemD > $containerD || $itemH > $containerH) {
            error_log("Item $itemId: Orientation $index doesn't fit container bounds.");
            continue;
        }
        
        // Iterate through possible positions with step size (X, Y, Z order from generated code)
        for ($x = 0; $x <= $containerW - $itemW; $x += $stepSize) {
            for ($y = 0; $y <= $containerD - $itemD; $y += $stepSize) { // Prioritize Y in scoring, not necessarily loop order
                for ($z = 0; $z <= $containerH - $itemH; $z += $stepSize) {
                    
                    $potentialPlacement = ['x' => $x, 'y' => $y, 'z' => $z, 'w' => $itemW, 'd' => $itemD, 'h' => $itemH];
                    
                    // Check for collisions
                    $hasCollision = false;
                    foreach ($existingItemsInContainer as $existingItem) {
                        if (boxesOverlap($potentialPlacement, $existingItem)) {
                            $hasCollision = true;
                            break;
                        }
                    }
                    
                    // If no collision, calculate placement score
                    if (!$hasCollision) {
                        // Score prioritizes lowest y, then lowest z, then lowest x
                        // Using large multipliers to ensure priority order
                        $score = ($y * 1000000) + ($z * 1000) + $x; 
                        
                        if ($score < $lowestScore) {
                             error_log("Item $itemId: Found NEW BEST space at (X:$x Y:$y Z:$z) Orientation $index with score $score (Old score: $lowestScore)");
                            $lowestScore = $score;
                            $bestPlacement = [
                                'pos_w' => $x, 'pos_d' => $y, 'pos_h' => $z,
                                'ori_w' => $itemW, 'ori_d' => $itemD, 'ori_h' => $itemH
                            ];
                            // Optimization: If we find a spot at Y=0, we could potentially stop checking larger Y for this orientation,
                            // but for simplicity, we check all spots and keep the absolute best score overall.
                        }
                    }
                } // End Z loop
            } // End Y loop
        } // End X loop
    } // End Orientation loop
    
    if ($bestPlacement === null) {
         error_log("Item $itemId: No valid placement found in container " . $container['containerId'] . " after checking all positions/orientations.");
    }
    
    return $bestPlacement;
}

/**
 * Generate all possible orientations of an item (6 permutations)
 * 
 * @param array $item Associative array with 'width', 'depth', 'height'
 * @return array Array of associative arrays, each with 'width', 'depth', 'height'
 */
function generateOrientations(array $item): array
{
    $width = (float)$item['width']; // Ensure float
    $depth = (float)$item['depth'];
    $height = (float)$item['height'];
    
    // Use associative keys for clarity
    return [
        ['width' => $width, 'depth' => $depth, 'height' => $height], // Original
        ['width' => $width, 'depth' => $height, 'height' => $depth], // Rotated on X axis
        ['width' => $depth, 'depth' => $width, 'height' => $height], // Rotated on Z axis
        ['width' => $depth, 'depth' => $height, 'height' => $width], // Rotated Z then X
        ['width' => $height, 'depth' => $width, 'height' => $depth], // Rotated Y then Z (or X then Y)
        ['width' => $height, 'depth' => $depth, 'height' => $width]  // Rotated on Y axis
    ];
}

/**
 * Check if two 3D axis-aligned bounding boxes (AABB) overlap.
 * Uses separating axis theorem concept (no gap = overlap).
 * 
 * @param array $box1 Associative array with 'x', 'y', 'z', 'w', 'd', 'h'
 * @param array $box2 Associative array with 'x', 'y', 'z', 'w', 'd', 'h'
 * @return bool True if boxes overlap, false otherwise
 */
function boxesOverlap(array $box1, array $box2): bool
{
    // Check for non-overlap (separation) on each axis
    // If there is a gap on ANY axis, they don't overlap
    $noOverlapX = ($box1['x'] >= $box2['x'] + $box2['w']) || ($box2['x'] >= $box1['x'] + $box1['w']);
    $noOverlapY = ($box1['y'] >= $box2['y'] + $box2['d']) || ($box2['y'] >= $box1['y'] + $box1['d']);
    $noOverlapZ = ($box1['z'] >= $box2['z'] + $box2['h']) || ($box2['z'] >= $box1['z'] + $box1['h']);
    
    // If there's no gap on ANY axis, then they DO overlap
    return !($noOverlapX || $noOverlapY || $noOverlapZ);
}

?>