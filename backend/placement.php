<?php
// --- File: backend/placement.php (Surface Heuristic - Best Fit - V6) ---
ini_set('max_execution_time', 300);
ini_set('display_errors', 0); // Disable displaying errors to the user
ini_set('log_errors', 1);    // Enable logging errors
error_reporting(E_ALL);     // Report all errors for logging

require_once __DIR__ . '/database.php';

// #########################################################################
// ## START: Constants & Config                                          ##
// #########################################################################

define('LOW_PRIORITY_THRESHOLD', 50); // Items with priority <= this are considered low for rearrangement
define('DB_DATETIME_FORMAT', 'Y-m-d H:i:s');
// Updated algorithm name - removed "Debug"
define('PLACEMENT_ALGORITHM_NAME', 'SurfaceHeuristic_BestFit_AltSpotRearrange');

// #########################################################################
// ## START: Helper Functions                                            ##
// #########################################################################

// --- generateOrientations function ---
function generateOrientations(array $dimensions): array {
    $width = (float)($dimensions['width'] ?? 0);
    $depth = (float)($dimensions['depth'] ?? 0);
    $height = (float)($dimensions['height'] ?? 0);
    if ($width <= 0 || $depth <= 0 || $height <= 0) {
        return [];
    }
    $orientationsMap = [];
    $candidates = [
        ['w' => $width, 'd' => $depth, 'h' => $height], ['w' => $width, 'd' => $height, 'h' => $depth],
        ['w' => $depth, 'd' => $width, 'h' => $height], ['w' => $depth, 'd' => $height, 'h' => $width],
        ['w' => $height, 'd' => $width, 'h' => $depth], ['w' => $height, 'd' => $depth, 'h' => $width]
    ];
    foreach ($candidates as $o) {
        $key = sprintf("%.3f_%.3f_%.3f", round($o['w'], 3), round($o['d'], 3), round($o['h'], 3));
        if (!isset($orientationsMap[$key])) {
             $orientationsMap[$key] = ['width' => $o['w'], 'depth' => $o['d'], 'height' => $o['h']];
        }
    }
    return array_values($orientationsMap);
}

// --- boxesOverlap function ---
function boxesOverlap(array $box1, array $box2): bool {
    $epsilon = 0.001;
    $x1 = (float)($box1['x'] ?? 0); $y1 = (float)($box1['y'] ?? 0); $z1 = (float)($box1['z'] ?? 0);
    $w1 = (float)($box1['w'] ?? 0); $d1 = (float)($box1['d'] ?? 0); $h1 = (float)($box1['h'] ?? 0);
    $x2 = (float)($box2['x'] ?? 0); $y2 = (float)($box2['y'] ?? 0); $z2 = (float)($box2['z'] ?? 0);
    $w2 = (float)($box2['w'] ?? 0); $d2 = (float)($box2['d'] ?? 0); $h2 = (float)($box2['h'] ?? 0);
    $noOverlapX = ($x1 + $w1 <= $x2 + $epsilon) || ($x2 + $w2 <= $x1 + $epsilon);
    $noOverlapY = ($y1 + $d1 <= $y2 + $epsilon) || ($y2 + $d2 <= $y1 + $epsilon);
    $noOverlapZ = ($z1 + $h1 <= $z2 + $epsilon) || ($z2 + $h2 <= $z1 + $epsilon);
    return !($noOverlapX || $noOverlapY || $noOverlapZ);
}


/**
 * Finds the best available position for an item IN A SPECIFIC CONTAINER using a surface
 * placement heuristic. Prioritizes placement closest to the open face (min Y),
 * then bottom (min Z), then left (min X). Includes point generation behind items.
 * (Standard placement function - checks ONE container)
 *
 * @param string $itemId The ID of the item being placed.
 * @param array $itemDimensionsApi Dimensions of the item {'width', 'depth', 'height'}.
 * @param string $containerId ID of the container being checked (for logging).
 * @param array $containerDimensionsApi Dimensions of the container {'width', 'depth', 'height'}.
 * @param array $existingItems Items already placed in this specific container.
 * @return ?array Best placement found in this container {'foundX', ..., 'score'} or null.
 */
