<?php
// --- File: backend/placement.php (Modified for central index.php handling) ---

/* --- REMOVED/COMMENTED OUT - Handled by index.php ---
// 1. Set Headers & Error Reporting
// header("Access-Control-Allow-Origin: http://localhost:5173"); // Adjust if needed - Handled by index.php
// header("Content-Type: application/json; charset=UTF-8"); // Handled by index.php
// header("Access-Control-Allow-Methods: POST, OPTIONS"); // Handled by index.php
// header("Access-Control-Max-Age: 3600"); // Handled by index.php
// header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With"); // Handled by index.php
//
// if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
//     http_response_code(200); // Note: index.php uses 200 for OPTIONS
//     exit();
// }

// ini_set('display_errors', 0); // Handled by index.php
// error_reporting(E_ALL);      // Handled by index.php
--- END REMOVED/COMMENTED OUT --- */


// --- Include DB & Setup Response ---
// Use __DIR__ for robustness when included by index.php
require_once __DIR__ . '/database.php'; // Defines getDbConnection()

$response = [
    'success' => false, // Will be set to true later if process completes
    'placements' => [],
    'rearrangements' => [] // Required by spec, currently unused
];
$internalErrors = []; // Keep track of non-fatal errors for logging

// --- Get Database Connection ---
$db = null; // Initialize $db
try {
    $db = getDbConnection();
    if ($db === null) {
        http_response_code(503); // Service Unavailable
        // index.php already set Content-Type
        echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
        error_log("Placement Error: Database connection failed.");
        exit(); // Exit on critical failure
    }
} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    // index.php already set Content-Type
    echo json_encode(['success' => false, 'message' => 'Error establishing database connection.']);
    error_log("Placement Error: DB connection exception: " . $e->getMessage());
    exit(); // Exit on critical failure
}


// --- Handle Input ---
$rawData = file_get_contents('php://input');
$requestData = json_decode($rawData, true);

if ($requestData === null || !isset($requestData['items']) || !isset($requestData['containers']) || !is_array($requestData['items']) || !is_array($requestData['containers'])) {
    http_response_code(400); // Bad Request
    // index.php already set Content-Type
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input. "items" and "containers" arrays are required.']);
    error_log("Placement Error: Invalid JSON input: " . $rawData);
    exit(); // Exit on invalid input
}

$itemsToPlaceInput = $requestData['items'];
$containersInput = $requestData['containers'];
error_log("Placement request: " . count($itemsToPlaceInput) . " items, " . count($containersInput) . " containers.");


// --- Get Existing Item Placements from DB ---
$existingPlacedItemsByContainer = [];
try {
    // --- !!! IMPORTANT: Verify these DB column names match your 'items' table !!! ---
    $sqlPlaced = "SELECT
                      i.itemId,          /* Assumed column name */
                      i.containerId,     /* Assumed column name */
                      i.placedDimensionW AS width,  /* Assumed column name -> API/helper key */
                      i.placedDimensionD AS depth,  /* Assumed column name -> API/helper key */
                      i.placedDimensionH AS height, /* Assumed column name -> API/helper key */
                      i.positionX AS posX, /* Assumed column name -> API/helper key */
                      i.positionY AS posY, /* Assumed column name -> API/helper key */
                      i.positionZ AS posZ  /* Assumed column name -> API/helper key */
                  FROM items i
                  WHERE
                      i.containerId IS NOT NULL
                      AND i.positionX IS NOT NULL AND i.positionY IS NOT NULL AND i.positionZ IS NOT NULL
                      AND i.status = 'stowed'"; // Only consider currently stowed items

    $stmtPlaced = $db->prepare($sqlPlaced);
    $stmtPlaced->execute();
    $placedItemsResult = $stmtPlaced->fetchAll(PDO::FETCH_ASSOC);

    foreach ($placedItemsResult as $item) {
        $containerId = $item['containerId'];
        if (!isset($existingPlacedItemsByContainer[$containerId])) {
            $existingPlacedItemsByContainer[$containerId] = [];
        }
        // Ensure data types are correct for boxesOverlap function (using API/helper keys)
        $existingPlacedItemsByContainer[$containerId][] = [
            'id' => $item['itemId'], // Use 'id' for consistency if needed by helpers
            'x' => (float)$item['posX'], 'y' => (float)$item['posY'], 'z' => (float)$item['posZ'], // Use x,y,z for helpers
            'w' => (float)$item['width'], 'd' => (float)$item['depth'], 'h' => (float)$item['height'] // Use w,d,h for helpers
        ];
    }
    error_log("Found existing placements in " . count($existingPlacedItemsByContainer) . " containers.");

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    $response['success'] = false; // Ensure success is false
    $response['message'] = "Database error fetching existing items."; // Add message for clarity
    // index.php already set Content-Type
    echo json_encode($response);
    error_log("Placement DB Error (fetch existing): " . $e->getMessage());
    $db = null; // Close connection
    exit(); // Exit on DB error
}


