<?php
// --- File: backend/placement.php (Surface Heuristic - Best Fit - V7 - Priority Rearrangement - Revised Trigger) ---
ini_set('max_execution_time', 300); // Consider increasing if rearrangements are complex
ini_set('display_errors', 0);      // Disable displaying errors to the user
ini_set('log_errors', 1);         // Enable logging errors
error_reporting(E_ALL);          // Report all errors for logging

require_once __DIR__ . '/database.php';

// #########################################################################
// ## START: Constants & Config                                          ##
// #########################################################################

define('DB_DATETIME_FORMAT', 'Y-m-d H:i:s');
define('PLACEMENT_ALGORITHM_NAME', 'SurfaceHeuristic_BestFit_PriorityRearrange_V7_RevisedTrigger'); // Updated name slightly

// --- Priority & Rearrangement Config ---
define('HIGH_PRIORITY_THRESHOLD', 80);    // Items with priority >= this trigger rearrangement if needed for preferred spot
define('LOW_PRIORITY_THRESHOLD', 50);     // Items with priority <= this are candidates for being moved during rearrangement

// --- Scoring Config ---
define('PREFERRED_ZONE_SCORE_PENALTY', 1000000000.0); // Huge penalty for placing outside preferred zone/container (applied during cross-container comparison)
define('ACCESSIBILITY_SCORE_WEIGHT_Y', 1000000.0);   // Weight for Y coordinate (Depth - Lower is better)
define('ACCESSIBILITY_SCORE_WEIGHT_Z', 1000.0);      // Weight for Z coordinate (Height - Lower is better)
define('ACCESSIBILITY_SCORE_WEIGHT_X', 1.0);         // Weight for X coordinate (Width - Lower is better)

// --- Other Constants ---
define('FLOAT_EPSILON', 0.001);      // Small value for float comparisons
define('POSITION_EPSILON', 0.1);     // How close coordinates need to be to be considered the "same" position (e.g., for avoiding original spot)

// #########################################################################
// ## START: Helper Functions                                            ##
// #########################################################################

/**
 * Generates unique possible orientations for an item.
 */
function generateOrientations(array $dimensions): array {
    $width = (float)($dimensions['width'] ?? 0);
    $depth = (float)($dimensions['depth'] ?? 0);
    $height = (float)($dimensions['height'] ?? 0);
    if ($width <= 0 || $depth <= 0 || $height <= 0) { return []; }
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

/**
 * Checks if two 3D boxes overlap, using an epsilon for float comparisons.
 */
function boxesOverlap(array $box1, array $box2): bool {
    $x1 = (float)($box1['x'] ?? 0); $y1 = (float)($box1['y'] ?? 0); $z1 = (float)($box1['z'] ?? 0);
    $w1 = (float)($box1['w'] ?? 0); $d1 = (float)($box1['d'] ?? 0); $h1 = (float)($box1['h'] ?? 0);
    $x2 = (float)($box2['x'] ?? 0); $y2 = (float)($box2['y'] ?? 0); $z2 = (float)($box2['z'] ?? 0);
    $w2 = (float)($box2['w'] ?? 0); $d2 = (float)($box2['d'] ?? 0); $h2 = (float)($box2['h'] ?? 0);
    $noOverlapX = ($x1 + $w1 <= $x2 + FLOAT_EPSILON) || ($x2 + $w2 <= $x1 + FLOAT_EPSILON);
    $noOverlapY = ($y1 + $d1 <= $y2 + FLOAT_EPSILON) || ($y2 + $d2 <= $y1 + FLOAT_EPSILON);
    $noOverlapZ = ($z1 + $h1 <= $z2 + FLOAT_EPSILON) || ($z2 + $h2 <= $z1 + FLOAT_EPSILON);
    return !($noOverlapX || $noOverlapY || $noOverlapZ);
}

/**
 * Finds the best available position for an item IN A SPECIFIC CONTAINER using a surface
 * placement heuristic. Prioritizes placement closest to the open face (min Y),
 * then bottom (min Z), then left (min X). Includes point generation behind items.
 * (Standard placement function - checks ONE container)
 *
 * @param string $itemId The ID of the item being placed (for logging).
 * @param array $itemDimensionsApi Dimensions of the item {'width', 'depth', 'height'}.
 * @param int $itemPriority Priority of the item being placed.
 * @param string $containerId ID of the container being checked.
 * @param array $containerDimensionsApi Dimensions of the container.
 * @param array $existingItems Items already placed in this specific container.
 * @return ?array Best placement found {'foundX', ..., 'score' (geometric score)} or null.
 */
function findSpaceForItem(string $itemId, array $itemDimensionsApi, int $itemPriority, string $containerId, array $containerDimensionsApi, array $existingItems): ?array
{
    $orientations = generateOrientations($itemDimensionsApi);
    if (empty($orientations)) { return null; }
    $bestPlacement = null; $bestScore = null;
    $containerW = (float)($containerDimensionsApi['width'] ?? 0); $containerD = (float)($containerDimensionsApi['depth'] ?? 0); $containerH = (float)($containerDimensionsApi['height'] ?? 0);
    if ($containerW <= 0 || $containerD <= 0 || $containerH <= 0) { return null; }

    // --- Candidate Point Generation ---
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
             if ($pt['x'] < $containerW + FLOAT_EPSILON && $pt['y'] < $containerD + FLOAT_EPSILON && $pt['z'] < $containerH + FLOAT_EPSILON) {
                 $key = sprintf("%.3f_%.3f_%.3f", round($pt['x'], 3), round($pt['y'], 3), round($pt['z'], 3));
                 if (!isset($candidatePoints[$key])) { $candidatePoints[$key] = $pt; }
             }
         }
    } $candidatePoints = array_values($candidatePoints);

    // --- Loop through Orientations and Candidate Points ---
    foreach ($orientations as $orientation) {
        $itemW = (float)$orientation['width']; $itemD = (float)$orientation['depth']; $itemH = (float)$orientation['height'];
        if ($itemW > $containerW + FLOAT_EPSILON || $itemD > $containerD + FLOAT_EPSILON || $itemH > $containerH + FLOAT_EPSILON) continue;

        foreach ($candidatePoints as $point) {
            $x = (float)$point['x']; $y = (float)$point['y']; $z = (float)$point['z'];
            if (($x + $itemW > $containerW + FLOAT_EPSILON) || ($y + $itemD > $containerD + FLOAT_EPSILON) || ($z + $itemH > $containerH + FLOAT_EPSILON)) continue;

            $potentialPlacement = ['x' => $x, 'y' => $y, 'z' => $z, 'w' => $itemW, 'd' => $itemD, 'h' => $itemH];
            $hasCollision = false; foreach ($existingItems as $existingItem) { if (boxesOverlap($potentialPlacement, $existingItem)) { $hasCollision = true; break; } }

            if (!$hasCollision) {
                // Geometric Score: Prioritize Min Y, then Min Z, then Min X
                $currentScore = ($y * ACCESSIBILITY_SCORE_WEIGHT_Y) + ($z * ACCESSIBILITY_SCORE_WEIGHT_Z) + ($x * ACCESSIBILITY_SCORE_WEIGHT_X);

                if ($bestScore === null || $currentScore < $bestScore) {
                    $bestScore = $currentScore;
                    $bestPlacement = ['foundX' => $x, 'foundY' => $y, 'foundZ' => $z, 'placedW' => $itemW, 'placedD' => $itemD, 'placedH' => $itemH, 'score' => $bestScore];
                }
            }
        }
    }
    return $bestPlacement;
}