function findSpaceForItem(string $itemId, array $itemDimensionsApi, string $containerId, array $containerDimensionsApi, array $existingItems): ?array
{
    // --- DEBUGGING LINES REMOVED ---

    $orientations = generateOrientations($itemDimensionsApi);
    if (empty($orientations)) {
        return null;
    }
    $bestPlacement = null; $bestScore = null; $epsilon = 0.001;
    $containerW = (float)($containerDimensionsApi['width'] ?? 0); $containerD = (float)($containerDimensionsApi['depth'] ?? 0); $containerH = (float)($containerDimensionsApi['height'] ?? 0);
    if ($containerW <= 0 || $containerD <= 0 || $containerH <= 0) {
        return null;
    }

    // --- Candidate Point Generation ---
    $candidatePoints = []; $originKey = sprintf("%.3f_%.3f_%.3f", 0.0, 0.0, 0.0); $candidatePoints[$originKey] = ['x' => 0.0, 'y' => 0.0, 'z' => 0.0];
    foreach ($existingItems as $existing) {
        $ex = (float)($existing['x'] ?? 0); $ey = (float)($existing['y'] ?? 0); $ez = (float)($existing['z'] ?? 0);
        $ew = (float)($existing['w'] ?? 0); $ed = (float)($existing['d'] ?? 0); $eh = (float)($existing['h'] ?? 0);
        // Generate points on top, right, AND BEHIND
        $pointsToAdd = [
            ['x' => $ex,       'y' => $ey,       'z' => $ez + $eh], // Top
            ['x' => $ex + $ew, 'y' => $ey,       'z' => $ez],       // Right
            ['x' => $ex,       'y' => $ey + $ed, 'z' => $ez]        // Behind
        ];
         foreach ($pointsToAdd as $pt) {
             // Check if point is within container bounds before adding
             if ($pt['x'] < $containerW + $epsilon && $pt['y'] < $containerD + $epsilon && $pt['z'] < $containerH + $epsilon) {
                 $key = sprintf("%.3f_%.3f_%.3f", $pt['x'], $pt['y'], $pt['z']);
                 if (!isset($candidatePoints[$key])) { // Avoid duplicates
                    $candidatePoints[$key] = $pt;
                 }
             }
         }
    }
     // --- DEBUGGING LINES REMOVED ---
    $candidatePoints = array_values($candidatePoints); // Convert back to indexed array for looping

    // --- Loop through Orientations and Candidate Points ---
    foreach ($orientations as $orientation) {
        $itemW = (float)$orientation['width']; $itemD = (float)$orientation['depth']; $itemH = (float)$orientation['height'];
        if ($itemW > $containerW + $epsilon || $itemD > $containerD + $epsilon || $itemH > $containerH + $epsilon) continue; // Orientation too big

        foreach ($candidatePoints as $point) {
            $x = (float)$point['x']; $y = (float)$point['y']; $z = (float)$point['z'];

            // Check container bounds for this orientation at this point
            if (($x + $itemW > $containerW + $epsilon) || ($y + $itemD > $containerD + $epsilon) || ($z + $itemH > $containerH + $epsilon)) {
                continue;
            }

            // Check for collisions
            $potentialPlacement = ['x' => $x, 'y' => $y, 'z' => $z, 'w' => $itemW, 'd' => $itemD, 'h' => $itemH];
            $hasCollision = false;
            foreach ($existingItems as $existingItem) {
                if (boxesOverlap($potentialPlacement, $existingItem)) {
                    $hasCollision = true;
                    break;
                }
            }

            // --- If Placement is Valid, Calculate and Compare Score ---
            if (!$hasCollision) {
                // Score: Prioritize Min Y, then Min Z, then Min X
                $currentScore = ($y * 1000000) + ($z * 1000) + $x;
                // --- DEBUGGING LINES REMOVED ---

                // --- Compare with Best Score *within this container* ---
                if ($bestScore === null || $currentScore < $bestScore) {
                    // --- DEBUGGING LINES REMOVED ---
                    $bestScore = $currentScore;
                    // Store the best placement found so far *within this specific container*
                    $bestPlacement = ['foundX' => $x, 'foundY' => $y, 'foundZ' => $z, 'placedW' => $itemW, 'placedD' => $itemD, 'placedH' => $itemH, 'score' => $bestScore]; // Include score here
                }
            }
            // --- End Valid Placement Block ---
        } // End point loop
    } // End orientation loop

    // --- DEBUGGING LINES REMOVED ---

    return $bestPlacement; // Return the best placement found in *this* container (or null)
}


/**
 * Tries to find a valid placement spot for an item, specifically avoiding
 * its original position. Includes point generation behind items.
 * Used for relocating blockers.
 *
 * @param string $itemId The ID of the item to place (for logging).
 * @param array $itemDimensionsApi Original dimensions from API {'width', 'depth', 'height'}.
 * @param string $containerId ID of the container being checked (for logging).
 * @param array $containerDimensionsApi Dimensions of container {'width', 'depth', 'height'}.
 * @param array $existingItems List of items already placed (excluding the item being moved).
 * @param array $originalPosition The original placement data {'x','y','z','w','d','h'} to avoid.
 * @return ?array Best alternative placement found {'foundX', ...} or null if no suitable alternative space.
 */
