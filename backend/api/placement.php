<?php // backend/api/placement.php

ini_set('display_errors', 1); // Show errors for debugging (remove in production)
error_reporting(E_ALL); // Report all errors

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
    'success' => false, // Default to false until end
    'placements' => [],
    'rearrangements' => [], // Keep track of suggested moves
    'message' => '',
    'errors' => [] // Add errors array for items that couldn't be placed
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

$itemsToPlaceInput = $requestData['items'];     // Array of new items to place (should include dimensions, itemId, priority etc.)
$containersInput = $requestData['containers']; // Array of available containers (should include dimensions, containerId, zone)
error_log("Placement request received for " . count($itemsToPlaceInput) . " items using " . count($containersInput) . " containers.");


// --- Get Existing Item Placements from DB ---
$existingPlacedItemsByContainer = []; // Key: containerId, Value: array of items in that container
try {
    // Select items that HAVE a location AND their dimensions for collision checking
    // We need W, D, H as placed (assuming no rotation for now) and position
    $sqlPlaced = "SELECT itemId, name, currentContainerId, width, depth, height, pos_w, pos_d, pos_h 
                  FROM items 
                  WHERE currentContainerId IS NOT NULL 
                  AND pos_w IS NOT NULL 
                  AND pos_d IS NOT NULL 
                  AND pos_h IS NOT NULL";
                  
    $stmtPlaced = $db->prepare($sqlPlaced);
    $stmtPlaced->execute();
    $placedItemsResult = $stmtPlaced->fetchAll(PDO::FETCH_ASSOC);
    
    // Re-organize for easier lookup by containerId
    foreach ($placedItemsResult as $item) {
         $containerId = $item['currentContainerId'];
         if (!isset($existingPlacedItemsByContainer[$containerId])) {
             $existingPlacedItemsByContainer[$containerId] = [];
         }
         // Store item details needed for collision check
         $existingPlacedItemsByContainer[$containerId][] = [
             'id' => $item['itemId'],
             'name' => $item['name'], // For logging/debugging
             'x' => (float)$item['pos_w'], // Start W
             'y' => (float)$item['pos_d'], // Start D
             'z' => (float)$item['pos_h'], // Start H
             'w' => (float)$item['width'], // Dimension W
             'd' => (float)$item['depth'], // Dimension D
             'h' => (float)$item['height']  // Dimension H
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

// Make a copy of existing items to track newly placed items within this request
$currentPlacementState = $existingPlacedItemsByContainer; 

// Sort items to place? (e.g., by priority descending, or size descending?)
// usort($itemsToPlaceInput, function($a, $b) { return $b['priority'] <=> $a['priority']; }); // Example sort by priority

foreach ($itemsToPlaceInput as $itemToPlace) {
    $itemPlaced = false;
    error_log("Attempting to place item: " . $itemToPlace['itemId'] . " (Priority: " . ($itemToPlace['priority'] ?? 'N/A') . ")");

    // Validate item data needed for placement
    if (!isset($itemToPlace['itemId'], $itemToPlace['width'], $itemToPlace['depth'], $itemToPlace['height'])) {
         error_log("Skipping item " . ($itemToPlace['itemId'] ?? 'Unknown') . " - Missing required dimension data.");
         $response['errors'][] = ['itemId' => ($itemToPlace['itemId'] ?? 'Unknown'), 'message' => 'Missing dimension data for placement.'];
         continue; // Skip to next item
    }
    // Ensure dimensions are numeric
     if (!is_numeric($itemToPlace['width']) || !is_numeric($itemToPlace['depth']) || !is_numeric($itemToPlace['height']) ||
         $itemToPlace['width'] <= 0 || $itemToPlace['depth'] <= 0 || $itemToPlace['height'] <= 0) {
         error_log("Skipping item " . $itemToPlace['itemId'] . " - Invalid dimensions.");
         $response['errors'][] = ['itemId' => $itemToPlace['itemId'], 'message' => 'Invalid dimensions provided for placement.'];
         continue; // Skip to next item
     }


    // ** TODO: Implement preferred zone logic here if desired **
    // Filter or prioritize $containersInput based on $itemToPlace['preferredZone']

    foreach ($containersInput as $container) {
        // Validate container data
        if (!isset($container['containerId'], $container['width'], $container['depth'], $container['height'])) {
            error_log("Skipping container " . ($container['containerId'] ?? 'Unknown') . " - Missing required dimension data.");
            continue; // Skip to next container
        }
         if (!is_numeric($container['width']) || !is_numeric($container['depth']) || !is_numeric($container['height'])) {
            error_log("Skipping container " . $container['containerId'] . " - Invalid dimensions.");
            continue; // Skip to next container
         }


        $containerId = $container['containerId'];
        error_log("Trying container: " . $containerId);

        // Get items currently considered to be in this container (existing + newly placed in this request)
        $itemsCurrentlyInContainer = $currentPlacementState[$containerId] ?? [];

        // --- !!! CORE PLACEMENT FUNCTION CALL !!! ---
        // This function needs to be implemented properly
        $coords = findSpaceForItem($itemToPlace, $container, $itemsCurrentlyInContainer);
        // --- !!! ----------------------------- !!! ---

        if ($coords !== null) {
            error_log("Found space for item " . $itemToPlace['itemId'] . " in container " . $containerId . " at W:" . $coords['pos_w'] . ", D:" . $coords['pos_d'] . ", H:" . $coords['pos_h']);

            // ** TODO: Determine actual dimensions used (based on rotation if implemented) **
            $itemPlacedWidth = (float)$itemToPlace['width']; // Assuming no rotation for now
            $itemPlacedDepth = (float)$itemToPlace['depth'];
            $itemPlacedHeight = (float)$itemToPlace['height'];

            // Add to response placements
            $response['placements'][] = [
                'itemId' => $itemToPlace['itemId'],
                'containerId' => $containerId,
                'position' => [
                    'startCoordinates' => ['width' => $coords['pos_w'], 'depth' => $coords['pos_d'], 'height' => $coords['pos_h']],
                    'endCoordinates' => [
                         'width' => $coords['pos_w'] + $itemPlacedWidth, 
                         'depth' => $coords['pos_d'] + $itemPlacedDepth,
                         'height' => $coords['pos_h'] + $itemPlacedHeight
                     ]
                ]
            ];

            // Update the temporary placement state for collision checking of subsequent items
            if (!isset($currentPlacementState[$containerId])) {
                $currentPlacementState[$containerId] = [];
            }
            $currentPlacementState[$containerId][] = [
                 'id' => $itemToPlace['itemId'],
                 'name' => $itemToPlace['name'] ?? 'Unknown', 
                 'x' => (float)$coords['pos_w'], 
                 'y' => (float)$coords['pos_d'], 
                 'z' => (float)$coords['pos_h'], 
                 'w' => $itemPlacedWidth, 
                 'd' => $itemPlacedDepth, 
                 'h' => $itemPlacedHeight  
            ];

            $itemPlaced = true;
            break; // Exit container loop, move to next item
        } else {
             error_log("No space found for item " . $itemToPlace['itemId'] . " in container " . $containerId);
        }
    } // End loop through containers

    if (!$itemPlaced) {
        error_log("Could not find placement for item: " . $itemToPlace['itemId']);
        $response['errors'][] = ['itemId' => $itemToPlace['itemId'], 'message' => 'Could not find suitable placement space.'];
        // ** TODO: Implement rearrangement suggestion logic here? **
    }

} // End loop through items to place


// --- Finalize Response ---
$response['success'] = true; // API call succeeded, even if not all items were placed
if (count($response['errors']) > 0 && count($response['placements']) === 0) {
     $response['message'] = "Placement calculation finished. No items could be placed.";
     // Maybe set success to false if nothing could be placed? Optional.
     // $response['success'] = false; 
     // http_response_code(409); // Conflict - maybe?
} elseif (count($response['errors']) > 0) {
    $response['message'] = "Placement calculation finished. Some items could not be placed.";
} else {
     $response['message'] = "Placement calculation finished successfully.";
}


echo json_encode($response, JSON_PRETTY_PRINT); 
$db = null; // Close connection
error_log("Placement API: Finished.");


// --- !!! HELPER FUNCTIONS !!! ---

/**
 * Tries to find a valid position for an item within a container, avoiding existing items.
 * Includes basic iteration along X, Y, Z and proper collision detection.
 * Rotation logic is present but commented out initially.
 * Prioritizes lower Y (depth).
 *
 * @param array $itemToPlace The item to place (needs 'itemId', 'width', 'depth', 'height')
 * @param array $container The container (needs 'containerId', 'width', 'depth', 'height')
 * @param array $existingItemsInContainer Array of items already in the container 
 *                                        (each needs 'x', 'y', 'z', 'w', 'd', 'h')
 * @return array|null Associative array ['pos_w', 'pos_d', 'pos_h', 'ori_w', 'ori_d', 'ori_h'] 
 *                    representing position and orientation dimensions used, or null if no space.
 */
function findSpaceForItem(array $itemToPlace, array $container, array $existingItemsInContainer): ?array {
    $itemId = $itemToPlace['itemId']; 
    error_log("Finding space for item $itemId in container " . $container['containerId']);
    
    $containerW = (float)$container['width'];
    $containerD = (float)$container['depth'];
    $containerH = (float)$container['height'];

    $orientations = [
        [(float)$itemToPlace['width'], (float)$itemToPlace['depth'], (float)$itemToPlace['height']], 
        // --- Optional: Add Rotations (Uncomment to enable) ---
        /*
        [(float)$itemToPlace['height'], (float)$itemToPlace['depth'], (float)$itemToPlace['width']], 
        [(float)$itemToPlace['width'], (float)$itemToPlace['height'], (float)$itemToPlace['depth']], 
        [(float)$itemToPlace['depth'], (float)$itemToPlace['width'], (float)$itemToPlace['height']],
        [(float)$itemToPlace['depth'], (float)$itemToPlace['height'], (float)$itemToPlace['width']],
        [(float)$itemToPlace['height'], (float)$itemToPlace['width'], (float)$itemToPlace['depth']],
        */
    ];

    foreach ($orientations as $index => $dims) {
        $itemW = $dims[0];
        $itemD = $dims[1];
        $itemH = $dims[2];
        error_log("Item $itemId: Trying orientation $index (W:$itemW, D:$itemD, H:$itemH)");

        if ($itemW > $containerW || $itemD > $containerD || $itemH > $containerH) {
            error_log("Item $itemId: Orientation $index doesn't fit container bounds.");
            continue; 
        }

        // --- Iterate through possible positions (Depth-first, then Width, then Height) ---
        // Iterate Y (depth) from front to back (prioritize front)
        for ($y = 0; $y <= $containerD - $itemD; $y++) { 
            // Iterate X (width) from left to right
            for ($x = 0; $x <= $containerW - $itemW; $x++) { 
                // Iterate Z (height) from bottom to top
                for ($z = 0; $z <= $containerH - $itemH; $z++) { 

                    // Define the bounding box for the item at this potential position
                    $itemBox = ['x' => $x, 'y' => $y, 'z' => $z, 'w' => $itemW, 'd' => $itemD, 'h' => $itemH];

                    // --- Collision Check ---
                    $collision = false;
                    foreach ($existingItemsInContainer as $existingItem) {
                         // $existingItem already has x, y, z, w, d, h keys
                         if (boxesOverlap($itemBox, $existingItem)) {
                            // Optional: Log collision details if needed for debugging
                            // error_log("Item $itemId: Collision at (X:$x Y:$y Z:$z) Orientation $index with existing " . $existingItem['id']);
                            $collision = true;
                            break; // Collision found, no need to check other existing items for this spot
                         }
                    }
                    // --- End Collision Check ---

                    // If NO collision detected for this position (x, y, z) and orientation
                    if (!$collision) {
                        error_log("Item $itemId: Found valid space in container " . $container['containerId'] . " at (X:$x, Y:$y, Z:$z) using orientation $index.");
                        return [
                            'pos_w' => $x, 
                            'pos_d' => $y, 
                            'pos_h' => $z,
                            'ori_w' => $itemW, 
                            'ori_d' => $itemD,
                            'ori_h' => $itemH
                        ];
                    }
                } // End Z loop
            } // End X loop
        } // End Y loop
    } // End loop through orientations

    error_log("Item $itemId: No valid space found in container " . $container['containerId'] . " after trying all positions/orientations.");
    return null;
}
/**
 * Checks if two 3D axis-aligned bounding boxes (AABB) overlap.
 * Each box is defined by its minimum corner (x, y, z) and its dimensions (w, d, h).
 *
 * @param array $box1 Associative array with keys 'x', 'y', 'z', 'w', 'd', 'h'.
 * @param array $box2 Associative array with keys 'x', 'y', 'z', 'w', 'd', 'h'.
 * @return bool True if the boxes overlap, false otherwise.
 */
function boxesOverlap(array $box1, array $box2): bool {
    // Check for overlap on each axis
    $xOverlap = ($box1['x'] < $box2['x'] + $box2['w']) && ($box1['x'] + $box1['w'] > $box2['x']);
    $yOverlap = ($box1['y'] < $box2['y'] + $box2['d']) && ($box1['y'] + $box1['d'] > $box2['y']);
    $zOverlap = ($box1['z'] < $box2['z'] + $box2['h']) && ($box1['z'] + $box1['h'] > $box2['z']);

    // Overlap occurs if and only if there is overlap on ALL three axes
    return $xOverlap && $yOverlap && $zOverlap;
}
?>