// --- Placement Algorithm Logic ---
$currentPlacementState = $existingPlacedItemsByContainer; // Start with existing items
$dbUpdates = []; // Store successful placements for DB update

foreach ($itemsToPlaceInput as $itemToPlace) {
    $itemPlaced = false;
    $currentItemId = $itemToPlace['itemId'] ?? 'Unknown';
    error_log("Attempting placement for item: " . $currentItemId . " (Priority: " . ($itemToPlace['priority'] ?? 'N/A') . ")");

    // Validate essential item data from input
    if (!isset($itemToPlace['itemId'], $itemToPlace['width'], $itemToPlace['depth'], $itemToPlace['height']) ||
        !is_numeric($itemToPlace['width']) || !is_numeric($itemToPlace['depth']) || !is_numeric($itemToPlace['height']) ||
        $itemToPlace['width'] <= 0 || $itemToPlace['depth'] <= 0 || $itemToPlace['height'] <= 0) {
        error_log("Skipping item $currentItemId - Invalid or missing dimension data.");
        $internalErrors[] = ['itemId' => $currentItemId, 'reason' => 'Invalid/missing dimension data.'];
        continue;
    }

    // Input dimensions are already width/depth/height
    $itemDimensionsApi = ['width' => (float)$itemToPlace['width'], 'depth' => (float)$itemToPlace['depth'], 'height' => (float)$itemToPlace['height']];

    // --- Preferred Zone Logic ---
    $preferredZone = $itemToPlace['preferredZone'] ?? null;
    $containersToTry = [];

    // Create prioritized list of containers
    if ($preferredZone !== null) {
        // Add preferred zone containers first
        foreach ($containersInput as $c) {
            if (isset($c['zone']) && $c['zone'] === $preferredZone && isset($c['containerId'])) { $containersToTry[] = $c; }
        }
        // Add other containers after
        foreach ($containersInput as $c) {
            if ((!isset($c['zone']) || $c['zone'] !== $preferredZone) && isset($c['containerId'])) { $containersToTry[] = $c; }
        }
    } else {
        // If no preference, try all
        $containersToTry = $containersInput;
    }

    // --- Try placing in prioritized containers ---
    foreach ($containersToTry as $container) {
        // Basic container validation
        if (!isset($container['containerId'], $container['width'], $container['depth'], $container['height']) ||
            !is_numeric($container['width']) || !is_numeric($container['depth']) || !is_numeric($container['height']) ||
            $container['width'] <=0 || $container['depth'] <= 0 || $container['height'] <= 0 ) {
                error_log("Skipping invalid container data: " . ($container['containerId'] ?? 'Unknown ID'));
                continue;
             }

        $containerId = $container['containerId'];
        $containerDimensionsApi = ['width' => (float)$container['width'], 'depth' => (float)$container['depth'], 'height' => (float)$container['height']];
        error_log("Item $currentItemId: Trying container: $containerId (Zone: " . ($container['zone'] ?? 'N/A') . ")");

        $itemsCurrentlyInContainer = $currentPlacementState[$containerId] ?? [];

        // --- Call Helper Function ---
        // Pass API dimensions, container info, and current state for this container
        $bestCoords = findSpaceForItem($currentItemId, $itemDimensionsApi, $containerDimensionsApi, $itemsCurrentlyInContainer);

        if ($bestCoords !== null) {
            // --- Placement Found ---
            $foundX = (float)$bestCoords['foundX']; // Position returned by helper
            $foundY = (float)$bestCoords['foundY'];
            $foundZ = (float)$bestCoords['foundZ'];
            $placedW = (float)$bestCoords['placedW']; // Dimensions as placed by helper
            $placedD = (float)$bestCoords['placedD'];
            $placedH = (float)$bestCoords['placedH'];
            error_log("Item $currentItemId: Found space in container $containerId at X:$foundX, Y:$foundY, Z:$foundZ (Placed Dims W:$placedW, D:$placedD, H:$placedH)");

            // 1. Prepare data for DB Update (Using ASSUMED DB column names)
            $dbUpdateData = [
                'itemId' => $currentItemId,
                'containerId' => $containerId,
                'positionX' => $foundX,         // Assumed DB column
                'positionY' => $foundY,         // Assumed DB column
                'positionZ' => $foundZ,         // Assumed DB column
                'placedDimensionW' => $placedW, // Assumed DB column
                'placedDimensionD' => $placedD, // Assumed DB column
                'placedDimensionH' => $placedH, // Assumed DB column
            ];
            $dbUpdates[] = $dbUpdateData; // Add to list for DB update transaction

            // 2. Prepare data for API Response (Matching the PDF Spec)
            $placementResultApi = [
                'itemId' => $currentItemId,
                'containerId' => $containerId,
                'position' => [
                    'startCoordinates' => [ // API uses width/depth/height for coords too
                        'width' => $foundX, // Map X to Width for API
                        'depth' => $foundY, // Map Y to Depth for API
                        'height' => $foundZ  // Map Z to Height for API
                    ],
                    'endCoordinates' => [ // Calculate end based on start + placed dimensions
                        'width' => $foundX + $placedW,
                        'depth' => $foundY + $placedD,
                        'height' => $foundZ + $placedH
                    ]
                ]
            ];
            $response['placements'][] = $placementResultApi; // Add to final response

            // 3. Update temporary state for subsequent placements in this run
            if (!isset($currentPlacementState[$containerId])) { $currentPlacementState[$containerId] = []; }
            $currentPlacementState[$containerId][] = [
                 'id' => $currentItemId, // Keep using 'id' if helpers expect it
                 'x' => $foundX, 'y' => $foundY, 'z' => $foundZ, // Use x,y,z for helpers
                 'w' => $placedW, 'd' => $placedD, 'h' => $placedH  // Use w,d,h for helpers
            ];

            $itemPlaced = true;
            break; // Exit container loop for this item
        } else {
            error_log("Item $currentItemId: No space found in container $containerId.");
        }
    } // End loop through containersToTry

    if (!$itemPlaced) {
        error_log("Item $currentItemId: Could not find placement in any suitable container.");
        $internalErrors[] = ['itemId' => $currentItemId, 'reason' => 'No suitable placement space found.'];
    }

} // End loop through itemsToPlace