function findAlternativeSpot(string $itemId, array $itemDimensionsApi, string $containerId, array $containerDimensionsApi, array $existingItems, array $originalPosition): ?array
{
    $orientations = generateOrientations($itemDimensionsApi);
    if (empty($orientations)) { return null; }

    $bestAltPlacement = null;
    $bestAltScore = null;
    $epsilon = 0.001;
    $posEpsilon = 0.1; // How close to original position counts as "same"

    $containerW = (float)($containerDimensionsApi['width'] ?? 0); $containerD = (float)($containerDimensionsApi['depth'] ?? 0); $containerH = (float)($containerDimensionsApi['height'] ?? 0);
    if ($containerW <= 0 || $containerD <= 0 || $containerH <= 0) { return null; }

    // Generate Candidate Points (Same as findSpaceForItem, including BEHIND)
    $candidatePoints = []; $originKey = sprintf("%.3f_%.3f_%.3f", 0.0, 0.0, 0.0); $candidatePoints[$originKey] = ['x' => 0.0, 'y' => 0.0, 'z' => 0.0];
    foreach ($existingItems as $existing) {
        $ex = (float)($existing['x'] ?? 0); $ey = (float)($existing['y'] ?? 0); $ez = (float)($existing['z'] ?? 0);
        $ew = (float)($existing['w'] ?? 0); $ed = (float)($existing['d'] ?? 0); $eh = (float)($existing['h'] ?? 0);
        $pointsToAdd = [
            ['x' => $ex,       'y' => $ey,       'z' => $ez + $eh], // Top
            ['x' => $ex + $ew, 'y' => $ey,       'z' => $ez],       // Right
            ['x' => $ex,       'y' => $ey + $ed, 'z' => $ez]        // Behind
        ];
         foreach ($pointsToAdd as $pt) {
             if ($pt['x'] < $containerW + $epsilon && $pt['y'] < $containerD + $epsilon && $pt['z'] < $containerH + $epsilon) {
                 $key = sprintf("%.3f_%.3f_%.3f", $pt['x'], $pt['y'], $pt['z']);
                 if (!isset($candidatePoints[$key])) { $candidatePoints[$key] = $pt; }
             }
         }
    } $candidatePoints = array_values($candidatePoints);

    // Check Orientations at Candidate Points
    foreach ($orientations as $orientation) {
        $itemW = (float)$orientation['width']; $itemD = (float)$orientation['depth']; $itemH = (float)$orientation['height'];
        if ($itemW > $containerW + $epsilon || $itemD > $containerD + $epsilon || $itemH > $containerH + $epsilon) continue;

        foreach ($candidatePoints as $point) {
            $x = (float)$point['x']; $y = (float)$point['y']; $z = (float)$point['z'];
            if (($x + $itemW > $containerW + $epsilon) || ($y + $itemD > $containerD + $epsilon) || ($z + $itemH > $containerH + $epsilon)) continue;

            $potentialPlacement = ['x' => $x, 'y' => $y, 'z' => $z, 'w' => $itemW, 'd' => $itemD, 'h' => $itemH];
            $hasCollision = false; foreach ($existingItems as $existingItem) { if (boxesOverlap($potentialPlacement, $existingItem)) { $hasCollision = true; break; } }

            if (!$hasCollision) {
                // Check: Avoid Original Position
                $isOriginalPosition = false;
                if (isset($originalPosition['x'], $originalPosition['y'], $originalPosition['z']) &&
                    abs($x - (float)$originalPosition['x']) < $posEpsilon &&
                    abs($y - (float)$originalPosition['y']) < $posEpsilon &&
                    abs($z - (float)$originalPosition['z']) < $posEpsilon) {
                    $isOriginalPosition = true;
                }

                if (!$isOriginalPosition) {
                    // Valid spot AND not original. Score it.
                    $currentScore = ($y * 1000000) + ($z * 1000) + $x;
                    if ($bestAltScore === null || $currentScore < $bestAltScore) {
                        $bestAltScore = $currentScore;
                        $bestAltPlacement = ['foundX' => $x, 'foundY' => $y, 'foundZ' => $z, 'placedW' => $itemW, 'placedD' => $itemD, 'placedH' => $itemH];
                    }
                }
            }
        } // End point loop
    } // End orientation loop

    if ($bestAltPlacement === null) { error_log("findAlternativeSpot for $itemId in $containerId: Could not find any valid alternative spot."); }
    return $bestAltPlacement;
}


/**
 * Helper to format position array for API response.
 */
function formatApiPosition(float $x, float $y, float $z, float $w, float $d, float $h): array {
    return [
        'startCoordinates' => ['width' => $x, 'depth' => $y, 'height' => $z],
        'endCoordinates' => ['width' => $x + $w, 'depth' => $y + $d, 'height' => $z + $h]
    ];
}

// #########################################################################
// ## END: HELPER FUNCTIONS                                              ##
// #########################################################################


// --- Script Start ---
$response = ['success' => false, 'placements' => [], 'rearrangements' => []];
$internalErrors = [];
$db = null;
$itemsMasterList = []; // Store priority and original dimensions

// --- Database Connection ---
try { $db = getDbConnection(); if ($db === null) throw new Exception("DB null"); } catch (Exception $e) { http_response_code(503); error_log("FATAL: DB Connect Error - " . $e->getMessage()); echo json_encode(['success' => false, 'message' => 'DB connection error.']); exit; }

// --- Input Processing ---
$rawData = file_get_contents('php://input'); $requestData = json_decode($rawData, true);
if ($requestData === null || !isset($requestData['items'], $requestData['containers']) || !is_array($requestData['items']) || !is_array($requestData['containers'])) { http_response_code(400); error_log("Placement Error: Invalid JSON: " . $rawData); echo json_encode(['success' => false, 'message' => 'Invalid input format.']); exit; }
$itemsToPlaceInput = $requestData['items']; $containersInput = $requestData['containers'];
$containerDimensionsMap = []; foreach ($containersInput as $c) { if (isset($c['containerId'], $c['width'], $c['depth'], $c['height'])) { $containerDimensionsMap[$c['containerId']] = ['width' => (float)$c['width'], 'depth' => (float)$c['depth'], 'height' => (float)$c['height'], 'zone' => $c['zone'] ?? 'UnknownZone']; } else { error_log("Skipping invalid container data: ".json_encode($c)); } }
error_log(PLACEMENT_ALGORITHM_NAME . " request: " . count($itemsToPlaceInput) . " items, " . count($containerDimensionsMap) . " valid containers.");