/**
 * Finds the best place to RELOCATE a low-priority item, avoiding its original spot
 * and the ideal spot being cleared for a high-priority item. Prioritizes less
 * desirable locations implicitly via container search order. Returns the *first* valid spot found.
 *
 * @param string $itemToMoveId ID of the low-priority item being moved.
 * @param array $itemDimensions Original dimensions of the item to move.
 * @param string $originalContainerId The container the item is currently in.
 * @param array $originalPosition The item's current position data {'x','y','z','w','d','h'}.
 * @param array $allContainerDimensions Map of all containers.
 * @param array $itemsMasterList Master list for priorities.
 * @param array $currentPlacementState The *current*, potentially modified, placement state during rearrangement.
 * @param string $highPriorityItemId ID of the item we're making space for.
 * @param array $idealSpotToClear Coordinates {'x','y','z','w','d','h'} of the spot being cleared in the original container.
 *
 * @return ?array Best relocation spot {'containerId', 'foundX', ...} or null.
 */
function findBestRelocationSpot(
    string $itemToMoveId, array $itemDimensions,
    string $originalContainerId, array $originalPosition,
    array $allContainerDimensions, array $itemsMasterList,
    array $currentPlacementState,
    string $highPriorityItemId, array $idealSpotToClear
): ?array {

    $orientations = generateOrientations($itemDimensions);
    if (empty($orientations)) return null;

    // Define search order: Prioritize keeping in same container, then less preferred zones.
    $containerSearchOrder = [];
    // 1. Same container (if valid)
    if (isset($allContainerDimensions[$originalContainerId])) {
        $containerSearchOrder[$originalContainerId] = $allContainerDimensions[$originalContainerId];
    }
    // 2. Containers in less preferred zones
    $originalZone = $allContainerDimensions[$originalContainerId]['zone'] ?? 'UnknownZone_Reloc';
    foreach($allContainerDimensions as $cId => $cData) {
        if ($cId === $originalContainerId) continue; // Already added
        $zone = $cData['zone'] ?? 'UnknownZone_Reloc';
        // Define "less preferred" - for now, any zone that is NOT the original zone
        if ($zone !== $originalZone) {
             if (!isset($containerSearchOrder[$cId])) $containerSearchOrder[$cId] = $cData;
        }
    }
    // 3. Any remaining containers (including others in the original zone)
     foreach($allContainerDimensions as $cId => $cData) {
         if (!isset($containerSearchOrder[$cId])) { // If not already added
              $containerSearchOrder[$cId] = $cData;
         }
     }

    // --- Search through containers in the defined order ---
    foreach ($containerSearchOrder as $containerId => $containerDims) {
        $itemsInThisContainer = $currentPlacementState[$containerId] ?? [];
        $containerW = (float)$containerDims['width']; $containerD = (float)$containerDims['depth']; $containerH = (float)$containerDims['height'];
        if ($containerW <= 0 || $containerD <= 0 || $containerH <= 0) continue;

        // Generate Candidate Points for this container
        $candidatePoints = []; $originKey = sprintf("%.3f_%.3f_%.3f", 0.0, 0.0, 0.0); $candidatePoints[$originKey] = ['x' => 0.0, 'y' => 0.0, 'z' => 0.0];
        foreach ($itemsInThisContainer as $existing) {
             // We might be checking a state where the item to move *hasn't* been removed yet. Skip self.
             if ($existing['id'] === $itemToMoveId) continue;
             $ex = (float)($existing['x'] ?? 0); $ey = (float)($existing['y'] ?? 0); $ez = (float)($existing['z'] ?? 0);
             $ew = (float)($existing['w'] ?? 0); $ed = (float)($existing['d'] ?? 0); $eh = (float)($existing['h'] ?? 0);
             $pointsToAdd = [
                 ['x' => $ex,       'y' => $ey,       'z' => $ez + $eh], // Top
                 ['x' => $ex + $ew, 'y' => $ey,       'z' => $ez],       // Right
                 ['x' => $ex,       'y' => $ey + $ed, 'z' => $ez]        // Behind
             ];
              foreach ($pointsToAdd as $pt) {
                  if ($pt['x'] < $containerW + FLOAT_EPSILON && $pt['y'] < $containerD + FLOAT_EPSILON && $pt['z'] < $containerH + FLOAT_EPSILON) {
                      $key = sprintf("%.3f_%.3f_%.3f", round($pt['x'], 3), round($pt['y'], 3), round($pt['z'], 3));
                      if (!isset($candidatePoints[$key])) { $candidatePoints[$key] = $pt; }
                  }
              }
        } $candidatePoints = array_values($candidatePoints);

        // Check Orientations at Candidate Points in this container
        foreach ($orientations as $orientation) {
            $itemW = (float)$orientation['width']; $itemD = (float)$orientation['depth']; $itemH = (float)$orientation['height'];
            if ($itemW > $containerW + FLOAT_EPSILON || $itemD > $containerD + FLOAT_EPSILON || $itemH > $containerH + FLOAT_EPSILON) continue;

            foreach ($candidatePoints as $point) {
                $x = (float)$point['x']; $y = (float)$point['y']; $z = (float)$point['z'];
                if (($x + $itemW > $containerW + FLOAT_EPSILON) || ($y + $itemD > $containerD + FLOAT_EPSILON) || ($z + $itemH > $containerH + FLOAT_EPSILON)) continue;

                $potentialPlacement = ['id' => $itemToMoveId, 'x' => $x, 'y' => $y, 'z' => $z, 'w' => $itemW, 'd' => $itemD, 'h' => $itemH];

                // A. Check Collision with other items in this container
                $hasCollision = false;
                 foreach ($itemsInThisContainer as $existingItem) {
                      if ($existingItem['id'] === $itemToMoveId) continue; // Skip self check
                      if (boxesOverlap($potentialPlacement, $existingItem)) { $hasCollision = true; break; }
                 }
                 if ($hasCollision) continue;

                 // B. Check: Avoid Original Position (only if checking the original container)
                 $isOriginalPos = false;
                 if ($containerId === $originalContainerId &&
                    abs($x - (float)$originalPosition['x']) < POSITION_EPSILON &&
                    abs($y - (float)$originalPosition['y']) < POSITION_EPSILON &&
                    abs($z - (float)$originalPosition['z']) < POSITION_EPSILON) {
                     $isOriginalPos = true;
                 }
                 if ($isOriginalPos) continue;

                 // C. Check: Avoid the Ideal Spot being cleared (only if checking the original container)
                 $isIdealSpot = false;
                 if ($containerId === $originalContainerId) {
                     // Check overlap between the potential *relocation* spot for the blocker
                     // and the *ideal spot* we want for the high-priority item.
                     if (boxesOverlap($potentialPlacement, $idealSpotToClear)) {
                         $isIdealSpot = true;
                     }
                 }
                 if ($isIdealSpot) continue;

                // *** Valid Relocation Spot Found ***
                // Since we iterate containers in order of preference (same -> less pref -> other),
                // and points/orientations within are checked systematically,
                // the *first* valid spot we find is our chosen relocation spot.
                 error_log("findBestRelocationSpot: Found valid spot for $itemToMoveId in $containerId at (Y=$y, Z=$z, X=$x)");
                 return [
                     'containerId' => $containerId,
                     'foundX' => $x, 'foundY' => $y, 'foundZ' => $z,
                     'placedW' => $itemW, 'placedD' => $itemD, 'placedH' => $itemH
                 ];

            } // End point loop
        } // End orientation loop
    } // End container loop

    // If entire search completes, no suitable relocation spot found
    error_log("findBestRelocationSpot: Could not find any valid relocation spot for $itemToMoveId.");
    return null;
}


/**
 * Attempts to make space for a high-priority item by rearranging low-priority items.
 * Returns the required moves, final placement, and updated state if successful.
 *
 * @param string $itemIdToPlace The high-priority item needing placement.
 * @param array $itemDimensionsApi Dimensions of the item to place.
 * @param int $itemPriority Priority of the item to place (assumed HIGH).
 * @param ?string $preferredContainerId Specific preferred container, if any.
 * @param ?string $preferredZone Preferred zone, if any.
 * @param array $allContainerDimensions Map of all container dimensions and zones.
 * @param array $currentPlacementState Current state of all placed items across containers.
 * @param array $itemsMasterList Master list with priorities and original dimensions.
 * @param int $stepCounter Current step number for rearrangement logging.
 *
 * @return array Result structure: ['success', 'reason', 'moves', 'finalPlacement', 'newState', 'nextStep']
 */