// --- Update Database with Successful Placements ---
if (!empty($dbUpdates)) {
    error_log("Attempting database update for " . count($dbUpdates) . " placements.");
    // --- !!! IMPORTANT: Verify these DB column names match your 'items' table !!! ---
    $updateSql = "UPDATE items SET
                      containerId = :containerId,     /* Assumed column name */
                      positionX = :positionX,         /* Assumed column name */
                      positionY = :positionY,         /* Assumed column name */
                      positionZ = :positionZ,         /* Assumed column name */
                      placedDimensionW = :placedW,    /* Assumed column name */
                      placedDimensionD = :placedD,    /* Assumed column name */
                      placedDimensionH = :placedH,    /* Assumed column name */
                      status = 'stowed',              /* Assumed column name exists */
                      lastUpdated = :lastUpdated      /* Assumed column name */
                  WHERE itemId = :itemId";           /* Assumed column name */

    try {
        $updateStmt = $db->prepare($updateSql);
        $updatedCount = 0;
        $lastUpdated = date('Y-m-d H:i:s');

        if (!$db->inTransaction()) { $db->beginTransaction(); }

        foreach ($dbUpdates as $placement) {
            // Bind parameters based on the $dbUpdateData structure created earlier
            $updateStmt->bindParam(':containerId', $placement['containerId']);
            $updateStmt->bindParam(':positionX', $placement['positionX']);
            $updateStmt->bindParam(':positionY', $placement['positionY']);
            $updateStmt->bindParam(':positionZ', $placement['positionZ']);
            $updateStmt->bindParam(':placedW', $placement['placedDimensionW']);
            $updateStmt->bindParam(':placedD', $placement['placedDimensionD']);
            $updateStmt->bindParam(':placedH', $placement['placedDimensionH']);
            $updateStmt->bindParam(':lastUpdated', $lastUpdated);
            $updateStmt->bindParam(':itemId', $placement['itemId']);

            if ($updateStmt->execute()) {
                $updatedCount++;
            } else {
                $errorInfo = $updateStmt->errorInfo();
                error_log("Failed DB update for itemId: " . $placement['itemId'] . " - Error: " . ($errorInfo[2] ?? 'Unknown PDO Error'));
                $internalErrors[] = ['itemId' => $placement['itemId'], 'reason' => 'Database update failed.'];
                // Throw exception to force rollback on first error?
                throw new PDOException("Failed DB update for itemId: " . $placement['itemId'] . " - " . ($errorInfo[2] ?? 'Unknown PDO Error'));
            }
        }

        // If loop completed without exception, commit
        if ($db->inTransaction()) {
            $db->commit();
            error_log("Committed DB updates for $updatedCount items.");
            $response['success'] = true; // Set success true only if DB commit is successful
        }

    } catch (PDOException $e) {
         if ($db->inTransaction()) {
             $db->rollBack();
             error_log("Rolled back DB updates due to error.");
         }
         http_response_code(500); // Internal Server Error
         $response['success'] = false;
         $response['placements'] = []; // Clear placements on major DB error
         $response['message'] = "Database error during update transaction. Changes rolled back."; // Add generic message
         // index.php already set Content-Type
         echo json_encode($response); // Echo partial response on error
         error_log("Placement DB Error (update): " . $e->getMessage());
         $db = null; // Close connection
         exit(); // Exit after sending error response
    }

} else {
    error_log("No successful placements to update in the database.");
    // Determine success based on whether items were expected to be placed
    if (count($itemsToPlaceInput) > 0) {
       // Items were provided, but none could be placed
       $response['success'] = false; // Considered failure if input items couldn't be placed
       $response['message'] = "No suitable space found for the provided items.";
    } else {
       // No items were provided, so the operation (placing nothing) succeeded
       $response['success'] = true;
       $response['message'] = "No items provided for placement.";
    }
}
// --- End Database Update ---