// --- Load Existing Item State from DB (Including Priority & ORIGINAL Dimensions) ---
$existingPlacedItemsByContainer = [];
$existingItemsMasterList = [];
try {
    $sqlPlaced = "SELECT i.itemId, i.containerId, i.priority,
                         i.dimensionW, i.dimensionD, i.dimensionH,             -- Original item dimensions
                         i.placedDimensionW, i.placedDimensionD, i.placedDimensionH, -- Placed dimensions
                         i.positionX AS posX, i.positionY AS posY, i.positionZ AS posZ
                  FROM items i
                  WHERE i.containerId IS NOT NULL AND i.status = 'stowed'";
    $stmtPlaced = $db->prepare($sqlPlaced); $stmtPlaced->execute(); $placedItemsResult = $stmtPlaced->fetchAll(PDO::FETCH_ASSOC);
    foreach ($placedItemsResult as $item) {
        $containerId = $item['containerId'];
        if (!isset($existingPlacedItemsByContainer[$containerId])) { $existingPlacedItemsByContainer[$containerId] = []; }
        $placementData = [ 'id' => $item['itemId'], 'x' => (float)$item['posX'], 'y' => (float)$item['posY'], 'z' => (float)$item['posZ'], 'w' => (float)$item['placedDimensionW'], 'd' => (float)$item['placedDimensionD'], 'h' => (float)$item['placedDimensionH'] ];
        $existingPlacedItemsByContainer[$containerId][] = $placementData;
        $existingItemsMasterList[$item['itemId']] = [
            'priority' => (int)($item['priority'] ?? 0),
            'placement' => $placementData,
            'dimensions_api' => [
                'width' => (float)($item['dimensionW'] ?: $item['placedDimensionW']),
                'depth' => (float)($item['dimensionD'] ?: $item['placedDimensionD']),
                'height' => (float)($item['dimensionH'] ?: $item['placedDimensionH'])
            ]
        ];
    }
    error_log("Found existing placements for " . count($existingItemsMasterList) . " items in " . count($existingPlacedItemsByContainer) . " containers from DB.");
} catch (PDOException $e) { http_response_code(500); $response = ['success' => false, 'message' => 'DB error loading existing items.']; error_log("Placement DB Error (fetch existing): " . $e->getMessage()); echo json_encode($response); $db = null; exit; }

// --- Merge incoming item data into master list (priority & dimensions) ---
foreach ($itemsToPlaceInput as $item) {
    if (isset($item['itemId'])) {
        $itemId = $item['itemId'];
        $itemsMasterList[$itemId] = [
            'priority' => (int)($item['priority'] ?? ($existingItemsMasterList[$itemId]['priority'] ?? 0)),
            'dimensions_api' => [
                'width' => (float)($item['width'] ?? 0),
                'depth' => (float)($item['depth'] ?? 0),
                'height' => (float)($item['height'] ?? 0)
            ],
            'placement' => $existingItemsMasterList[$itemId]['placement'] ?? null,
            // Store preferences directly with the item master data
            'preferredContainerId' => $item['preferredContainerId'] ?? null,
            'preferredZone' => $item['preferredZone'] ?? null
         ];
    }
}


// --- Placement Algorithm Logic ---
$currentPlacementState = $existingPlacedItemsByContainer; // In-memory state
$dbUpdates = []; // Collect DB changes needed at the end
$rearrangementSteps = []; // Collect rearrangement steps for the response
$stepCounter = 1; // For rearrangement steps

// --- Sorting Incoming Items (Priority + Volume) ---
if (!empty($itemsToPlaceInput)) {
    error_log("Sorting " . count($itemsToPlaceInput) . " incoming items...");
    usort($itemsToPlaceInput, function($a, $b) {
         $priorityA = (int)($a['priority'] ?? 0); $priorityB = (int)($b['priority'] ?? 0); if ($priorityA !== $priorityB) { return $priorityB <=> $priorityA; }
         $volumeA = (float)($a['width'] ?? 0) * (float)($a['depth'] ?? 0) * (float)($a['height'] ?? 0); $volumeB = (float)($b['width'] ?? 0) * (float)($b['depth'] ?? 0) * (float)($b['height'] ?? 0); if (abs($volumeA - $volumeB) > 0.001) { return $volumeB <=> $volumeA; }
         return ($a['itemId'] ?? '') <=> ($b['itemId'] ?? '');
    });
}