function attemptRearrangementForHighPriorityItem(
    string $itemIdToPlace, array $itemDimensionsApi, int $itemPriority,
    ?string $preferredContainerId, ?string $preferredZone,
    array $allContainerDimensions, array $currentPlacementState,
    array $itemsMasterList, int $stepCounter
): array {

    error_log("Rearrange Triggered for $itemIdToPlace (Prio: $itemPriority)");
    $rearrangementMoves = [];
    $tempPlacementState = $currentPlacementState; // IMPORTANT: Work on a deep copy if modifying nested arrays directly
                                                   // PHP arrays are copy-on-write, but nested modifications can be tricky.
                                                   // For this structure, direct modification should be okay, but be mindful.

    // 1. Identify Target Containers (Prefer specific, then zone, else fail)
    $targetContainerIds = [];
    if ($preferredContainerId !== null && isset($allContainerDimensions[$preferredContainerId])) {
        $targetContainerIds[] = $preferredContainerId;
    } elseif ($preferredZone !== null) {
        foreach ($allContainerDimensions as $cId => $cData) {
            if (($cData['zone'] ?? null) === $preferredZone) {
                $targetContainerIds[] = $cId;
            }
        }
    }

    if (empty($targetContainerIds)) {
         $reason = $preferredZone ? "Preferred zone '$preferredZone' has no valid containers or preference invalid." : "No preferred container/zone specified for high-priority item.";
         error_log("Rearrange for $itemIdToPlace: FAILED - $reason");
        return ['success' => false, 'reason' => $reason, 'moves' => [], 'finalPlacement' => null, 'newState' => $currentPlacementState, 'nextStep' => $stepCounter];
    }
     error_log("Rearrange for $itemIdToPlace: Targeting containers: " . implode(', ', $targetContainerIds));

    // 2. Iterate through TARGET containers, trying to find/make space
    foreach ($targetContainerIds as $targetContainerId) {
        error_log("Rearrange for $itemIdToPlace: Assessing target container $targetContainerId");
        $targetContainerDims = $allContainerDimensions[$targetContainerId];
        $itemsInTargetContainerOriginal = $tempPlacementState[$targetContainerId] ?? []; // State before moves for *this container*

        // 3. Find the *ideal* spot in this target container (using findSpaceForItem on empty)
        $potentialIdealPlacement = findSpaceForItem(
            $itemIdToPlace, $itemDimensionsApi, $itemPriority,
            $targetContainerId, $targetContainerDims, [] // Check against EMPTY
        );

        if ($potentialIdealPlacement === null) {
            error_log("Rearrange for $itemIdToPlace: Item doesn't fit geometrically in target container $targetContainerId. Skipping.");
            continue;
        }
        $idealCoords = ['x' => $potentialIdealPlacement['foundX'], 'y' => $potentialIdealPlacement['foundY'], 'z' => $potentialIdealPlacement['foundZ'], 'w' => $potentialIdealPlacement['placedW'], 'd' => $potentialIdealPlacement['placedD'], 'h' => $potentialIdealPlacement['placedH']];
         error_log("Rearrange for $itemIdToPlace: Ideal spot in $targetContainerId identified: (Y=$idealCoords[y], Z=$idealCoords[z], X=$idealCoords[x])");

        // 4. Identify Blockers: Which LOW_PRIORITY items overlap this ideal spot?
        $blockersToMove = []; // Array of ['id', 'index', 'data', 'originalDims']
        $foundHighPriorityBlocker = false;
        foreach ($itemsInTargetContainerOriginal as $index => $existingItem) {
            if (boxesOverlap($idealCoords, $existingItem)) {
                $blockerId = $existingItem['id'];
                if (!isset($itemsMasterList[$blockerId])) {
                     error_log("Rearrange for $itemIdToPlace: CRITICAL - Missing master data for potential blocker $blockerId. Aborting for this container.");
                     $foundHighPriorityBlocker = true; break; // Treat as high priority if data missing
                }
                $blockerPriority = $itemsMasterList[$blockerId]['priority'] ?? 999;
                $blockerOriginalDimensions = $itemsMasterList[$blockerId]['dimensions_api'] ?? null;

                if (!$blockerOriginalDimensions || ($blockerOriginalDimensions['width'] ?? 0) <=0) { // More robust check
                    error_log("Rearrange for $itemIdToPlace: CRITICAL - Missing/Invalid original dimensions for potential blocker $blockerId. Aborting for this container.");
                    $foundHighPriorityBlocker = true; break; // Treat as high priority
                }

                if ($blockerPriority <= LOW_PRIORITY_THRESHOLD) {
                    error_log("Rearrange for $itemIdToPlace: Found LOW priority blocker $blockerId (Prio: $blockerPriority) at index $index in $targetContainerId.");
                    $blockersToMove[] = ['id' => $blockerId, 'index' => $index, 'data' => $existingItem, 'originalDims' => $blockerOriginalDimensions];
                } else {
                     error_log("Rearrange for $itemIdToPlace: Found HIGH priority blocker $blockerId (Prio: $blockerPriority >= " . LOW_PRIORITY_THRESHOLD . "). Cannot move it. Aborting for this container ($targetContainerId).");
                    $foundHighPriorityBlocker = true;
                    break; // Stop checking blockers for this container
                }
            }
        }

        if ($foundHighPriorityBlocker) {
            $blockersToMove = []; // Clear any identified low-prio blockers
             error_log("Rearrange for $itemIdToPlace: Aborting attempt for container $targetContainerId due to non-movable blocker.");
            continue; // Try next target container
        }

        // 5. Attempt to Relocate Blockers (if any)
        $allBlockersRelocatedSuccessfully = true;
        $currentMovesForThisAttempt = []; // Track moves specific to this target container attempt
        $tempStateForThisAttempt = $tempPlacementState; // State evolves as we plan moves

        if (!empty($blockersToMove)) {
            error_log("Rearrange for $itemIdToPlace: Attempting to relocate " . count($blockersToMove) . " blockers in $targetContainerId.");
            // Sort blockers? Maybe by volume (smaller first) or Y coord (front first)? Simple order for now.
            foreach ($blockersToMove as $blockerInfo) {
                $blockerId = $blockerInfo['id'];
                $blockerData = $blockerInfo['data']; // Current position/dims
                $blockerOriginalDimensions = $blockerInfo['originalDims'];

                error_log("Rearrange for $itemIdToPlace: Finding relocation spot for blocker $blockerId...");

                // *** Call relocation function using the *evolving* temporary state ***
                $relocationSpot = findBestRelocationSpot(
                    $blockerId,
                    $blockerOriginalDimensions,
                    $targetContainerId,     // Current container of blocker
                    $blockerData,           // Original position to avoid
                    $allContainerDimensions,
                    $itemsMasterList,
                    $tempStateForThisAttempt, // Use the state reflecting previous moves in *this attempt*
                    $itemIdToPlace,         // Item we are making space for
                    $idealCoords            // The ideal spot we are trying to clear
                );

                if ($relocationSpot) {
                    $newContId = $relocationSpot['containerId'];
                    $newX = $relocationSpot['foundX']; $newY = $relocationSpot['foundY']; $newZ = $relocationSpot['foundZ'];
                    $newW = $relocationSpot['placedW']; $newD = $relocationSpot['placedD']; $newH = $relocationSpot['placedH'];
                    error_log("Rearrange for $itemIdToPlace: Found relocation spot for blocker $blockerId in $newContId at (Y=$newY, Z=$newZ, X=$newX). Recording move.");

                    // Record the move details
                    $moveData = [
                        'step' => $stepCounter + count($currentMovesForThisAttempt), // Provisional step number
                        'action' => 'move',
                        'itemId' => $blockerId,
                        'fromContainer' => $targetContainerId,
                        'fromPosition' => formatApiPosition($blockerData['x'], $blockerData['y'], $blockerData['z'], $blockerData['w'], $blockerData['d'], $blockerData['h']),
                        'toContainer' => $newContId,
                        'toPosition' => formatApiPosition($newX, $newY, $newZ, $newW, $newD, $newH)
                    ];
                    $currentMovesForThisAttempt[] = [
                         'apiResponse' => $moveData,
                         'itemId' => $blockerId,
                         'dbUpdate' => ['action' => 'move', 'itemId' => $blockerId, 'containerId' => $newContId, 'positionX' => $newX, 'positionY' => $newY, 'positionZ' => $newZ, 'placedDimensionW' => $newW, 'placedDimensionD' => $newD, 'placedDimensionH' => $newH ]
                    ];

                    // *** Update the temporary state for the *next* blocker's relocation check ***
                     // Remove from old container state in the temporary copy
                     $found = false;
                     if (isset($tempStateForThisAttempt[$targetContainerId])) {
                         foreach ($tempStateForThisAttempt[$targetContainerId] as $idx => $item) {
                             if ($item['id'] === $blockerId) {
                                 unset($tempStateForThisAttempt[$targetContainerId][$idx]);
                                 $tempStateForThisAttempt[$targetContainerId] = array_values($tempStateForThisAttempt[$targetContainerId]);
                                 $found = true; break;
                             }
                         }
                     }
                     if (!$found) error_log("Rearrange for $itemIdToPlace: WARN - Blocker $blockerId not found in old container $targetContainerId during temp state update.");

                     // Add to new container state in the temporary copy
                     if (!isset($tempStateForThisAttempt[$newContId])) $tempStateForThisAttempt[$newContId] = [];
                     $tempStateForThisAttempt[$newContId][] = ['id' => $blockerId, 'x' => $newX, 'y' => $newY, 'z' => $newZ, 'w' => $newW, 'd' => $newD, 'h' => $newH];
                     // No need to re-index target container here, done after loop if successful

                } else {
                    error_log("Rearrange for $itemIdToPlace: FAILED to find relocation spot for blocker $blockerId. Aborting rearrangement for $targetContainerId.");
                    $allBlockersRelocatedSuccessfully = false;
                    // IMPORTANT: If one blocker fails, the entire attempt for this container fails.
                    // No need to revert state as $tempStateForThisAttempt is local to this loop iteration.
                    $currentMovesForThisAttempt = []; // Discard moves planned for this container
                    break; // Stop trying to move blockers for this container
                }
            } // End loop through blockers for this container
        } // End if blockers needed moving

        if (!$allBlockersRelocatedSuccessfully) {
            error_log("Rearrange for $itemIdToPlace: Skipping $targetContainerId due to blocker relocation failure.");
            continue; // Try the next target container
        }

        // 6. Verify Final Placement: Can the high-priority item *now* fit in the ideal spot
        //    using the state reflecting the successful moves?
        $finalPlacementCheck = findSpaceForItem(
            $itemIdToPlace, $itemDimensionsApi, $itemPriority,
            $targetContainerId, $targetContainerDims,
            $tempStateForThisAttempt[$targetContainerId] ?? [] // Use the state after hypothetical moves
        );

        // Check if the found spot is the ideal spot (or very close to it)
        $isIdealSpotFound = false;
        if ($finalPlacementCheck !== null &&
            abs($finalPlacementCheck['foundX'] - $idealCoords['x']) < POSITION_EPSILON &&
            abs($finalPlacementCheck['foundY'] - $idealCoords['y']) < POSITION_EPSILON &&
            abs($finalPlacementCheck['foundZ'] - $idealCoords['z']) < POSITION_EPSILON)
        {
             $isIdealSpotFound = true;
        }

        if ($isIdealSpotFound) {
            error_log("Rearrange for $itemIdToPlace: SUCCESS! Final placement verified in $targetContainerId after moving blockers.");
            $finalX = $finalPlacementCheck['foundX']; $finalY = $finalPlacementCheck['foundY']; $finalZ = $finalPlacementCheck['foundZ'];
            $finalW = $finalPlacementCheck['placedW']; $finalD = $finalPlacementCheck['placedD']; $finalH = $finalPlacementCheck['placedH'];

            // Finalize step numbers for the committed moves
            $committedMoves = [];
            foreach ($currentMovesForThisAttempt as $move) {
                 $move['apiResponse']['step'] = $stepCounter++; // Assign final step number
                 $committedMoves[] = $move;
            }


            // Prepare final placement data
            $finalPlacementData = [
                'apiResponse' => [ // For the rearrangement steps array
                    'step' => $stepCounter++,
                    'action' => 'place',
                    'itemId' => $itemIdToPlace,
                    'fromContainer' => null, 'fromPosition' => null,
                    'toContainer' => $targetContainerId,
                    'toPosition' => formatApiPosition($finalX, $finalY, $finalZ, $finalW, $finalD, $finalH)
                ],
                'placementResponse' => [ // For the main 'placements' array
                    'itemId' => $itemIdToPlace,
                    'containerId' => $targetContainerId,
                    'position' => formatApiPosition($finalX, $finalY, $finalZ, $finalW, $finalD, $finalH)
                ],
                'dbUpdate' => ['action' => 'place', 'itemId' => $itemIdToPlace, 'containerId' => $targetContainerId, 'positionX' => $finalX, 'positionY' => $finalY, 'positionZ' => $finalZ, 'placedDimensionW' => $finalW, 'placedDimensionD' => $finalD, 'placedDimensionH' => $finalH ]
            ];

            // Add the final placement to the temporary state as well before returning it
             if (!isset($tempStateForThisAttempt[$targetContainerId])) $tempStateForThisAttempt[$targetContainerId] = [];
             $tempStateForThisAttempt[$targetContainerId][] = ['id' => $itemIdToPlace, 'x' => $finalX, 'y' => $finalY, 'z' => $finalZ, 'w' => $finalW, 'd' => $finalD, 'h' => $finalH];
             $tempStateForThisAttempt[$targetContainerId] = array_values($tempStateForThisAttempt[$targetContainerId]); // Re-index after adding


            // Return success with the committed moves and the final state
            return [
                'success' => true,
                'reason' => 'Rearrangement successful in container ' . $targetContainerId,
                'moves' => $committedMoves, // Moves that were successfully planned and executed
                'finalPlacement' => $finalPlacementData,
                'newState' => $tempStateForThisAttempt, // Return the fully updated state reflecting moves and final placement
                'nextStep' => $stepCounter // Return the updated step counter
            ];

        } else {
            error_log("Rearrange for $itemIdToPlace: FAILED FINAL CHECK in $targetContainerId. Item couldn't be placed in ideal spot even after alleged blocker moves. Check results: " . json_encode($finalPlacementCheck));
            // Discard moves ($currentMovesForThisAttempt is not used) and try the next target container.
            continue;
        }

    } // End loop through target containers

    // If loop finishes without success in any target container
    error_log("Rearrange for $itemIdToPlace: Exhausted all target containers. Rearrangement failed.");
    return ['success' => false, 'reason' => 'Could not find suitable rearrangement solution in any target container.', 'moves' => [], 'finalPlacement' => null, 'newState' => $currentPlacementState, 'nextStep' => $stepCounter]; // Return original state
}