// --- Finalize and Echo Response ---
// Set status code based on overall success, default to 200 if not set previously
if (http_response_code() === false || http_response_code() < 400) {
    http_response_code($response['success'] ? 200 : 400); // 200 OK or 400 Bad Request (e.g., no space found)
}

// Only include keys specified in the API documentation
$finalResponse = [
    'success' => $response['success'],
    'placements' => $response['placements'],
    'rearrangements' => $response['rearrangements'] // Still empty []
];
if (!empty($response['message'])) {
     $finalResponse['message'] = $response['message']; // Include message if set
}

// index.php already set Content-Type
echo json_encode($finalResponse);
error_log("Placement API: Finished. Success: " . ($finalResponse['success'] ? 'Yes' : 'No') . ". Placed: " . count($finalResponse['placements']) . ". Internal Errors: " . count($internalErrors));
$db = null; // Ensure connection is closed

// Let index.php handle script termination

// --- HELPER FUNCTIONS (remain unchanged) ---

/**
 * Finds the best available position for an item in a container.
 *
 * @param string $itemId ID of the item being placed (for logging).
 * @param array $itemDimensionsApi {'width', 'depth', 'height'} of the item.
 * @param array $containerDimensionsApi {'width', 'depth', 'height'} of the container.
 * @param array $existingItems An array of already placed items in the container,
 *                             each item represented as {'id', 'x', 'y', 'z', 'w', 'd', 'h'}.
 * @return array|null An array {'foundX', 'foundY', 'foundZ', 'placedW', 'placedD', 'placedH'}
 *                   representing the position and dimensions used for placement, or null if no space found.
 */