// --- Main Placement Loop ---
foreach ($itemsToPlaceInput as $itemToPlace) {
    $itemPlaced = false;
    $currentItemId = $itemToPlace['itemId'] ?? null;

    // --- Basic Item Validation ---
    if ($currentItemId === null || !isset($itemsMasterList[$currentItemId]['dimensions_api']) || $itemsMasterList[$currentItemId]['dimensions_api']['width'] <= 0 || $itemsMasterList[$currentItemId]['dimensions_api']['depth'] <= 0 || $itemsMasterList[$currentItemId]['dimensions_api']['height'] <= 0) { error_log("Skipping invalid item data for $currentItemId: ".json_encode($itemToPlace)); $internalErrors[] = ['itemId' => $currentItemId ?? 'Unknown', 'reason' => 'Invalid item data or dimensions.']; continue; }
    $itemDimensionsApi = $itemsMasterList[$currentItemId]['dimensions_api'];
    $currentItemPriority = $itemsMasterList[$currentItemId]['priority'];
    // --- DEBUGGING LINE REMOVED ---

    // --- Determine Container Order ("Best Fit" logic) ---
    $preferredContainerIdSpecific = $itemsMasterList[$currentItemId]['preferredContainerId'] ?? null;
    $preferredZone = $itemsMasterList[$currentItemId]['preferredZone'] ?? null;
    $containersToTryIds = [];
    $processedIds = []; // Keep track of added IDs

    // 1. Add specific preferred container ID if valid
    if ($preferredContainerIdSpecific !== null && isset($containerDimensionsMap[$preferredContainerIdSpecific])) {
        $containersToTryIds[] = $preferredContainerIdSpecific;
        $processedIds[$preferredContainerIdSpecific] = true;
        // --- DEBUGGING LINE REMOVED ---
    }

    // 2. Add other containers in the preferred zone (if any)
    if ($preferredZone !== null) {
         // --- DEBUGGING LINE REMOVED ---
        foreach ($containerDimensionsMap as $cId => $cData) {
            if (!isset($processedIds[$cId]) && ($cData['zone'] ?? null) === $preferredZone) {
                $containersToTryIds[] = $cId;
                $processedIds[$cId] = true;
            }
        }
    }

    // 3. Add all remaining containers
     // --- DEBUGGING LINE REMOVED ---
    foreach ($containerDimensionsMap as $cId => $cData) {
        if (!isset($processedIds[$cId])) {
            $containersToTryIds[] = $cId;
        }
    }
    // --- DEBUGGING LINE REMOVED ---


    // --- Attempt Direct Placement by finding BEST spot across eligible containers ---
    $bestOverallPlacement = null;
    $bestOverallScore = null; // Lower is better
    $bestOverallContainerId = null;

    foreach ($containersToTryIds as $containerId) {
        if (!isset($containerDimensionsMap[$containerId])) continue;
        $containerDimensionsApi = $containerDimensionsMap[$containerId];
        $itemsCurrentlyInContainer = $currentPlacementState[$containerId] ?? [];

        // *** Call findSpaceForItem to find the best spot *within this specific container* ***
        $placementInThisContainer = findSpaceForItem(
            $currentItemId,
            $itemDimensionsApi,
            $containerId,
            $containerDimensionsApi,
            $itemsCurrentlyInContainer
        );

        // If a valid spot was found in *this* container, compare its score globally
        if ($placementInThisContainer !== null) {
             $currentOverallScore = $placementInThisContainer['score'];

             // --- DEBUGGING LINES REMOVED ---

             // Compare with the best score found so far across ALL checked containers
             if ($bestOverallScore === null || $currentOverallScore < $bestOverallScore) {
                  // --- DEBUGGING LINES REMOVED ---
                 $bestOverallScore = $currentOverallScore;
                 $bestOverallPlacement = $placementInThisContainer;
                 $bestOverallContainerId = $containerId;
             }
        }
    } // End loop through containers to try


    // --- Process the BEST overall placement found across all containers ---
    if ($bestOverallPlacement !== null) {
        // --- Best Direct Placement Found ---
        $foundX = (float)$bestOverallPlacement['foundX']; $foundY = (float)$bestOverallPlacement['foundY']; $foundZ = (float)$bestOverallPlacement['foundZ'];
        $placedW = (float)$bestOverallPlacement['placedW']; $placedD = (float)$bestOverallPlacement['placedD']; $placedH = (float)$bestOverallPlacement['placedH'];
        $chosenContainerId = $bestOverallContainerId;

        error_log("Item $currentItemId: Best Direct Placement chosen in $chosenContainerId at ($foundX, $foundY, $foundZ) with Score: $bestOverallScore");

        // Add to DB Updates, API Response, and In-Memory State for the CHOSEN container
        $dbUpdates[$currentItemId] = ['action' => 'place', 'itemId' => $currentItemId, 'containerId' => $chosenContainerId, 'positionX' => $foundX, 'positionY' => $foundY, 'positionZ' => $foundZ, 'placedDimensionW' => $placedW, 'placedDimensionD' => $placedD, 'placedDimensionH' => $placedH ];
        $response['placements'][] = ['itemId' => $currentItemId, 'containerId' => $chosenContainerId, 'position' => formatApiPosition($foundX, $foundY, $foundZ, $placedW, $placedD, $placedH) ];
        if (!isset($currentPlacementState[$chosenContainerId])) { $currentPlacementState[$chosenContainerId] = []; }
        $currentPlacementState[$chosenContainerId][] = [ 'id' => $currentItemId, 'x' => $foundX, 'y' => $foundY, 'z' => $foundZ, 'w' => $placedW, 'd' => $placedD, 'h' => $placedH ];
        $currentPlacementState[$chosenContainerId] = array_values($currentPlacementState[$chosenContainerId]); // Re-index

        $itemPlaced = true;
    }
    // --- End processing best overall placement ---


    // --- Attempt Rearrangement if Direct Placement Failed ---
    if (!$itemPlaced && $preferredZone !== null) {
        error_log("Item $currentItemId: No suitable direct placement found in any container. Attempting simple rearrangement in preferred zone '$preferredZone'.");

        $preferredZoneContainerIds = [];
        foreach ($containerDimensionsMap as $cId => $cData) {
             if (($cData['zone'] ?? null) === $preferredZone) {
                  $preferredZoneContainerIds[] = $cId;
             }
        }

        foreach ($preferredZoneContainerIds as $preferredContainerId) {
            if ($itemPlaced) break;

            $containerDimensionsApi = $containerDimensionsMap[$preferredContainerId];
            $itemsInPrefContainer = $currentPlacementState[$preferredContainerId] ?? [];

            $targetX = 0.0; $targetY = 0.0; $targetZ = 0.0; $canFitAtOrigin = false; $targetPlacement = null; $orientations = generateOrientations($itemDimensionsApi);
            foreach($orientations as $orientation) { $itemW = (float)$orientation['width']; $itemD = (float)$orientation['depth']; $itemH = (float)$orientation['height']; if ($itemW <= $containerDimensionsApi['width'] + 0.001 && $itemD <= $containerDimensionsApi['depth'] + 0.001 && $itemH <= $containerDimensionsApi['height'] + 0.001) { $canFitAtOrigin = true; $targetPlacement = ['x' => $targetX, 'y' => $targetY, 'z' => $targetZ, 'w' => $itemW, 'd' => $itemD, 'h' => $itemH]; break; } }
            if (!$canFitAtOrigin) { continue; }

            $blockers = [];
            foreach ($itemsInPrefContainer as $idx => $existingItem) {
                if (boxesOverlap($targetPlacement, $existingItem)) {
                    $blockerId = $existingItem['id'];
                    $blockerPriority = $itemsMasterList[$blockerId]['priority'] ?? 999;
                    if ($blockerPriority <= LOW_PRIORITY_THRESHOLD) { $blockers[] = ['id' => $blockerId, 'index' => $idx, 'data' => $existingItem]; }
                    else { error_log("Item $currentItemId: Found high-priority blocker at origin in $preferredContainerId: $blockerId (Prio: $blockerPriority). Cannot move."); $blockers = []; break; }
                }
            }
            if (empty($blockers)) { continue; }

            foreach ($blockers as $blockerInfo) {
                $blockerId = $blockerInfo['id'];
                $blockerData = $blockerInfo['data'];
                $blockerIndex = $blockerInfo['index'];
                $blockerMasterInfo = $itemsMasterList[$blockerId] ?? null;
                if ($blockerMasterInfo === null || !isset($blockerMasterInfo['dimensions_api'])) { error_log("Item $currentItemId: CRITICAL - Missing master data/dims for blocker $blockerId."); continue; }
                $blockerOriginalDimensions = $blockerMasterInfo['dimensions_api'];

                error_log("Item $currentItemId: Attempting to relocate blocker $blockerId from ($blockerData[x], $blockerData[y], $blockerData[z]) in $preferredContainerId using findAlternativeSpot...");

                $tempStateWithoutBlocker = $currentPlacementState[$preferredContainerId];
                 if (!isset($tempStateWithoutBlocker[$blockerIndex]) || $tempStateWithoutBlocker[$blockerIndex]['id'] !== $blockerId) {
                     error_log("Item $currentItemId: CRITICAL - Index mismatch or blocker not found at index $blockerIndex for removal in $preferredContainerId. Skipping.");
                     break;
                 }
                unset($tempStateWithoutBlocker[$blockerIndex]);
                $tempStateWithoutBlocker = array_values($tempStateWithoutBlocker);

                $newBlockerCoords = findAlternativeSpot(
                    $blockerId,
                    $blockerOriginalDimensions,
                    $preferredContainerId,
                    $containerDimensionsApi,
                    $tempStateWithoutBlocker,
                    $blockerData
                );

                if ($newBlockerCoords !== null) {
                    $newX = (float)$newBlockerCoords['foundX']; $newY = (float)$newBlockerCoords['foundY']; $newZ = (float)$newBlockerCoords['foundZ'];
                    $newW = (float)$newBlockerCoords['placedW']; $newD = (float)$newBlockerCoords['placedD']; $newH = (float)$newBlockerCoords['placedH'];
                    error_log("Item $currentItemId: Found ALTERNATIVE spot for blocker $blockerId in $preferredContainerId at ($newX, $newY, $newZ). Verifying final placement...");

                    $tempStateWithBlockerMoved = $tempStateWithoutBlocker;
                    $tempStateWithBlockerMoved[] = ['id' => $blockerId, 'x' => $newX, 'y' => $newY, 'z' => $newZ, 'w' => $newW, 'd' => $newD, 'h' => $newH];
                    $tempStateWithBlockerMoved = array_values($tempStateWithBlockerMoved);

                    $finalPlacementCoords = findSpaceForItem(
                         $currentItemId,
                         $itemDimensionsApi,
                         $preferredContainerId,
                         $containerDimensionsApi,
                         $tempStateWithBlockerMoved
                     );

                    $epsilon = 0.01;
                    if ($finalPlacementCoords !== null &&
                        abs($finalPlacementCoords['foundX'] - $targetX) < $epsilon &&
                        abs($finalPlacementCoords['foundY'] - $targetY) < $epsilon &&
                        abs($finalPlacementCoords['foundZ'] - $targetZ) < $epsilon)
                    {
                        $finalX = (float)$finalPlacementCoords['foundX']; $finalY = (float)$finalPlacementCoords['foundY']; $finalZ = (float)$finalPlacementCoords['foundZ'];
                        $finalW = (float)$finalPlacementCoords['placedW']; $finalD = (float)$finalPlacementCoords['placedD']; $finalH = (float)$finalPlacementCoords['placedH'];
                        error_log("Item $currentItemId: Rearrangement SUCCESS! Will place at origin in $preferredContainerId after moving $blockerId to ($newX, $newY, $newZ).");

                        // COMMIT Blocker Move
                        $rearrangementSteps[] = [ 'step' => $stepCounter++, 'action' => 'move', 'itemId' => $blockerId, 'fromContainer' => $preferredContainerId, 'fromPosition' => formatApiPosition($blockerData['x'], $blockerData['y'], $blockerData['z'], $blockerData['w'], $blockerData['d'], $blockerData['h']), 'toContainer' => $preferredContainerId, 'toPosition' => formatApiPosition($newX, $newY, $newZ, $newW, $newD, $newH) ];
                        $dbUpdates[$blockerId] = ['action' => 'move', 'itemId' => $blockerId, 'containerId' => $preferredContainerId, 'positionX' => $newX, 'positionY' => $newY, 'positionZ' => $newZ, 'placedDimensionW' => $newW, 'placedDimensionD' => $newD, 'placedDimensionH' => $newH ];
                         $currentPlacementState[$preferredContainerId] = $tempStateWithBlockerMoved;

                        // COMMIT Final Placement
                        $rearrangementSteps[] = [ 'step' => $stepCounter++, 'action' => 'place', 'itemId' => $currentItemId, 'fromContainer' => null, 'fromPosition' => null, 'toContainer' => $preferredContainerId, 'toPosition' => formatApiPosition($finalX, $finalY, $finalZ, $finalW, $finalD, $finalH) ];
                        $dbUpdates[$currentItemId] = ['action' => 'place', 'itemId' => $currentItemId, 'containerId' => $preferredContainerId, 'positionX' => $finalX, 'positionY' => $finalY, 'positionZ' => $finalZ, 'placedDimensionW' => $finalW, 'placedDimensionD' => $finalD, 'placedDimensionH' => $finalH ];
                        $response['placements'][] = ['itemId' => $currentItemId, 'containerId' => $preferredContainerId, 'position' => formatApiPosition($finalX, $finalY, $finalZ, $finalW, $finalD, $finalH) ];
                        $currentPlacementState[$preferredContainerId][] = ['id' => $currentItemId, 'x' => $finalX, 'y' => $finalY, 'z' => $finalZ, 'w' => $finalW, 'd' => $finalD, 'h' => $finalH];
                        $currentPlacementState[$preferredContainerId] = array_values($currentPlacementState[$preferredContainerId]);

                        $itemPlaced = true;
                        break;
                    } else {
                        error_log("Item $currentItemId: Rearrangement Check FAILED for blocker $blockerId. Blocker moved to alternative spot, but $currentItemId still couldn't be placed at origin ($finalPlacementCoords ? " . json_encode($finalPlacementCoords) . "). No changes made.");
                    }
                } else {
                    error_log("Item $currentItemId: Rearrangement Check FAILED. Could not find any alternative location for blocker $blockerId in $preferredContainerId.");
                }
            } // End blocker loop

        } // End preferred container loop
    } // End rearrangement attempt block


    // --- Final Check for Placement ---
    if (!$itemPlaced) {
        error_log("Item $currentItemId: Placement FAILED after direct search ('Best Fit') and rearrangement attempts.");
        $internalErrors[] = ['itemId' => $currentItemId, 'reason' => 'No suitable placement space found across any containers, even after simple rearrangement check.'];
    }

} // End main item loop