/**
 * Helper to format position array for API response.
 */
function formatApiPosition(float $x, float $y, float $z, float $w, float $d, float $h): array {
    return [
        'startCoordinates' => ['width' => round($x, 3), 'depth' => round($y, 3), 'height' => round($z, 3)],
        'endCoordinates' => ['width' => round($x + $w, 3), 'depth' => round($y + $d, 3), 'height' => round($z + $h, 3)]
    ];
}

// #########################################################################
// ## END: HELPER FUNCTIONS                                              ##
// #########################################################################


// --- Script Start ---
$response = ['success' => false, 'placements' => [], 'rearrangements' => []];
$internalErrors = [];
$db = null;
$itemsMasterList = []; // Store priority, original dimensions, preferences

// --- Database Connection ---
try { $db = getDbConnection(); if ($db === null) throw new Exception("DB null"); }
catch (Exception $e) { http_response_code(503); error_log("FATAL: DB Connect Error - " . $e->getMessage()); echo json_encode(['success' => false, 'message' => 'DB connection error.']); exit; }

// --- Input Processing ---
$rawData = file_get_contents('php://input'); $requestData = json_decode($rawData, true);
if ($requestData === null || !isset($requestData['items'], $requestData['containers']) || !is_array($requestData['items']) || !is_array($requestData['containers'])) {
    http_response_code(400); error_log("Placement Error: Invalid JSON input: " . $rawData); echo json_encode(['success' => false, 'message' => 'Invalid input format.']); exit;
}
$itemsToPlaceInput = $requestData['items']; $containersInput = $requestData['containers'];
$containerDimensionsMap = []; foreach ($containersInput as $c) { if (isset($c['containerId'], $c['width'], $c['depth'], $c['height'])) { $containerDimensionsMap[$c['containerId']] = ['width' => (float)$c['width'], 'depth' => (float)$c['depth'], 'height' => (float)$c['height'], 'zone' => $c['zone'] ?? 'UnknownZone']; } else { error_log("Skipping invalid container data: ".json_encode($c)); } }
error_log(PLACEMENT_ALGORITHM_NAME . " request: " . count($itemsToPlaceInput) . " items, " . count($containerDimensionsMap) . " valid containers.");