function findSpaceForItem(string $itemId, array $itemDimensionsApi, array $containerDimensionsApi, array $existingItems): ?array
{
    // error_log("Finding space for item $itemId"); // Keep less verbose
    $orientations = generateOrientations($itemDimensionsApi);
    $bestPlacement = null;
    $lowestScore = PHP_FLOAT_MAX;
    $stepSize = 5; // Placement granularity (adjust as needed)
    $containerW = (float)$containerDimensionsApi['width'];
    $containerD = (float)$containerDimensionsApi['depth'];
    $containerH = (float)$containerDimensionsApi['height'];
    $epsilon = 0.001; // Tolerance for floating point comparisons

    foreach ($orientations as $index => $orientation) {
        $itemW = (float)$orientation['width'];
        $itemD = (float)$orientation['depth'];
        $itemH = (float)$orientation['height'];
        // Check if orientation fits container dimensions at all
        if ($itemW > $containerW + $epsilon || $itemD > $containerD + $epsilon || $itemH > $containerH + $epsilon) {
             continue; // Skip impossible orientation
        }

        // Iterate through possible bottom-left-front corner positions (x,y,z)
        // Loop bounds ensure item doesn't go outside container
        for ($x = 0; $x <= $containerW - $itemW + $epsilon; $x += $stepSize) {
            for ($y = 0; $y <= $containerD - $itemD + $epsilon; $y += $stepSize) { // Depth (Y)
                for ($z = 0; $z <= $containerH - $itemH + $epsilon; $z += $stepSize) { // Height (Z)
                    $potentialPlacement = ['x' => $x, 'y' => $y, 'z' => $z, 'w' => $itemW, 'd' => $itemD, 'h' => $itemH];
                    $hasCollision = false;
                    foreach ($existingItems as $existingItem) {
                        // Existing item format: {'id', 'x', 'y', 'z', 'w', 'd', 'h'}
                        if (boxesOverlap($potentialPlacement, $existingItem)) {
                            $hasCollision = true;
                            break;
                        }
                    }
                    if (!$hasCollision) {
                        // Score prioritizes placement towards the back (lower Y), then bottom (lower Z), then left (lower X)
                        $score = ($y * 1000000) + ($z * 1000) + $x;
                        if ($score < $lowestScore) {
                            $lowestScore = $score;
                            // Return consistent keys for the main script to use
                            $bestPlacement = [
                                'foundX' => $x, 'foundY' => $y, 'foundZ' => $z,
                                'placedW' => $itemW, 'placedD' => $itemD, 'placedH' => $itemH
                            ];
                            // Optional: 'break 3;' here for first-fit instead of best-fit based on score
                        }
                    }
                } // End Z
            } // End Y
        } // End X
    } // End Orientation
    return $bestPlacement;
}

/**
 * Generates possible 3D orientations for an item.
 */
function generateOrientations(array $dimensions): array
{
    $width = (float)$dimensions['width'];
    $depth = (float)$dimensions['depth'];
    $height = (float)$dimensions['height'];
    // Return unique orientations only if needed, but all 6 are standard for cuboids
    return [
        ['width' => $width, 'depth' => $depth, 'height' => $height],
        ['width' => $width, 'depth' => $height, 'height' => $depth],
        ['width' => $depth, 'depth' => $width, 'height' => $height],
        ['width' => $depth, 'depth' => $height, 'height' => $width],
        ['width' => $height, 'depth' => $width, 'height' => $depth],
        ['width' => $height, 'depth' => $depth, 'height' => $width]
    ];
}

/**
 * Checks if two 3D bounding boxes overlap (AABB collision).
 * Assumes boxes are defined by {'x', 'y', 'z', 'w', 'd', 'h'}
 */
function boxesOverlap(array $box1, array $box2): bool
{
    $epsilon = 0.001; // Tolerance for floating point comparisons
    // Check for no overlap on any axis (Separating Axis Theorem simplified for AABB)
    $noOverlapX = ($box1['x'] + $box1['w'] <= $box2['x'] + $epsilon) || ($box2['x'] + $box2['w'] <= $box1['x'] + $epsilon);
    $noOverlapY = ($box1['y'] + $box1['d'] <= $box2['y'] + $epsilon) || ($box2['y'] + $box2['d'] <= $box1['y'] + $epsilon); // Use 'd' for depth
    $noOverlapZ = ($box1['z'] + $box1['h'] <= $box2['z'] + $epsilon) || ($box2['z'] + $box2['h'] <= $box1['z'] + $epsilon); // Use 'h' for height
    // If there's no overlap on *any* axis, they don't collide
    return !($noOverlapX || $noOverlapY || $noOverlapZ);
}
?>