// --- Update Database ---
$updatedCount = 0;
$dbUpdateFailed = false;
$actuallyUpdatedIds = [];
if (!empty($dbUpdates)) {
    error_log("DB Update Prep: Processing " . count($dbUpdates) . " placement/move actions.");
    $finalDbUpdates = [];
    foreach($dbUpdates as $itemId => $updateData) {
        if (($updateData['action'] ?? 'place') !== 'revert') {
            $finalDbUpdates[$itemId] = $updateData;
        } else {
             error_log("DB Update Skip: Ignoring reverted action for item $itemId.");
        }
    }

    if (!empty($finalDbUpdates)) {
        $updateSql = "UPDATE items SET containerId = :containerId, positionX = :positionX, positionY = :positionY, positionZ = :positionZ, placedDimensionW = :placedW, placedDimensionD = :placedD, placedDimensionH = :placedH, status = 'stowed', lastUpdated = :lastUpdated WHERE itemId = :itemId";
        try {
            $db->beginTransaction();
            $updateStmt = $db->prepare($updateSql);
            $lastUpdated = date(DB_DATETIME_FORMAT);

            $bind_containerId = null; $bind_posX = null; $bind_posY = null; $bind_posZ = null;
            $bind_placedW = null; $bind_placedD = null; $bind_placedH = null; $bind_itemId = null;
            $updateStmt->bindParam(':containerId', $bind_containerId); $updateStmt->bindParam(':positionX', $bind_posX); $updateStmt->bindParam(':positionY', $bind_posY); $updateStmt->bindParam(':positionZ', $bind_posZ);
            $updateStmt->bindParam(':placedW', $bind_placedW); $updateStmt->bindParam(':placedD', $bind_placedD); $updateStmt->bindParam(':placedH', $bind_placedH);
            $updateStmt->bindParam(':lastUpdated', $lastUpdated); $updateStmt->bindParam(':itemId', $bind_itemId, PDO::PARAM_STR);

            foreach ($finalDbUpdates as $itemId => $updateData) {
                if (($updateData['action'] ?? 'place') === 'revert') continue;

                $bind_containerId = $updateData['containerId']; $bind_posX = $updateData['positionX']; $bind_posY = $updateData['positionY']; $bind_posZ = $updateData['positionZ'];
                $bind_placedW = $updateData['placedDimensionW']; $bind_placedD = $updateData['placedDimensionD']; $bind_placedH = $updateData['placedDimensionH']; $bind_itemId = $updateData['itemId'];

                if ($updateStmt->execute()) {
                    $rowCount = $updateStmt->rowCount();
                    if ($rowCount > 0) { $updatedCount++; $actuallyUpdatedIds[] = $bind_itemId; }
                    else { error_log("DB Update WARN: Execute OK but 0 rows affected for item: $bind_itemId."); $internalErrors[] = ['itemId' => $bind_itemId, 'reason' => 'DB update OK but 0 rows affected.']; }
                } else {
                     $errorInfo = $updateStmt->errorInfo(); error_log("DB Update FAIL for itemId: " . $bind_itemId . " - Error: " . ($errorInfo[2] ?? 'Unknown PDO Error')); throw new PDOException("DB execute failed for itemId: " . $bind_itemId);
                }
            }
            $db->commit();
            error_log("DB Update Commit: Transaction committed. Final updatedCount: $updatedCount.");
            if (!$dbUpdateFailed) $response['success'] = true;

        } catch (PDOException $e) {
             if ($db->inTransaction()) { $db->rollBack(); error_log("Rolled back DB updates due to error."); } http_response_code(500); $response['success'] = false; $response['placements'] = []; $response['rearrangements'] = [];
             $response['message'] = "DB update failed during placement. Transaction rolled back."; error_log("Placement DB Error (update execution): " . $e->getMessage() . " Failed Item ID might be near the last one logged before error."); $dbUpdateFailed = true;
        }
    } else {
         error_log("No actual DB updates required after filtering reverted actions.");
          if (count($response['placements']) > 0) { $response['success'] = true; $response['message'] = $response['message'] ?? "Placement successful (no DB updates needed)."; }
          elseif (count($itemsToPlaceInput) === 0) { $response['success'] = true; $response['message'] = "No items provided for placement."; }
          else { $response['success'] = false; $response['message'] = $response['message'] ?? "No suitable space found for any provided items (or only reverted moves attempted)."; }
    }
} else {
     if (count($itemsToPlaceInput) === 0) { $response['success'] = true; $response['message'] = "No items provided for placement."; }
     elseif (count($response['placements']) > 0) { $response['success'] = true; $response['message'] = $response['message'] ?? "Placement successful (no DB updates generated)."; }
     else { $response['success'] = false; $response['message'] = $response['message'] ?? "No suitable space found for any provided items."; }
     error_log("No DB updates generated or needed.");
}
// --- End Database Update ---