// --- Load Existing Item State from DB (Crucial for rearrangements) ---
$existingPlacedItemsByContainer = [];
// $existingItemsMasterList will be populated below and merged with input item data
try {
    $sqlPlaced = "SELECT i.itemId, i.containerId, i.priority,
                         i.dimensionW, i.dimensionD, i.dimensionH,             -- Original item dimensions
                         i.placedDimensionW, i.placedDimensionD, i.placedDimensionH, -- Placed dimensions
                         i.positionX AS posX, i.positionY AS posY, i.positionZ AS posZ,
                         i.preferredContainerId, i.preferredZone -- Load preferences too
                  FROM items i
                  WHERE i.containerId IS NOT NULL AND i.status = 'stowed'";
    $stmtPlaced = $db->prepare($sqlPlaced); $stmtPlaced->execute(); $placedItemsResult = $stmtPlaced->fetchAll(PDO::FETCH_ASSOC);

    foreach ($placedItemsResult as $item) {
        $containerId = $item['containerId'];
        if (!isset($existingPlacedItemsByContainer[$containerId])) { $existingPlacedItemsByContainer[$containerId] = []; }
        $placementData = [ 'id' => $item['itemId'], 'x' => (float)$item['posX'], 'y' => (float)$item['posY'], 'z' => (float)$item['posZ'], 'w' => (float)$item['placedDimensionW'], 'd' => (float)$item['placedDimensionD'], 'h' => (float)$item['placedDimensionH'] ];
        $existingPlacedItemsByContainer[$containerId][] = $placementData;

        // Populate master list with data for existing items
        $itemsMasterList[$item['itemId']] = [
            'priority' => (int)($item['priority'] ?? 0),
            'dimensions_api' => [ // Use original dimensions if available, else placed
                'width' => (float)($item['dimensionW'] ?: $item['placedDimensionW']),
                'depth' => (float)($item['dimensionD'] ?: $item['placedDimensionD']),
                'height' => (float)($item['dimensionH'] ?: $item['placedDimensionH'])
            ],
            'placement' => $placementData, // Store current placement
            'preferredContainerId' => $item['preferredContainerId'] ?? null,
            'preferredZone' => $item['preferredZone'] ?? null
         ];
    }
    error_log("Found existing placements for " . count($itemsMasterList) . " items in " . count($existingPlacedItemsByContainer) . " containers from DB.");
} catch (PDOException $e) { http_response_code(500); $response = ['success' => false, 'message' => 'DB error loading existing items.']; error_log("Placement DB Error (fetch existing): " . $e->getMessage()); echo json_encode($response); $db = null; exit; }

// --- Merge incoming item data into master list (overwriting/adding) ---
foreach ($itemsToPlaceInput as $item) {
    if (isset($item['itemId'])) {
        $itemId = $item['itemId'];
        // If item already exists in master list (from DB load), update its details. Otherwise, add it.
        $itemsMasterList[$itemId] = [
            'priority' => (int)($item['priority'] ?? ($itemsMasterList[$itemId]['priority'] ?? 0)), // Use new priority if provided
            'dimensions_api' => [ // Use new dimensions if provided
                'width' => (float)($item['width'] ?? ($itemsMasterList[$itemId]['dimensions_api']['width'] ?? 0)),
                'depth' => (float)($item['depth'] ?? ($itemsMasterList[$itemId]['dimensions_api']['depth'] ?? 0)),
                'height' => (float)($item['height'] ?? ($itemsMasterList[$itemId]['dimensions_api']['height'] ?? 0))
            ],
            'placement' => $itemsMasterList[$itemId]['placement'] ?? null, // Keep existing placement if any
            'preferredContainerId' => $item['preferredContainerId'] ?? ($itemsMasterList[$itemId]['preferredContainerId'] ?? null), // Use new preference if provided
            'preferredZone' => $item['preferredZone'] ?? ($itemsMasterList[$itemId]['preferredZone'] ?? null)
         ];
    }
}
error_log("Items Master List populated/updated. Total items considered: " . count($itemsMasterList));


// --- Placement Algorithm Logic ---
$currentPlacementState = $existingPlacedItemsByContainer; // Start with the loaded state
$dbUpdates = [];           // Collect DB changes needed {'itemId' => ['action'=>'place'/'move', ...]}
$rearrangementSteps = [];  // Collect rearrangement steps for the API response [{step:1, action:'move',...}, {step:2, action:'place',...}]
$stepCounter = 1;          // For ordering rearrangement steps

// --- Sorting Incoming Items (Priority High->Low, then Volume Large->Small) ---
if (!empty($itemsToPlaceInput)) {
    error_log("Sorting " . count($itemsToPlaceInput) . " incoming items...");
    usort($itemsToPlaceInput, function($a, $b) use ($itemsMasterList) {
         // Use priority from the potentially updated itemsMasterList
         $priorityA = $itemsMasterList[$a['itemId']]['priority'] ?? 0;
         $priorityB = $itemsMasterList[$b['itemId']]['priority'] ?? 0;
         if ($priorityA !== $priorityB) { return $priorityB <=> $priorityA; } // Descending Priority

         // Use dimensions from the potentially updated itemsMasterList
         $dimsA = $itemsMasterList[$a['itemId']]['dimensions_api'] ?? ['width'=>0,'depth'=>0,'height'=>0];
         $dimsB = $itemsMasterList[$b['itemId']]['dimensions_api'] ?? ['width'=>0,'depth'=>0,'height'=>0];
         $volumeA = $dimsA['width'] * $dimsA['depth'] * $dimsA['height'];
         $volumeB = $dimsB['width'] * $dimsB['depth'] * $dimsB['height'];
         if (abs($volumeA - $volumeB) > FLOAT_EPSILON) { return $volumeB <=> $volumeA; } // Descending Volume

         return ($a['itemId'] ?? '') <=> ($b['itemId'] ?? ''); // Item ID for tiebreak
    });
     error_log("Items sorted. First item to process: " . ($itemsToPlaceInput[0]['itemId'] ?? 'None'));
}

// --- Main Placement Loop (Processing sorted incoming items) ---
foreach ($itemsToPlaceInput as $itemToPlace) {
    $itemPlaced = false;
    $currentItemId = $itemToPlace['itemId'] ?? null;

    // --- Basic Item Validation ---
    if ($currentItemId === null || !isset($itemsMasterList[$currentItemId])) { error_log("Skipping item - Missing ID or not in Master List: ".json_encode($itemToPlace)); $internalErrors[] = ['itemId' => $currentItemId ?? 'Unknown', 'reason' => 'Invalid item ID or missing master data.']; continue; }
    $itemMasterData = $itemsMasterList[$currentItemId];
    $itemDimensionsApi = $itemMasterData['dimensions_api'];
    if (($itemDimensionsApi['width'] ?? 0) <= 0 || ($itemDimensionsApi['depth'] ?? 0) <= 0 || ($itemDimensionsApi['height'] ?? 0) <= 0) { error_log("Skipping invalid item dimensions for $currentItemId: ".json_encode($itemDimensionsApi)); $internalErrors[] = ['itemId' => $currentItemId, 'reason' => 'Invalid item dimensions (zero or negative).']; continue; }
    $currentItemPriority = $itemMasterData['priority'];
    $preferredContainerIdSpecific = $itemMasterData['preferredContainerId'];
    $preferredZone = $itemMasterData['preferredZone'];
    error_log("Processing Item $currentItemId (Priority: $currentItemPriority, PrefZone: $preferredZone, PrefCont: $preferredContainerIdSpecific)");

    // --- Determine Container Search Order (Based on Preferences) ---
    $containersToTryIds = []; $processedIds = [];
    // 1. Specific preferred container
    if ($preferredContainerIdSpecific !== null && isset($containerDimensionsMap[$preferredContainerIdSpecific])) { $containersToTryIds[] = $preferredContainerIdSpecific; $processedIds[$preferredContainerIdSpecific] = true; }
    // 2. Other containers in preferred zone
    if ($preferredZone !== null) { foreach ($containerDimensionsMap as $cId => $cData) { if (!isset($processedIds[$cId]) && ($cData['zone'] ?? null) === $preferredZone) { $containersToTryIds[] = $cId; $processedIds[$cId] = true; } } }
    // 3. All remaining containers
    foreach ($containerDimensionsMap as $cId => $cData) { if (!isset($processedIds[$cId])) { $containersToTryIds[] = $cId; } }
    error_log("Item $currentItemId: Container search order: " . implode(', ', $containersToTryIds));


    // --- Attempt Direct Placement - Find BEST *OVERALL* spot considering preference penalties ---
    $bestOverallPlacementData = null;   // Holds {'foundX', 'foundY',... 'score'} from findSpaceForItem
    $bestOverallAdjustedScore = null; // Score used for comparing across containers (includes penalties)
    $bestOverallContainerId = null;
    // *** MODIFICATION START 1 ***
    $idealSpotBlockedInPreferred = false; // *** NEW FLAG ***: Tracks if an ideal spot was blocked in any preferred container
    // *** MODIFICATION END 1 ***

    foreach ($containersToTryIds as $containerId) {
        if (!isset($containerDimensionsMap[$containerId])) continue; // Skip invalid container ID
        $containerDimensionsApi = $containerDimensionsMap[$containerId];
        $itemsCurrentlyInContainer = $currentPlacementState[$containerId] ?? [];
        $containerZone = $containerDimensionsMap[$containerId]['zone'] ?? null;

        // Find the best geometric spot *within this specific container*
        $placementInThisContainer = findSpaceForItem(
            $currentItemId, $itemDimensionsApi, $currentItemPriority,
            $containerId, $containerDimensionsApi, $itemsCurrentlyInContainer
        );

        if ($placementInThisContainer !== null) {
             $geometricScore = $placementInThisContainer['score'];
             $adjustedScore = $geometricScore; // Start with geometric score

             // *** MODIFICATION START 2 ***
             // Determine if this container is preferred for the current item
             $isCurrentContainerPreferred = false;
             if ($preferredContainerIdSpecific !== null && $preferredContainerIdSpecific === $containerId) $isCurrentContainerPreferred = true;
             elseif ($preferredContainerIdSpecific === null && $preferredZone !== null && $containerZone === $preferredZone) $isCurrentContainerPreferred = true;
             elseif ($preferredContainerIdSpecific === null && $preferredZone === null) $isCurrentContainerPreferred = true; // No preference = all are acceptable

             // Apply Penalty if this container is not preferred
             if (!$isCurrentContainerPreferred) {
                 $adjustedScore += PREFERRED_ZONE_SCORE_PENALTY;
                 error_log("Item $currentItemId: Adjusting score in non-preferred $containerId (Zone: $containerZone). Geom: $geometricScore, Adj: $adjustedScore");
             } else {
                  error_log("Item $currentItemId: Score in preferred $containerId (Zone: $containerZone). Geom: $geometricScore, Adj: $adjustedScore");
                  // *** NEW CHECK ***: If this is a preferred container and the score isn't ideal, flag it.
                  if ($geometricScore > FLOAT_EPSILON) {
                      error_log("Item $currentItemId: Ideal spot appears blocked in preferred container $containerId (Score: " . round($geometricScore,2) . " > 0). Setting flag.");
                      $idealSpotBlockedInPreferred = true;
                  }
             }

             // Compare with the best *adjusted* score found so far across all containers
             if ($bestOverallAdjustedScore === null || $adjustedScore < $bestOverallAdjustedScore) {
                 error_log("Item $currentItemId: Found NEW best overall spot in $containerId. Adjusted Score: $adjustedScore < Previous Best: " . ($bestOverallAdjustedScore ?? 'N/A'));
                 $bestOverallAdjustedScore = $adjustedScore;
                 $bestOverallPlacementData = $placementInThisContainer; // Store the raw placement details
                 $bestOverallContainerId = $containerId;
             }
             // *** MODIFICATION END 2 ***
        }
    } // End loop through containers to try for direct placement


    // --- Process the Best Found Spot (Direct Placement or Trigger Rearrangement) ---
    $triggerRearrangement = false;
    if ($bestOverallPlacementData !== null) {
        // A spot was found. Determine if the CHOSEN spot is preferred.
        $chosenContainerId = $bestOverallContainerId;
        $chosenContainerZone = $containerDimensionsMap[$chosenContainerId]['zone'] ?? null;
        $isChosenSpotPreferred = false; // Recalculate preference for the CHOSEN spot
        if ($preferredContainerIdSpecific !== null && $preferredContainerIdSpecific === $chosenContainerId) $isChosenSpotPreferred = true;
        elseif ($preferredContainerIdSpecific === null && $preferredZone !== null && $chosenContainerZone === $preferredZone) $isChosenSpotPreferred = true;
        elseif ($preferredContainerIdSpecific === null && $preferredZone === null) $isChosenSpotPreferred = true;

        // *** MODIFICATION START 3 ***
        // --- REVISED REARRANGEMENT TRIGGER LOGIC ---
        if ($currentItemPriority >= HIGH_PRIORITY_THRESHOLD) {
            // Condition 1: The best overall spot found is NOT preferred at all.
            if (!$isChosenSpotPreferred) {
                 error_log("Item $currentItemId (High Prio): Best direct spot found in $chosenContainerId (Zone: $chosenContainerZone) is NOT preferred. Triggering rearrangement attempt.");
                 $triggerRearrangement = true;
            }
            // Condition 2: The best overall spot IS preferred, BUT we detected earlier that an ideal spot was blocked in AT LEAST ONE preferred container.
            elseif ($idealSpotBlockedInPreferred) {
                 error_log("Item $currentItemId (High Prio): Best spot chosen is $chosenContainerId (preferred), but an ideal spot was blocked in some preferred container earlier. Triggering rearrangement attempt.");
                 $triggerRearrangement = true;
            }
            // Else: High priority, the chosen spot is preferred, AND no ideal spots were blocked in any preferred container -> Place directly check below.
        }

        // --- Decision: Place Directly or Let Rearrangement Handle ---
        if (!$triggerRearrangement) {
            // Acceptable direct placement! (Includes non-high-prio items, or high-prio items getting their ideal preferred spot without needing rearrangement)
            $foundX = (float)$bestOverallPlacementData['foundX']; $foundY = (float)$bestOverallPlacementData['foundY']; $foundZ = (float)$bestOverallPlacementData['foundZ'];
            $placedW = (float)$bestOverallPlacementData['placedW']; $placedD = (float)$bestOverallPlacementData['placedD']; $placedH = (float)$bestOverallPlacementData['placedH'];
            $geometricScoreOfBestSpot = $bestOverallPlacementData['score']; // Get score for logging
            error_log("Item $currentItemId: Placing directly in $chosenContainerId at (Y=$foundY, Z=$foundZ, X=$foundX). Adjusted Score: $bestOverallAdjustedScore. Preferred: " . ($isChosenSpotPreferred ? 'Yes' : 'No') . ". Score: " . round($geometricScoreOfBestSpot, 2));


            // Add to DB Updates, API Response, and In-Memory State
            $dbUpdates[$currentItemId] = ['action' => 'place', 'itemId' => $currentItemId, 'containerId' => $chosenContainerId, 'positionX' => $foundX, 'positionY' => $foundY, 'positionZ' => $foundZ, 'placedDimensionW' => $placedW, 'placedDimensionD' => $placedD, 'placedDimensionH' => $placedH ];
            $response['placements'][] = ['itemId' => $currentItemId, 'containerId' => $chosenContainerId, 'position' => formatApiPosition($foundX, $foundY, $foundZ, $placedW, $placedD, $placedH) ];
            if (!isset($currentPlacementState[$chosenContainerId])) { $currentPlacementState[$chosenContainerId] = []; }
            $currentPlacementState[$chosenContainerId][] = [ 'id' => $currentItemId, 'x' => $foundX, 'y' => $foundY, 'z' => $foundZ, 'w' => $placedW, 'd' => $placedD, 'h' => $placedH ];
            $currentPlacementState[$chosenContainerId] = array_values($currentPlacementState[$chosenContainerId]); // Re-index

            $itemPlaced = true;
        }
        // If $triggerRearrangement is true, we skip direct placement here and let the rearrangement block handle it later.
         // *** MODIFICATION END 3 ***

    } else {
        // No direct placement spot found anywhere
        error_log("Item $currentItemId: No direct placement spot found in any container.");
        // Trigger rearrangement if high priority AND has preferences (otherwise rearrangement can't target anything)
         if ($currentItemPriority >= HIGH_PRIORITY_THRESHOLD && ($preferredContainerIdSpecific !== null || $preferredZone !== null)) {
            error_log("Item $currentItemId (High Prio): Triggering rearrangement attempt as no direct spot was found.");
            $triggerRearrangement = true;
        }
    }
    // --- End processing best overall placement ---


    // --- Attempt Rearrangement IF flagged ---
    if ($triggerRearrangement) {
        // This is only triggered if $currentItemPriority >= HIGH_PRIORITY_THRESHOLD
        error_log("Item $currentItemId (High Prio): Initiating rearrangement logic...");

        $rearrangementResult = attemptRearrangementForHighPriorityItem(
            $currentItemId,
            $itemDimensionsApi,
            $currentItemPriority,
            $preferredContainerIdSpecific,
            $preferredZone,
            $containerDimensionsMap,    // Pass all container info
            $currentPlacementState,     // Pass full current state
            $itemsMasterList,           // Pass master list for priorities/dims
            $stepCounter                // Pass current step counter
        );

        if ($rearrangementResult['success']) {
            error_log("Item $currentItemId: Rearrangement SUCCEEDED. Applying changes.");
            $itemPlaced = true;
            $finalPlacement = $rearrangementResult['finalPlacement'];
            $moves = $rearrangementResult['moves'];
            $newState = $rearrangementResult['newState'];
            $stepCounter = $rearrangementResult['nextStep']; // Update step counter

            // 1. Add MOVES to response and DB updates
            foreach ($moves as $move) {
                $rearrangementSteps[] = $move['apiResponse']; // Add to API response steps
                $dbUpdates[$move['itemId']] = $move['dbUpdate'];   // Add/overwrite DB update for the moved item
            }
            // 2. Add FINAL PLACEMENT to response and DB updates
            $rearrangementSteps[] = $finalPlacement['apiResponse']; // Add placement as the last step for this item
            $dbUpdates[$currentItemId] = $finalPlacement['dbUpdate']; // Add/overwrite DB update for the placed item
            $response['placements'][] = $finalPlacement['placementResponse']; // Add to main placements list

            // 3. CRITICAL: Update the master in-memory state for subsequent items
            $currentPlacementState = $newState;
            error_log("Item $currentItemId: In-memory state updated after successful rearrangement.");

        } else {
            error_log("Item $currentItemId: Rearrangement FAILED. Reason: " . $rearrangementResult['reason']);
            // Item remains unplaced, state remains unchanged from before the attempt
        }

    } // End rearrangement attempt block


    // --- Final Check for Placement Failure ---
    if (!$itemPlaced) {
        $failReason = ($currentItemPriority >= HIGH_PRIORITY_THRESHOLD && $triggerRearrangement)
            ? 'No suitable placement space found even after rearrangement attempt.'
            : 'No suitable placement space found.';
        error_log("Item $currentItemId: Placement FAILED. $failReason");
        $internalErrors[] = ['itemId' => $currentItemId, 'reason' => $failReason];
    }

} // End main item loop