// --- Finalize and Echo Response ---
if (!$dbUpdateFailed) {
     $finalSuccess = !empty($response['placements']) || count($itemsToPlaceInput) === 0;
     $response['success'] = $finalSuccess;

     $placedCount = count($response['placements']);
     $attemptedCount = count($itemsToPlaceInput);
     $rearrangedCount = count($rearrangementSteps);

     if ($finalSuccess) {
         if ($attemptedCount > 0 && $placedCount < $attemptedCount) {
             http_response_code(207); // Multi-Status
             $response['message'] = $response['message'] ?? "Placement partially successful. $placedCount placed, " . ($attemptedCount - $placedCount) . " failed.";
         } else {
              http_response_code(200); // OK
              $response['message'] = $response['message'] ?? ($attemptedCount > 0 ? "Placement successful." : "No items requested for placement.");
         }
          if ($rearrangedCount > 0) { $response['message'] .= " ($rearrangedCount rearrangement steps involved)"; }
     } else {
          if (http_response_code() < 400) { http_response_code(400); }
          $response['message'] = $response['message'] ?? "Placement failed. No items could be placed.";
     }

     if (!empty($internalErrors)) { $response['warnings'] = $internalErrors; }
     $response['rearrangements'] = $rearrangementSteps;
}


// --- Logging ---
$finalResponseSuccess = $response['success'] ?? false;
$finalHttpMessage = $response['message'] ?? null;
try { if ($db) {
        $logSql = "INSERT INTO logs (userId, actionType, detailsJson, timestamp) VALUES (:userId, :actionType, :details, :timestamp)"; $logStmt = $db->prepare($logSql);
        $logDetails = [
             'operationType' => 'placement',
             'algorithm' => PLACEMENT_ALGORITHM_NAME,
             'requestItemCount' => count($itemsToPlaceInput),
             'responseSuccess' => $finalResponseSuccess,
             'httpStatusCode' => http_response_code(),
             'itemsPlacedCount' => count($response['placements'] ?? []),
             'dbUpdatesAttempted' => count($finalDbUpdates ?? []),
             'dbUpdatesSuccessful' => $updatedCount,
             'rearrangementStepsCount' => count($response['rearrangements'] ?? []),
             'internalErrorsCount' => count($internalErrors),
             'finalMessage' => $finalHttpMessage
         ];
        $logParams = [
             ':userId' => 'System_PlacementAPI',
             ':actionType' => 'placement',
             ':details' => json_encode($logDetails, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
             ':timestamp' => date(DB_DATETIME_FORMAT)
         ];
        $logStmt->execute($logParams);
 } } catch (Exception $logEx) { error_log("CRITICAL: Failed to log placement action! Error: " . $logEx->getMessage()); }


// --- Send Output ---
if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
$finalResponse = [
    'success' => $finalResponseSuccess,
    'placements' => $response['placements'] ?? [],
    'rearrangements' => $response['rearrangements'] ?? []
];
if ($finalHttpMessage !== null) { $finalResponse['message'] = $finalHttpMessage; }
if (!empty($response['warnings'])) { $finalResponse['warnings'] = $response['warnings']; }

echo json_encode($finalResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
error_log(PLACEMENT_ALGORITHM_NAME . " Finished. Success: " . ($finalResponseSuccess ? 'Yes' : 'No') . ". Placed: " . count($finalResponse['placements']) . ". Rearranged Steps: " . count($finalResponse['rearrangements']) . ". Attempted: " . count($itemsToPlaceInput) . ". DB Updates: $updatedCount. Warnings: " . count($internalErrors) . ". HTTP Code: " . http_response_code());
$db = null; exit();

?>