// --- Update Database ---
$updatedCount = 0;
$dbUpdateFailed = false;
$actuallyUpdatedIds = [];
if (!empty($dbUpdates)) {
    error_log("DB Update Prep: Processing " . count($dbUpdates) . " placement/move actions.");
    // Note: $dbUpdates should already contain the final intended state for each affected item ID.
    // If an item was moved then placed, the 'place' action would overwrite the 'move' for that ID.

    $updateSql = "UPDATE items SET
                    containerId = :containerId,
                    positionX = :positionX, positionY = :positionY, positionZ = :positionZ,
                    placedDimensionW = :placedW, placedDimensionD = :placedD, placedDimensionH = :placedH,
                    status = 'stowed', lastUpdated = :lastUpdated
                  WHERE itemId = :itemId";
    try {
        $db->beginTransaction();
        $updateStmt = $db->prepare($updateSql);
        $lastUpdated = date(DB_DATETIME_FORMAT);

        // Prepare bound variables
        $bind_containerId = null; $bind_posX = null; $bind_posY = null; $bind_posZ = null;
        $bind_placedW = null; $bind_placedD = null; $bind_placedH = null; $bind_itemId = null;
        $updateStmt->bindParam(':containerId', $bind_containerId);
        $updateStmt->bindParam(':positionX', $bind_posX); $updateStmt->bindParam(':positionY', $bind_posY); $updateStmt->bindParam(':positionZ', $bind_posZ);
        $updateStmt->bindParam(':placedW', $bind_placedW); $updateStmt->bindParam(':placedD', $bind_placedD); $updateStmt->bindParam(':placedH', $bind_placedH);
        $updateStmt->bindParam(':lastUpdated', $lastUpdated);
        $updateStmt->bindParam(':itemId', $bind_itemId, PDO::PARAM_STR);

        foreach ($dbUpdates as $itemId => $updateData) {
            // Ensure all necessary keys exist (belt and braces)
             if (!isset($updateData['action'], $updateData['itemId'], $updateData['containerId'], $updateData['positionX'], $updateData['positionY'], $updateData['positionZ'], $updateData['placedDimensionW'], $updateData['placedDimensionD'], $updateData['placedDimensionH'])) {
                 error_log("DB Update Skip: Incomplete data for item $itemId: " . json_encode($updateData));
                 continue;
             }

            $bind_containerId = $updateData['containerId'];
            $bind_posX = $updateData['positionX']; $bind_posY = $updateData['positionY']; $bind_posZ = $updateData['positionZ'];
            $bind_placedW = $updateData['placedDimensionW']; $bind_placedD = $updateData['placedDimensionD']; $bind_placedH = $updateData['placedDimensionH'];
            $bind_itemId = $updateData['itemId']; // Should match $itemId key

            if ($updateStmt->execute()) {
                $rowCount = $updateStmt->rowCount();
                if ($rowCount > 0) {
                    $updatedCount++; $actuallyUpdatedIds[] = $bind_itemId;
                     error_log("DB Update OK for Item $bind_itemId (Action: {$updateData['action']})");
                } else {
                    // This might happen if the item's state in the DB was already what we tried to set it to. Not necessarily an error.
                    error_log("DB Update WARN: Execute OK but 0 rows affected for item: $bind_itemId. State might have been unchanged.");
                     // Optionally add to internalErrors if this is unexpected
                     //$internalErrors[] = ['itemId' => $bind_itemId, 'reason' => 'DB update executed but affected 0 rows.'];
                }
            } else {
                 $errorInfo = $updateStmt->errorInfo();
                 $errorMsg = "DB Update FAIL for itemId: " . $bind_itemId . " - Error: " . ($errorInfo[2] ?? 'Unknown PDO Error');
                 error_log($errorMsg);
                 throw new PDOException($errorMsg); // Trigger rollback
            }
        }
        $db->commit();
        error_log("DB Update Commit: Transaction committed. Items affected in DB: $updatedCount. IDs: " . implode(', ', $actuallyUpdatedIds));
        if (!$dbUpdateFailed) $response['success'] = true; // Mark success if commit is reached

    } catch (PDOException $e) {
         if ($db->inTransaction()) { $db->rollBack(); error_log("DB Update ROLLED BACK due to error."); }
         http_response_code(500);
         $response['success'] = false; $response['placements'] = []; $response['rearrangements'] = []; // Clear results on DB failure
         $response['message'] = "DB update failed during placement. Transaction rolled back.";
         error_log("Placement DB Error (update execution): " . $e->getMessage());
         $dbUpdateFailed = true;
    }
} else {
     // No DB updates were generated. Check if this is expected.
     if (count($itemsToPlaceInput) === 0) {
         $response['success'] = true; $response['message'] = "No items provided for placement.";
         error_log("No DB updates needed: No items in input.");
     } elseif (!empty($response['placements']) || !empty($rearrangementSteps)) { // Consider rearrangements as success too
          // Items were placed/moved in the simulation, but perhaps their state didn't change from DB load?
          $response['success'] = true;
          $response['message'] = $response['message'] ?? "Placement simulation complete. No database changes were required.";
          error_log("No DB updates generated, but placements/rearrangements exist in response (likely no state change needed or only moves occurred).");
     } else {
         // No placements and no DB updates - means nothing could be placed.
         $response['success'] = false; // Keep success false if items were provided but none placed.
         $response['message'] = $response['message'] ?? "No suitable space found for any provided items.";
          error_log("No DB updates generated: No items were successfully placed or rearranged.");
     }
}
// --- End Database Update ---


// --- Finalize and Echo Response ---
if (!$dbUpdateFailed) {
     // Determine final success based on whether any *requested* items were placed/moved, or if no items were requested.
     $attemptedCount = count($itemsToPlaceInput);
     $placedCount = count($response['placements'] ?? []);
     $finalSuccess = ($attemptedCount === 0 || $placedCount > 0 || !empty($rearrangementSteps)); // Success if no items, or items placed, or items moved

     $response['success'] = $finalSuccess;

     $rearrangedCount = 0; // Count actual 'move' steps
     foreach($rearrangementSteps as $step) { if($step['action'] === 'move') $rearrangedCount++; }

     if ($finalSuccess) {
         if ($attemptedCount > 0 && $placedCount < $attemptedCount && empty($rearrangementSteps)) { // Only show partial if NO rearrangements involved for the failed items
             http_response_code(207); // Multi-Status
             $response['message'] = $response['message'] ?? "Placement partially successful. Placed: $placedCount/" . $attemptedCount . ".";
         } elseif ($attemptedCount > 0 && $placedCount == 0 && !empty($rearrangementSteps)) {
             http_response_code(200); // OK - Rearrangement occurred, but maybe only moves, no final placement of input items
             $response['message'] = $response['message'] ?? "Placement process included rearrangements.";
         }
         else {
              http_response_code(200); // OK
              $response['message'] = $response['message'] ?? ($attemptedCount > 0 ? "Placement successful." : "No items requested for placement.");
         }
          if (!empty($rearrangementSteps)) {
              $response['message'] .= " (" . count($rearrangementSteps) . " rearrangement steps, including $rearrangedCount moves)";
          }
     } else {
          // Ensure appropriate error code if not already set
          if (http_response_code() < 400) { http_response_code(422); } // Unprocessable Entity / Bad Request
          $response['message'] = $response['message'] ?? "Placement failed. No items could be placed or rearranged.";
     }

     if (!empty($internalErrors)) { $response['warnings'] = $internalErrors; }
     // Ensure rearrangements are in the response
     $response['rearrangements'] = $rearrangementSteps;
}

// --- Logging Summary ---
$finalResponseSuccess = $response['success'] ?? false;
$finalHttpMessage = $response['message'] ?? null;
$finalDbUpdatesAttempted = count($dbUpdates);
try { if ($db) {
        $logSql = "INSERT INTO logs (userId, actionType, detailsJson, timestamp) VALUES (:userId, :actionType, :details, :timestamp)"; $logStmt = $db->prepare($logSql);
        $logDetails = [
             'operationType' => 'placement', 'algorithm' => PLACEMENT_ALGORITHM_NAME,
             'requestItemCount' => count($itemsToPlaceInput),
             'responseSuccess' => $finalResponseSuccess, 'httpStatusCode' => http_response_code(),
             'itemsPlacedCount' => count($response['placements'] ?? []),
             'dbUpdatesAttempted' => $finalDbUpdatesAttempted, 'dbUpdatesSuccessful' => $updatedCount,
             'rearrangementStepsCount' => count($response['rearrangements'] ?? []),
             'internalErrorsCount' => count($internalErrors), 'finalMessage' => $finalHttpMessage
         ];
        $logParams = [
             ':userId' => 'System_PlacementAPI_V7RT', ':actionType' => 'placement_v7_rt', // Slightly updated ID/Type
             ':details' => json_encode($logDetails, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
             ':timestamp' => date(DB_DATETIME_FORMAT)
         ];
        if (!$logStmt->execute($logParams)) { error_log("CRITICAL: Failed to execute placement log query!"); }
 } } catch (Exception $logEx) { error_log("CRITICAL: Failed to log placement action! Error: " . $logEx->getMessage()); }


// --- Send Output ---
if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }

// Construct final response object cleanly
$finalResponsePayload = [
    'success' => $finalResponseSuccess,
    'placements' => $response['placements'] ?? [],
    'rearrangements' => $response['rearrangements'] ?? []
];
if ($finalHttpMessage !== null) { $finalResponsePayload['message'] = $finalHttpMessage; }
if (!empty($response['warnings'])) { $finalResponsePayload['warnings'] = $response['warnings']; }

echo json_encode($finalResponsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
error_log(PLACEMENT_ALGORITHM_NAME . " Finished. Success: " . ($finalResponseSuccess ? 'Yes' : 'No') . ". Placed: " . count($finalResponsePayload['placements']) . ". Rearrangement Steps: " . count($finalResponsePayload['rearrangements']) . ". Attempted: " . count($itemsToPlaceInput) . ". DB Updates: $updatedCount/$finalDbUpdatesAttempted. Warnings: " . count($internalErrors) . ". HTTP Code: " . http_response_code());
$db = null; // Close connection implicitly
exit();

?>