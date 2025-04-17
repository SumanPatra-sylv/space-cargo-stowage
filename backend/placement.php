<?php
// --- File: backend/placement.php (V12 - Enhanced Refinement Pass) ---
ini_set('max_execution_time', 380); // Slightly more time for enhanced refinement
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/database.php';

// #########################################################################
// ## START: Constants & Config                                          ##
// #########################################################################

define('DB_DATETIME_FORMAT', 'Y-m-d H:i:s');
define('PLACEMENT_ALGORITHM_NAME', 'SurfaceHeuristic_AggroHP_V12_EnhRefine'); // Updated name

// --- Priority Tiers ---
define('HIGH_PRIORITY_THRESHOLD', 80);
define('LOW_PRIORITY_THRESHOLD', 50);

// --- Scoring Config ---
define('PREFERRED_ZONE_SCORE_PENALTY', 1000000000.0);
define('ACCESSIBILITY_SCORE_WEIGHT_Y', 1000000.0);
define('ACCESSIBILITY_SCORE_WEIGHT_Z', 1000.0);
define('ACCESSIBILITY_SCORE_WEIGHT_X', 1.0);

// --- Other Constants ---
define('FLOAT_EPSILON', 0.001);
define('POSITION_EPSILON', 0.1);

// #########################################################################
// ## START: Core Helper Functions (Unchanged from V11)                   ##
// #########################################################################

// --- generateOrientations(...) - Unchanged ---
function generateOrientations(array $dimensions): array { /* ... V11 code ... */
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
// --- boxesOverlap(...) - Unchanged ---
function boxesOverlap(array $box1, array $box2): bool { /* ... V11 code ... */
    $x1 = (float)($box1['x'] ?? 0); $y1 = (float)($box1['y'] ?? 0); $z1 = (float)($box1['z'] ?? 0);
    $w1 = (float)($box1['w'] ?? 0); $d1 = (float)($box1['d'] ?? 0); $h1 = (float)($box1['h'] ?? 0);
    $x2 = (float)($box2['x'] ?? 0); $y2 = (float)($box2['y'] ?? 0); $z2 = (float)($box2['z'] ?? 0);
    $w2 = (float)($box2['w'] ?? 0); $d2 = (float)($box2['d'] ?? 0); $h2 = (float)($box2['h'] ?? 0);
    $noOverlapX = ($x1 + $w1 <= $x2 + FLOAT_EPSILON) || ($x2 + $w2 <= $x1 + FLOAT_EPSILON);
    $noOverlapY = ($y1 + $d1 <= $y2 + FLOAT_EPSILON) || ($y2 + $d2 <= $y1 + FLOAT_EPSILON);
    $noOverlapZ = ($z1 + $h1 <= $z2 + FLOAT_EPSILON) || ($z2 + $h2 <= $z1 + FLOAT_EPSILON);
    return !($noOverlapX || $noOverlapY || $noOverlapZ);
}
// --- findSpaceForItem(...) - Unchanged ---
function findSpaceForItem(string $itemId, array $itemDimensionsApi, int $itemPriority, string $containerId, array $containerDimensionsApi, array $existingItems): ?array { /* ... V11 code ... */
    $orientations = generateOrientations($itemDimensionsApi);
    if (empty($orientations)) { return null; }
    $bestPlacement = null; $bestScore = null;
    $containerW = (float)($containerDimensionsApi['width'] ?? 0); $containerD = (float)($containerDimensionsApi['depth'] ?? 0); $containerH = (float)($containerDimensionsApi['height'] ?? 0);
    if ($containerW <= 0 || $containerD <= 0 || $containerH <= 0) { return null; }

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

    foreach ($orientations as $orientation) {
        $itemW = (float)$orientation['width']; $itemD = (float)$orientation['depth']; $itemH = (float)$orientation['height'];
        if ($itemW > $containerW + FLOAT_EPSILON || $itemD > $containerD + FLOAT_EPSILON || $itemH > $containerH + FLOAT_EPSILON) continue;

        foreach ($candidatePoints as $point) {
            $x = (float)$point['x']; $y = (float)$point['y']; $z = (float)$point['z'];
            if (($x + $itemW > $containerW + FLOAT_EPSILON) || ($y + $itemD > $containerD + FLOAT_EPSILON) || ($z + $itemH > $containerH + FLOAT_EPSILON)) continue;

            $potentialPlacement = ['x' => $x, 'y' => $y, 'z' => $z, 'w' => $itemW, 'd' => $itemD, 'h' => $itemH];
            $hasCollision = false; foreach ($existingItems as $existingItem) { if (boxesOverlap($potentialPlacement, $existingItem)) { $hasCollision = true; break; } }

            if (!$hasCollision) {
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
// --- findBestRelocationSpot(...) - Unchanged ---
function findBestRelocationSpot(/* ... */): ?array { /* ... V11 code ... */
    $itemToMoveId = func_get_arg(0); $itemDimensions = func_get_arg(1);
    $originalContainerId = func_get_arg(2); $originalPosition = func_get_arg(3);
    $allContainerDimensions = func_get_arg(4); $itemsMasterList = func_get_arg(5);
    $currentPlacementState = func_get_arg(6); $highPriorityItemId = func_get_arg(7);
    $idealSpotToClear = func_get_arg(8);

     $orientations = generateOrientations($itemDimensions);
    if (empty($orientations)) return null;
    $containerSearchOrder = [];
    // Prioritize original container THEN others for relocation
    if (isset($allContainerDimensions[$originalContainerId])) { $containerSearchOrder[$originalContainerId] = $allContainerDimensions[$originalContainerId]; }
    foreach($allContainerDimensions as $cId => $cData) { if ($cId !== $originalContainerId && !isset($containerSearchOrder[$cId])) { $containerSearchOrder[$cId] = $cData; } }


    foreach ($containerSearchOrder as $containerId => $containerDims) {
        $itemsInThisContainer = [];
        // **Important**: Use the potentially modified placement state passed in
        if (isset($currentPlacementState[$containerId])) {
             foreach ($currentPlacementState[$containerId] as $item) {
                 // Exclude the item being moved itself
                 if ($item['id'] !== $itemToMoveId) {
                      $itemsInThisContainer[] = $item;
                 }
             }
         }
        $containerW = (float)$containerDims['width']; $containerD = (float)$containerDims['depth']; $containerH = (float)$containerDims['height'];
        if ($containerW <= 0 || $containerD <= 0 || $containerH <= 0) continue;

        $bestPlacementInContainer = null; $bestScoreInContainer = null;
        $candidatePoints = []; $originKey = sprintf("%.3f_%.3f_%.3f", 0.0, 0.0, 0.0); $candidatePoints[$originKey] = ['x' => 0.0, 'y' => 0.0, 'z' => 0.0];
        foreach ($itemsInThisContainer as $existing) {
             $ex = (float)($existing['x'] ?? 0); $ey = (float)($existing['y'] ?? 0); $ez = (float)($existing['z'] ?? 0);
             $ew = (float)($existing['w'] ?? 0); $ed = (float)($existing['d'] ?? 0); $eh = (float)($existing['h'] ?? 0);
             $pointsToAdd = [ ['x' => $ex, 'y' => $ey, 'z' => $ez + $eh],['x' => $ex + $ew, 'y' => $ey, 'z' => $ez],['x' => $ex, 'y' => $ey + $ed, 'z' => $ez] ];
              foreach ($pointsToAdd as $pt) {
                  if ($pt['x'] < $containerW + FLOAT_EPSILON && $pt['y'] < $containerD + FLOAT_EPSILON && $pt['z'] < $containerH + FLOAT_EPSILON) {
                      $key = sprintf("%.3f_%.3f_%.3f", round($pt['x'], 3), round($pt['y'], 3), round($pt['z'], 3));
                      if (!isset($candidatePoints[$key])) { $candidatePoints[$key] = $pt; }
                  }
              }
        } $candidatePoints = array_values($candidatePoints);

        foreach ($orientations as $orientation) {
            $itemW = (float)$orientation['width']; $itemD = (float)$orientation['depth']; $itemH = (float)$orientation['height'];
            if ($itemW > $containerW + FLOAT_EPSILON || $itemD > $containerD + FLOAT_EPSILON || $itemH > $containerH + FLOAT_EPSILON) continue;

            foreach ($candidatePoints as $point) {
                $x = (float)$point['x']; $y = (float)$point['y']; $z = (float)$point['z'];
                if (($x + $itemW > $containerW + FLOAT_EPSILON) || ($y + $itemD > $containerD + FLOAT_EPSILON) || ($z + $itemH > $containerH + FLOAT_EPSILON)) continue;
                $potentialPlacement = ['id' => $itemToMoveId, 'x' => $x, 'y' => $y, 'z' => $z, 'w' => $itemW, 'd' => $itemD, 'h' => $itemH];
                $hasCollision = false; foreach ($itemsInThisContainer as $existingItem) { if (boxesOverlap($potentialPlacement, $existingItem)) { $hasCollision = true; break; } }
                 if ($hasCollision) continue;

                 // --- Check 1: Is it the original position? ---
                 $isOriginalPos = false;
                 if ($containerId === $originalContainerId && $originalPosition) {
                     if (abs($x - (float)$originalPosition['x']) < POSITION_EPSILON &&
                         abs($y - (float)$originalPosition['y']) < POSITION_EPSILON &&
                         abs($z - (float)$originalPosition['z']) < POSITION_EPSILON) {
                         $isOriginalPos = true;
                     }
                 }
                 if ($isOriginalPos) continue; // Don't relocate to the same spot

                 // --- Check 2: Does it overlap the specific spot we might be trying to clear? ---
                 $overlapsIdealSpot = false;
                 // Only check if the relocation is in the SAME container as the ideal spot AND if idealSpotToClear is valid
                 if ($idealSpotToClear && $containerId === ($idealSpotToClear['containerId'] ?? $originalContainerId) && isset($idealSpotToClear['w']) && $idealSpotToClear['w'] > 0) {
                      if (boxesOverlap($potentialPlacement, $idealSpotToClear)) {
                          $overlapsIdealSpot = true;
                      }
                 }
                 if ($overlapsIdealSpot) {
                      // error_log("findBestRelocationSpot: Skipping spot for $itemToMoveId because it overlaps the ideal spot being cleared."); // Verbose
                      continue; // Don't relocate a blocker into the spot we are trying to clear!
                 }


                 // --- Calculate Score and Update Best ---
                 $currentScore = ($y * ACCESSIBILITY_SCORE_WEIGHT_Y) + ($z * ACCESSIBILITY_SCORE_WEIGHT_Z) + ($x * ACCESSIBILITY_SCORE_WEIGHT_X);
                 // For relocation, we MIGHT prefer spots further back if necessary, but stick with lowest Y first for now.
                 if ($bestScoreInContainer === null || $currentScore < $bestScoreInContainer) {
                     $bestScoreInContainer = $currentScore;
                     $bestPlacementInContainer = ['containerId' => $containerId, 'foundX' => $x, 'foundY' => $y, 'foundZ' => $z, 'placedW' => $itemW, 'placedD' => $itemD, 'placedH' => $itemH ];
                 }
            }
        }
        if ($bestPlacementInContainer !== null) {
            // Found the best spot *in this container*, return it immediately
            error_log("findBestRelocationSpot: Found valid spot for $itemToMoveId in $containerId at (Y={$bestPlacementInContainer['foundY']}, Z={$bestPlacementInContainer['foundZ']}, X={$bestPlacementInContainer['foundX']})");
            return $bestPlacementInContainer;
        }
    } // End loop through containers

    error_log("findBestRelocationSpot: Could not find any valid relocation spot for $itemToMoveId.");
    return null; // No spot found in any container
}
// --- attemptRearrangementForHighPriorityItem(...) - Unchanged ---
function attemptRearrangementForHighPriorityItem(/* ... */): array { /* ... V11 code ... */
    $itemIdToPlace = func_get_arg(0); $itemDimensionsApi = func_get_arg(1); $itemPriority = func_get_arg(2);
    $preferredContainerId = func_get_arg(3); $preferredZone = func_get_arg(4); $allContainerDimensions = func_get_arg(5);
    $currentPlacementState = func_get_arg(6); $itemsMasterList = func_get_arg(7); $stepCounter = func_get_arg(8);
    $targetIdealSpotCoords = func_get_arg(9); // <<< V11: Expecting ideal spot coords to target

    error_log("Rearrange Triggered for $itemIdToPlace (Prio: $itemPriority, PrefCont: ".($preferredContainerId ?? 'None').", PrefZone: ".($preferredZone ?? 'None').") TARGETING IDEAL SPOT");
    $rearrangementMoves = []; $tempPlacementState = $currentPlacementState;
    $targetContainerIds = []; $targetZone = null; $foundInZone = false;

    if ($preferredContainerId !== null && isset($allContainerDimensions[$preferredContainerId])) {
        $targetContainerIds[] = $preferredContainerId; $targetZone = $allContainerDimensions[$preferredContainerId]['zone'] ?? null;
    } elseif ($preferredZone !== null) {
        $targetZone = $preferredZone;
        foreach ($allContainerDimensions as $cId => $cData) { if (($cData['zone'] ?? null) === $targetZone) { $targetContainerIds[] = $cId; $foundInZone = true; } }
    }

    // If a specific ideal spot container was provided, prioritize that.
    if ($targetIdealSpotCoords && isset($targetIdealSpotCoords['containerId'])) {
         $idealContainerId = $targetIdealSpotCoords['containerId'];
         if (isset($allContainerDimensions[$idealContainerId])) {
             // Make sure the ideal container is first in the list to try
             if (($key = array_search($idealContainerId, $targetContainerIds)) !== false) {
                 unset($targetContainerIds[$key]);
             }
             array_unshift($targetContainerIds, $idealContainerId); // Put it at the front
             error_log("Rearrange for $itemIdToPlace: Prioritizing target container $idealContainerId based on ideal spot.");
         } else {
              error_log("Rearrange for $itemIdToPlace: WARN - Ideal spot container $idealContainerId not found in dimensions map.");
         }
    }


    if (empty($targetContainerIds)) { /* ... handle no target ... */ return ['success' => false, 'reason' => 'No valid target for rearrangement', 'moves' => [], 'finalPlacement' => null, 'newState' => $currentPlacementState, 'nextStep' => $stepCounter]; }
     error_log("Rearrange for $itemIdToPlace: Final target containers: " . implode(', ', $targetContainerIds));

    foreach ($targetContainerIds as $targetContainerId) {
        error_log("Rearrange for $itemIdToPlace: Assessing target $targetContainerId");
        if (!isset($allContainerDimensions[$targetContainerId])) continue;
        $targetContainerDims = $allContainerDimensions[$targetContainerId];
        $itemsInTargetContainerOriginal = $tempPlacementState[$targetContainerId] ?? [];

        // Use the provided ideal spot coordinates if available FOR THIS CONTAINER
        $idealCoords = null;
        if ($targetIdealSpotCoords && isset($targetIdealSpotCoords['containerId']) && $targetIdealSpotCoords['containerId'] === $targetContainerId) {
            $idealCoords = $targetIdealSpotCoords; // Use the pre-calculated ideal spot
             error_log("Rearrange [$itemIdToPlace]: Using pre-calculated ideal in $targetContainerId: (Y={$idealCoords['y']}, Z={$idealCoords['z']}, X={$idealCoords['x']})");
        } else {
             // If no specific ideal spot was passed, calculate the best spot in an empty version of *this* container
             $potentialIdealPlacement = findSpaceForItem($itemIdToPlace, $itemDimensionsApi, $itemPriority, $targetContainerId, $targetContainerDims, []);
             if ($potentialIdealPlacement === null) { error_log("Rearrange [$itemIdToPlace]: Doesn't fit in $targetContainerId (even empty)."); continue; }
             $idealCoords = ['x' => $potentialIdealPlacement['foundX'], 'y' => $potentialIdealPlacement['foundY'], 'z' => $potentialIdealPlacement['foundZ'], 'w' => $potentialIdealPlacement['placedW'], 'd' => $potentialIdealPlacement['placedD'], 'h' => $potentialIdealPlacement['placedH'], 'containerId' => $targetContainerId]; // Added containerId
             error_log("Rearrange [$itemIdToPlace]: Calculated ideal in $targetContainerId: (Y={$idealCoords['y']}, Z={$idealCoords['z']}, X={$idealCoords['x']})");
        }

        $blockersToMove = []; $foundNonMovableBlocker = false;
        foreach ($itemsInTargetContainerOriginal as $index => $existingItem) {
            if (boxesOverlap($idealCoords, $existingItem)) {
                $blockerId = $existingItem['id'];
                if (!isset($itemsMasterList[$blockerId])) { $foundNonMovableBlocker = true; error_log("Rearrange [$itemIdToPlace]: Blocker $blockerId MISSING MASTER DATA"); break; }
                $blockerPriority = $itemsMasterList[$blockerId]['priority'] ?? 999;
                $blockerOriginalDimensions = $itemsMasterList[$blockerId]['dimensions_api'] ?? null;
                if (!$blockerOriginalDimensions || ($blockerOriginalDimensions['width'] ?? 0) <= 0) { $foundNonMovableBlocker = true; error_log("Rearrange [$itemIdToPlace]: Blocker $blockerId MISSING DIMS"); break; }

                if ($blockerPriority < $itemPriority) {
                    error_log("Rearrange [$itemIdToPlace]: Found blocker $blockerId (Prio: $blockerPriority) < item prio ($itemPriority). Can move.");
                    $blockersToMove[] = ['id' => $blockerId, 'index' => $index, 'data' => $existingItem, 'originalDims' => $blockerOriginalDimensions];
                } else {
                     error_log("Rearrange [$itemIdToPlace]: Found blocker $blockerId (Prio: $blockerPriority) >= item prio ($itemPriority). CANNOT move.");
                    $foundNonMovableBlocker = true; break;
                }
            }
        }
        if ($foundNonMovableBlocker) { error_log("Rearrange [$itemIdToPlace]: Aborting $targetContainerId due to non-movable blocker for the ideal spot."); continue; }

        $allBlockersRelocatedSuccessfully = true; $currentMovesForThisAttempt = [];
        $tempStateForThisAttempt = $tempPlacementState; // Re-copy state for this attempt

        if (!empty($blockersToMove)) {
            error_log("Rearrange [$itemIdToPlace]: Relocating " . count($blockersToMove) . " blockers in $targetContainerId to clear ideal spot.");
            foreach ($blockersToMove as $blockerInfo) {
                $blockerId = $blockerInfo['id']; $blockerData = $blockerInfo['data']; $blockerOriginalDimensions = $blockerInfo['originalDims'];
                error_log("Rearrange [$itemIdToPlace]: Finding spot for blocker $blockerId...");
                // Pass $idealCoords so relocation doesn't put blocker back in the target spot
                $relocationSpot = findBestRelocationSpot($blockerId, $blockerOriginalDimensions, $targetContainerId, $blockerData, $allContainerDimensions, $itemsMasterList, $tempStateForThisAttempt, $itemIdToPlace, $idealCoords);

                if ($relocationSpot) {
                    $newContId = $relocationSpot['containerId']; $newX = $relocationSpot['foundX']; $newY = $relocationSpot['foundY']; $newZ = $relocationSpot['foundZ']; $newW = $relocationSpot['placedW']; $newD = $relocationSpot['placedD']; $newH = $relocationSpot['placedH'];
                    error_log("Rearrange [$itemIdToPlace]: Relocated blocker $blockerId to $newContId (Y=$newY, Z=$newZ, X=$newX).");
                    $moveData = [ 'step' => $stepCounter + count($currentMovesForThisAttempt), 'action' => 'move','itemId' => $blockerId, 'fromContainer' => $targetContainerId,'fromPosition' => formatApiPosition($blockerData['x'], $blockerData['y'], $blockerData['z'], $blockerData['w'], $blockerData['d'], $blockerData['h']), 'toContainer' => $newContId,'toPosition' => formatApiPosition($newX, $newY, $newZ, $newW, $newD, $newH) ];
                    $dbUpdateData = ['action' => 'move', 'itemId' => $blockerId, 'containerId' => $newContId, 'positionX' => $newX, 'positionY' => $newY, 'positionZ' => $newZ, 'placedDimensionW' => $newW, 'placedDimensionD' => $newD, 'placedDimensionH' => $newH ];
                    $currentMovesForThisAttempt[] = ['apiResponse' => $moveData, 'itemId' => $blockerId, 'dbUpdate' => $dbUpdateData];

                    // --- Update temp state: Remove blocker from old container/position ---
                     $found = false;
                     if (isset($tempStateForThisAttempt[$targetContainerId])) {
                         foreach ($tempStateForThisAttempt[$targetContainerId] as $idx => $item) {
                             if ($item['id'] === $blockerId) {
                                 unset($tempStateForThisAttempt[$targetContainerId][$idx]);
                                 $tempStateForThisAttempt[$targetContainerId] = array_values($tempStateForThisAttempt[$targetContainerId]); // Re-index
                                 $found = true;
                                 break;
                             }
                         }
                     }
                     if (!$found) { error_log("Rearrange WARN [$itemIdToPlace]: Blocker $blockerId not found in old container $targetContainerId state for removal."); }

                     // --- Update temp state: Add blocker to new container/position ---
                     if (!isset($tempStateForThisAttempt[$newContId])) {
                         $tempStateForThisAttempt[$newContId] = [];
                     }
                     $tempStateForThisAttempt[$newContId][] = ['id' => $blockerId, 'x' => $newX, 'y' => $newY, 'z' => $newZ, 'w' => $newW, 'd' => $newD, 'h' => $newH];

                } else {
                    error_log("Rearrange FAIL [$itemIdToPlace]: Could not relocate blocker $blockerId. Aborting $targetContainerId.");
                    $allBlockersRelocatedSuccessfully = false; $currentMovesForThisAttempt = []; break; // Stop trying for this container
                }
            }
        } // End if blockers need moving

        if (!$allBlockersRelocatedSuccessfully) { error_log("Rearrange [$itemIdToPlace]: Skipping $targetContainerId due to blocker move failure."); continue; }

        // Final check: Can the HP item *now* fit into the ideal spot using the updated temp state?
        $finalPlacementCheck = findSpaceForItem($itemIdToPlace, $itemDimensionsApi, $itemPriority, $targetContainerId, $targetContainerDims, $tempStateForThisAttempt[$targetContainerId] ?? []);
        $isIdealSpotFound = false;
        if ($finalPlacementCheck !== null &&
            abs($finalPlacementCheck['foundX'] - $idealCoords['x']) < POSITION_EPSILON &&
            abs($finalPlacementCheck['foundY'] - $idealCoords['y']) < POSITION_EPSILON &&
            abs($finalPlacementCheck['foundZ'] - $idealCoords['z']) < POSITION_EPSILON &&
            // Also check dimensions match the ones calculated for the ideal spot
            abs($finalPlacementCheck['placedW'] - $idealCoords['w']) < FLOAT_EPSILON &&
            abs($finalPlacementCheck['placedD'] - $idealCoords['d']) < FLOAT_EPSILON &&
            abs($finalPlacementCheck['placedH'] - $idealCoords['h']) < FLOAT_EPSILON )
            {
                 $isIdealSpotFound = true;
            }


        if ($isIdealSpotFound) {
             error_log("Rearrange SUCCESS [$itemIdToPlace]: Final placement verified in IDEAL spot in $targetContainerId after moves.");
            $finalX = $finalPlacementCheck['foundX']; $finalY = $finalPlacementCheck['foundY']; $finalZ = $finalPlacementCheck['foundZ']; $finalW = $finalPlacementCheck['placedW']; $finalD = $finalPlacementCheck['placedD']; $finalH = $finalPlacementCheck['placedH'];
            $committedMoves = []; $currentStep = $stepCounter;
            foreach ($currentMovesForThisAttempt as $move) { $move['apiResponse']['step'] = $currentStep++; $committedMoves[] = $move; }
            $finalPlacementData = [
                 'apiResponse' => ['step' => $currentStep++, 'action' => 'place', 'itemId' => $itemIdToPlace, 'fromContainer' => null, 'fromPosition' => null, 'toContainer' => $targetContainerId, 'toPosition' => formatApiPosition($finalX, $finalY, $finalZ, $finalW, $finalD, $finalH)],
                 'placementResponse' => ['itemId' => $itemIdToPlace, 'containerId' => $targetContainerId, 'position' => formatApiPosition($finalX, $finalY, $finalZ, $finalW, $finalD, $finalH)],
                 'dbUpdate' => ['action' => 'place', 'itemId' => $itemIdToPlace, 'containerId' => $targetContainerId, 'positionX' => $finalX, 'positionY' => $finalY, 'positionZ' => $finalZ, 'placedDimensionW' => $finalW, 'placedDimensionD' => $finalD, 'placedDimensionH' => $finalH ]
             ];
             // Update the temp state with the final placement of the item being placed
             if (!isset($tempStateForThisAttempt[$targetContainerId])) $tempStateForThisAttempt[$targetContainerId] = [];
             $tempStateForThisAttempt[$targetContainerId][] = ['id' => $itemIdToPlace, 'x' => $finalX, 'y' => $finalY, 'z' => $finalZ, 'w' => $finalW, 'd' => $finalD, 'h' => $finalH];
             $tempStateForThisAttempt[$targetContainerId] = array_values($tempStateForThisAttempt[$targetContainerId]); // Re-index

             return ['success' => true,'reason' => 'Rearrangement successful, placed in ideal spot in container ' . $targetContainerId,'moves' => $committedMoves,'finalPlacement' => $finalPlacementData,'newState' => $tempStateForThisAttempt,'nextStep' => $currentStep ];
        } else {
            error_log("Rearrange FAIL [$itemIdToPlace]: FINAL CHECK FAILED in $targetContainerId. Could not place in ideal spot even after moving blockers. Spot found: " . json_encode($finalPlacementCheck));
            // IMPORTANT: Do NOT continue to the next container immediately. The calling function (`placeSingleItem`)
            // needs to know this specific attempt failed so it can try the fallback (best available spot).
            // Return failure state FOR THIS CONTAINER ATTEMPT.
            // Rollback the temporary state changes made during THIS attempt is implicitly done as tempStateForThisAttempt is local.
            return ['success' => false, 'reason' => "Could not place in ideal spot in $targetContainerId after attempting blocker moves.", 'moves' => [], 'finalPlacement' => null, 'newState' => $currentPlacementState, 'nextStep' => $stepCounter]; // Return failure state, use original state
        }
    } // End loop through target containers

    error_log("Rearrange FAIL [$itemIdToPlace]: Exhausted all target containers for ideal spot rearrangement.");
    return ['success' => false, 'reason' => 'Could not find/clear suitable ideal spot in any target container.', 'moves' => [], 'finalPlacement' => null, 'newState' => $currentPlacementState, 'nextStep' => $stepCounter]; // Return failure state, use original state

}
// --- formatApiPosition(...) - Unchanged ---
function formatApiPosition(float $x, float $y, float $z, float $w, float $d, float $h): array { /* ... V11 code ... */
    return [
        'startCoordinates' => ['width' => round($x, 3), 'depth' => round($y, 3), 'height' => round($z, 3)],
        'endCoordinates' => ['width' => round($x + $w, 3), 'depth' => round($y + $d, 3), 'height' => round($z + $h, 3)]
    ];
}
// --- findFittingOrientation(...) - Unchanged ---
function findFittingOrientation(array $itemApiDims, array $targetBox): ?array { /* ... V11 code ... */
    $targetW = (float)($targetBox['w'] ?? 0);
    $targetD = (float)($targetBox['d'] ?? 0);
    $targetH = (float)($targetBox['h'] ?? 0);

    // Check if target dimensions are valid
    if ($targetW <= FLOAT_EPSILON || $targetD <= FLOAT_EPSILON || $targetH <= FLOAT_EPSILON) {
        return null; // Cannot fit into a zero-dimension box
    }

    $orientations = generateOrientations($itemApiDims);
    if (empty($orientations)) return null; // Item itself has invalid dimensions

    foreach ($orientations as $o) {
        // Compare dimensions using epsilon for float safety
        if (abs($o['width'] - $targetW) < FLOAT_EPSILON &&
            abs($o['depth'] - $targetD) < FLOAT_EPSILON &&
            abs($o['height'] - $targetH) < FLOAT_EPSILON) {
            // Found an orientation that matches the target box dimensions
            return ['w' => $o['width'], 'd' => $o['depth'], 'h' => $o['height']];
        }
    }

    // No orientation fit exactly
    return null;
}
// --- placeSingleItem(...) - Unchanged ---
function placeSingleItem( array $itemToPlaceData, array $itemsMasterList, array $containerDimensionsMap, array &$currentPlacementState, int &$stepCounter, bool $enableRearrangement ): array { /* ... V11 code ... */
    $currentItemId = $itemToPlaceData['itemId'] ?? null;
    if ($currentItemId === null || !isset($itemsMasterList[$currentItemId])) {
        return ['success' => false, 'reason' => 'Invalid item data.', 'placement' => null, 'rearrangementResult' => null, 'dbUpdate' => null];
    }

    $itemMasterData = $itemsMasterList[$currentItemId];
    $itemDimensionsApi = $itemMasterData['dimensions_api'] ?? null;
    $currentItemPriority = $itemMasterData['priority'];
    $preferredContainerIdSpecific = $itemMasterData['preferredContainerId'];
    $preferredZone = $itemMasterData['preferredZone'];
    if ($preferredContainerIdSpecific === '') $preferredContainerIdSpecific = null;
    if ($preferredZone === '') $preferredZone = null;

    if (!$itemDimensionsApi || ($itemDimensionsApi['width'] ?? 0) <= 0) {
        return ['success' => false, 'reason' => "Invalid dimensions for $currentItemId.", 'placement' => null, 'rearrangementResult' => null, 'dbUpdate' => null];
    }

    $tier = ($currentItemPriority >= HIGH_PRIORITY_THRESHOLD) ? 'High' : (($currentItemPriority <= LOW_PRIORITY_THRESHOLD) ? 'Low' : 'Medium');
    error_log("placeSingleItem (V11) Processing Item $currentItemId (Priority: $currentItemPriority, Tier: $tier, PrefZone: " . ($preferredZone ?? 'None') . ", PrefCont: " . ($preferredContainerIdSpecific ?? 'None') . ")");

    // --- Determine Preferred Container Search Order (Prioritize specific, then zone) ---
    $preferredContainerSearchOrder = [];
    $processedIdsForPrefSearch = [];
    if ($preferredContainerIdSpecific !== null && isset($containerDimensionsMap[$preferredContainerIdSpecific])) {
        $preferredContainerSearchOrder[] = $preferredContainerIdSpecific;
        $processedIdsForPrefSearch[$preferredContainerIdSpecific] = true;
    }
    if ($preferredZone !== null) {
        foreach ($containerDimensionsMap as $cId => $cData) {
            if (!isset($processedIdsForPrefSearch[$cId]) && ($cData['zone'] ?? null) === $preferredZone) {
                $preferredContainerSearchOrder[] = $cId;
                $processedIdsForPrefSearch[$cId] = true; // Mark as processed for zone preference
            }
        }
    } elseif ($preferredContainerIdSpecific !== null && isset($containerDimensionsMap[$preferredContainerIdSpecific]['zone'])) {
         // If only specific container was given, still consider others in its zone as preferred
         $specificContainerZone = $containerDimensionsMap[$preferredContainerIdSpecific]['zone'];
         if ($specificContainerZone !== null) {
             foreach ($containerDimensionsMap as $cId => $cData) {
                if (!isset($processedIdsForPrefSearch[$cId]) && ($cData['zone'] ?? null) === $specificContainerZone) {
                    $preferredContainerSearchOrder[] = $cId;
                    $processedIdsForPrefSearch[$cId] = true;
                }
             }
         }
    }

    // --- V11: High Priority Aggressive Placement Attempt ---
    if ($tier === 'High' && $enableRearrangement && !empty($preferredContainerSearchOrder)) {
        error_log("Item $currentItemId (High Prio): Attempting IDEAL spot placement in preferred containers: " . implode(', ', $preferredContainerSearchOrder));

        $idealSpotToTarget = null; // Holds details of the best absolute ideal spot found
        $idealSpotContainerId = null;

        // 1. Find the absolute best geometric spot in preferred containers (assuming empty)
        foreach ($preferredContainerSearchOrder as $prefContainerId) {
            if (!isset($containerDimensionsMap[$prefContainerId])) continue;
            $containerDimensionsApi = $containerDimensionsMap[$prefContainerId];
            $potentialIdealSpot = findSpaceForItem($currentItemId, $itemDimensionsApi, $currentItemPriority, $prefContainerId, $containerDimensionsApi, []); // Pass empty array

            if ($potentialIdealSpot !== null) {
                // If this is the first ideal spot, or better than the previous best ideal, store it
                 if ($idealSpotToTarget === null || $potentialIdealSpot['score'] < $idealSpotToTarget['score']) {
                     $idealSpotToTarget = $potentialIdealSpot; // Contains x,y,z,w,d,h,score
                     $idealSpotContainerId = $prefContainerId;
                     error_log("Item $currentItemId: Found potential ideal spot in $prefContainerId (Score: {$potentialIdealSpot['score']}).");
                 }
            }
        }

        if ($idealSpotToTarget === null) {
            error_log("Item $currentItemId (High Prio): Cannot fit into ANY preferred container, even if empty. Proceeding to standard search.");
        } else {
             error_log("Item $currentItemId (High Prio): Best ideal spot found in $idealSpotContainerId at (Y={$idealSpotToTarget['foundY']}, Z={$idealSpotToTarget['foundZ']}, X={$idealSpotToTarget['foundX']}). Checking blockers...");
             $idealCoords = [
                 'x' => $idealSpotToTarget['foundX'], 'y' => $idealSpotToTarget['foundY'], 'z' => $idealSpotToTarget['foundZ'],
                 'w' => $idealSpotToTarget['placedW'], 'd' => $idealSpotToTarget['placedD'], 'h' => $idealSpotToTarget['placedH'],
                 'containerId' => $idealSpotContainerId // V11: Add containerId here for rearrangement function
             ];

            // 2. Check if the ideal spot is currently blocked in the target container
            $itemsInIdealContainer = $currentPlacementState[$idealSpotContainerId] ?? [];
            $blockers = [];
            $nonMovableBlockerFound = false;
            foreach ($itemsInIdealContainer as $existingItem) {
                if (boxesOverlap($idealCoords, $existingItem)) {
                    $blockerId = $existingItem['id'];
                    $blockerDetails = $itemsMasterList[$blockerId] ?? null;
                    if (!$blockerDetails) {
                        error_log("Item $currentItemId: Ideal spot blocked by $blockerId - MISSING MASTER DATA! Cannot rearrange.");
                        $nonMovableBlockerFound = true; break;
                    }
                    $blockerPriority = $blockerDetails['priority'];
                    if ($blockerPriority >= $currentItemPriority) {
                        error_log("Item $currentItemId: Ideal spot blocked by $blockerId (Prio: $blockerPriority) - EQUAL/HIGHER PRIORITY! Cannot rearrange.");
                        $nonMovableBlockerFound = true; break;
                    }
                    $blockers[] = $blockerId; // Store ID of movable blocker
                }
            }

            // 3. Decide action based on blockers
            if ($nonMovableBlockerFound) {
                 error_log("Item $currentItemId (High Prio): Ideal spot blocked by non-movable item. Proceeding to standard search.");
            } elseif (empty($blockers)) {
                 // --- Ideal Spot is FREE! Place directly ---
                 error_log("Item $currentItemId (High Prio): Ideal spot is currently free! Placing directly.");
                 $finalX = $idealCoords['x']; $finalY = $idealCoords['y']; $finalZ = $idealCoords['z'];
                 $finalW = $idealCoords['w']; $finalD = $idealCoords['d']; $finalH = $idealCoords['h'];

                 if (!isset($currentPlacementState[$idealSpotContainerId])) { $currentPlacementState[$idealSpotContainerId] = []; }
                 $currentPlacementState[$idealSpotContainerId][] = ['id' => $currentItemId, 'x' => $finalX, 'y' => $finalY, 'z' => $finalZ, 'w' => $finalW, 'd' => $finalD, 'h' => $finalH];
                 $currentPlacementState[$idealSpotContainerId] = array_values($currentPlacementState[$idealSpotContainerId]);

                 return [
                     'success' => true, 'reason' => 'Placed directly in ideal spot.',
                     'placement' => ['itemId' => $currentItemId, 'containerId' => $idealSpotContainerId, 'position' => formatApiPosition($finalX, $finalY, $finalZ, $finalW, $finalD, $finalH)],
                     'rearrangementResult' => null,
                     'dbUpdate' => ['action' => 'place', 'itemId' => $currentItemId, 'containerId' => $idealSpotContainerId, 'positionX' => $finalX, 'positionY' => $finalY, 'positionZ' => $finalZ, 'placedDimensionW' => $finalW, 'placedDimensionD' => $finalD, 'placedDimensionH' => $finalH]
                 ];
                 // --- Placement Successful - Exit Function ---

            } else {
                 // --- Ideal Spot is Blocked by Lower Prio - Attempt Rearrangement ---
                 error_log("Item $currentItemId (High Prio): Ideal spot blocked by lower priority items (" . implode(', ', $blockers) . "). Attempting rearrangement targeting this spot...");
                 $rearrangementResult = attemptRearrangementForHighPriorityItem(
                     $currentItemId, $itemDimensionsApi, $currentItemPriority,
                     $preferredContainerIdSpecific, $preferredZone, // Pass original preferences
                     $containerDimensionsMap, $currentPlacementState, $itemsMasterList,
                     $stepCounter, $idealCoords // <<< V11: Pass the specific ideal spot to clear
                 );

                 if ($rearrangementResult['success']) {
                     error_log("Item $currentItemId (High Prio): Rearrangement SUCCESSFUL, placed in ideal spot.");
                     $currentPlacementState = $rearrangementResult['newState']; // Update state from rearrangement result
                     $stepCounter = $rearrangementResult['nextStep']; // Update step counter

                     // Prepare response structure similar to how it was done in V10 rearrange success
                      return [
                         'success' => true,
                         'reason' => $rearrangementResult['reason'] ?? 'Placed via rearrangement into ideal spot.',
                         'placement' => $rearrangementResult['finalPlacement']['placementResponse'],
                         'rearrangementResult' => $rearrangementResult, // Include full rearrange details
                         'dbUpdate' => null // DB updates handled within main loop based on rearrange result moves/place
                     ];
                     // --- Placement Successful - Exit Function ---

                 } else {
                      error_log("Item $currentItemId (High Prio): Rearrangement FAILED to clear ideal spot. Reason: " . ($rearrangementResult['reason'] ?? 'Unknown') . ". Proceeding to find best *available* spot (fallback).");
                     // --- Let execution continue to the fallback logic below ---
                 }
            }
        }
    } // End of High Priority Aggressive Placement logic

    // --- V11: Fallback Logic or Standard Placement for Medium/Low Prio ---
    // ... (Rest of placeSingleItem - Fallback / Standard Search - is unchanged from V11) ...
     error_log("Item $currentItemId ($tier Prio): Entering standard/fallback placement search (best available spot).");

    // --- Determine FULL Container Search Order (Preferred first, then others) ---
    $allContainersToTryIds = [];
    $processedIdsForAllSearch = [];
    // Add preferred ones first (respecting original preference logic)
    if ($preferredContainerIdSpecific !== null && isset($containerDimensionsMap[$preferredContainerIdSpecific])) { $allContainersToTryIds[] = $preferredContainerIdSpecific; $processedIdsForAllSearch[$preferredContainerIdSpecific] = true; }
    if ($preferredZone !== null) { foreach ($containerDimensionsMap as $cId => $cData) { if (!isset($processedIdsForAllSearch[$cId]) && ($cData['zone'] ?? null) === $preferredZone) { $allContainersToTryIds[] = $cId; $processedIdsForAllSearch[$cId] = true; } } }
    elseif ($preferredContainerIdSpecific !== null && isset($containerDimensionsMap[$preferredContainerIdSpecific]['zone'])) { $specificContainerZone = $containerDimensionsMap[$preferredContainerIdSpecific]['zone']; if ($specificContainerZone !== null) { foreach ($containerDimensionsMap as $cId => $cData) { if (!isset($processedIdsForAllSearch[$cId]) && ($cData['zone'] ?? null) === $specificContainerZone) { $allContainersToTryIds[] = $cId; $processedIdsForAllSearch[$cId] = true; } } } }
    // Add all remaining containers
    foreach ($containerDimensionsMap as $cId => $cData) { if (!isset($processedIdsForAllSearch[$cId])) { $allContainersToTryIds[] = $cId; } }


    // --- Find Best *Available* Spot Across ALL Containers ---
    $bestAvailablePlacementData = null;
    $bestAvailableAdjustedScore = null;
    $bestAvailableContainerId = null;

    foreach ($allContainersToTryIds as $containerId) {
        if (!isset($containerDimensionsMap[$containerId])) continue;
        $containerDimensionsApi = $containerDimensionsMap[$containerId];
        $itemsCurrentlyInContainer = $currentPlacementState[$containerId] ?? [];
        $containerZone = $containerDimensionsMap[$containerId]['zone'] ?? null;

        // Find the best spot considering items already there
        $placementInThisContainer = findSpaceForItem(
            $currentItemId, $itemDimensionsApi, $currentItemPriority,
            $containerId, $containerDimensionsApi, $itemsCurrentlyInContainer
        );

        if ($placementInThisContainer !== null) {
             $geometricScore = $placementInThisContainer['score'];
             $adjustedScore = $geometricScore;

             // Apply penalty if the container is not in a preferred zone/container
             $isCurrentContainerPreferred = false;
             if ($preferredContainerIdSpecific !== null) {
                 if ($preferredContainerIdSpecific === $containerId) $isCurrentContainerPreferred = true;
             } elseif ($preferredZone !== null) {
                 if ($containerZone === $preferredZone) $isCurrentContainerPreferred = true;
             } else {
                 $isCurrentContainerPreferred = true; // No preference specified, so any container is "preferred"
             }

             if (!$isCurrentContainerPreferred) {
                 $adjustedScore += PREFERRED_ZONE_SCORE_PENALTY;
             }

             // Check if this is the best overall available spot found so far
             if ($bestAvailableAdjustedScore === null || $adjustedScore < $bestAvailableAdjustedScore) {
                 $bestAvailableAdjustedScore = $adjustedScore;
                 $bestAvailablePlacementData = $placementInThisContainer;
                 $bestAvailableContainerId = $containerId;
                 // error_log("Item $currentItemId: Found new best available spot in $containerId (Adj Score: $adjustedScore)"); // Verbose
             }
        }
    }

    // --- Process Best Found Available Spot ---
    if ($bestAvailablePlacementData !== null) {
        $chosenContainerId = $bestAvailableContainerId;
        $foundX = (float)$bestAvailablePlacementData['foundX']; $foundY = (float)$bestAvailablePlacementData['foundY']; $foundZ = (float)$bestAvailablePlacementData['foundZ'];
        $placedW = (float)$bestAvailablePlacementData['placedW']; $placedD = (float)$bestAvailablePlacementData['placedD']; $placedH = (float)$bestAvailablePlacementData['placedH'];

        $reason = ($tier === 'High') ? 'Placed in best available spot (fallback after ideal attempt).' : 'Placed directly in best available spot.';
        error_log("Item $currentItemId: $reason Placing in $chosenContainerId at (Y=$foundY, Z=$foundZ, X=$foundX).");

        // Update state
        if (!isset($currentPlacementState[$chosenContainerId])) { $currentPlacementState[$chosenContainerId] = []; }
        $currentPlacementState[$chosenContainerId][] = ['id' => $currentItemId, 'x' => $foundX, 'y' => $foundY, 'z' => $foundZ, 'w' => $placedW, 'd' => $placedD, 'h' => $placedH];
        $currentPlacementState[$chosenContainerId] = array_values($currentPlacementState[$chosenContainerId]);

        // Return success
        return [
            'success' => true,
            'reason' => $reason,
            'placement' => ['itemId' => $currentItemId, 'containerId' => $chosenContainerId, 'position' => formatApiPosition($foundX, $foundY, $foundZ, $placedW, $placedD, $placedH)],
            'rearrangementResult' => null, // No rearrangement happened in this path
            'dbUpdate' => ['action' => 'place', 'itemId' => $currentItemId, 'containerId' => $chosenContainerId, 'positionX' => $foundX, 'positionY' => $foundY, 'positionZ' => $foundZ, 'placedDimensionW' => $placedW, 'placedDimensionD' => $placedD, 'placedDimensionH' => $placedH]
        ];
    } else {
         // --- No Spot Found Anywhere ---
         $failReason = ($tier === 'High') ? 'No ideal spot, rearrangement failed, and no fallback spot found.' : 'No available placement space found.';
         error_log("Item $currentItemId: Placement FAILED. Reason: $failReason");
         return [
             'success' => false,
             'reason' => $failReason,
             'placement' => null,
             'rearrangementResult' => null, // Include potential failed rearrange result if HP
             'dbUpdate' => null
         ];
    }
}

// #########################################################################
// ## START: Modified Refinement Swap Pass Function (V12)                ##
// #########################################################################

/**
 * Post-processing pass to identify and perform swaps (direct or complex)
 * between higher-priority items placed behind lower-priority items.
 *
 * @param array &$placementState      Current placement state (passed by reference, will be modified).
 * @param array $itemsMasterList      Master list with item details (priority, dimensions).
 * @param array $containerDimensionsMap Map of container dimensions. <<< NEW PARAMETER V12
 * @param int   &$stepCounter         Current step counter (passed by reference, will be incremented).
 * @return array Structure containing:
 *               'swapApiMoves' => array (API move instructions for swaps performed)
 *               'swapDbUpdates' => array (DB update instructions for swapped items [itemId => dbUpdate])
 */
function refinePlacementsBySwapping(
    array &$placementState, // Modifies state directly
    array $itemsMasterList,
    array $containerDimensionsMap, // <<< NEW V12
    int &$stepCounter       // Modifies step counter
): array {
    error_log("--- Starting Enhanced Refinement Swap Pass (V12) ---");
    $swapApiMoves = [];
    $swapDbUpdates = [];
    $swappedItemIdsThisPass = []; // Prevent swapping same item back and forth within the pass

    // Iterate through each container in the current placement state
    // Clone container IDs to avoid issues if state is modified (items moving containers)
    $containerIds = array_keys($placementState);

    foreach ($containerIds as $containerId) {
        // Check if container still exists in state (might have been emptied by moves)
        if (!isset($placementState[$containerId])) continue;

        $itemsInContainer = $placementState[$containerId]; // Get current items
        $itemCount = count($itemsInContainer);
        if ($itemCount < 2) continue; // Need at least two items to potentially swap

        error_log("Refine Pass V12: Checking container $containerId with $itemCount items.");

        // Use nested loops to compare all unique pairs (indices i, j where j > i)
        for ($i = 0; $i < $itemCount; $i++) {
            // Re-fetch item A data in case state was modified by inner loop swap
            if (!isset($placementState[$containerId][$i])) continue; // Item might have moved out
            $itemA_pos = $placementState[$containerId][$i];
            $itemA_id = $itemA_pos['id'];

             // Skip if item A already swapped
             if (isset($swappedItemIdsThisPass[$itemA_id])) continue;

            for ($j = $i + 1; $j < $itemCount; $j++) {
                // Re-fetch item B data
                if (!isset($placementState[$containerId][$j])) continue; // Item might have moved out
                $itemB_pos = $placementState[$containerId][$j];
                $itemB_id = $itemB_pos['id'];

                // Skip if B already swapped or if A == B (shouldn't happen with j=i+1)
                if (isset($swappedItemIdsThisPass[$itemB_id]) || $itemA_id === $itemB_id) continue;

                // Get priorities and original dimensions from master list
                $itemA_details = $itemsMasterList[$itemA_id] ?? null;
                $itemB_details = $itemsMasterList[$itemB_id] ?? null;
                if (!$itemA_details || !$itemB_details || !$itemA_details['dimensions_api'] || !$itemB_details['dimensions_api']) {
                    error_log("Refine Pass V12 WARN: Missing details or dimensions for pair ($itemA_id, $itemB_id). Skipping.");
                    continue; // Skip if data is missing
                }
                $prioA = $itemA_details['priority'];
                $prioB = $itemB_details['priority'];

                // Determine High Priority (HP) and Low Priority (LP) item for this pair
                $hpItem_id = null; $lpItem_id = null;
                $hpItem_pos = null; $lpItem_pos = null;
                $hpItem_details = null; $lpItem_details = null;

                if ($prioA > $prioB) {
                    $hpItem_id = $itemA_id; $lpItem_id = $itemB_id;
                    $hpItem_pos = $itemA_pos; $lpItem_pos = $itemB_pos;
                    $hpItem_details = $itemA_details; $lpItem_details = $itemB_details;
                } elseif ($prioB > $prioA) {
                    $hpItem_id = $itemB_id; $lpItem_id = $itemA_id;
                    $hpItem_pos = $itemB_pos; $lpItem_pos = $itemA_pos;
                    $hpItem_details = $itemB_details; $lpItem_details = $itemA_details;
                } else {
                    continue; // Skip items with same priority
                }

                // --- Check Trigger Condition: LP is in front of HP and potentially blocks ---
                if ($lpItem_pos['y'] >= $hpItem_pos['y'] - FLOAT_EPSILON) continue; // LP not in front

                $xOverlap = ($lpItem_pos['x'] < $hpItem_pos['x'] + $hpItem_pos['w'] - FLOAT_EPSILON) && ($lpItem_pos['x'] + $lpItem_pos['w'] > $hpItem_pos['x'] + FLOAT_EPSILON);
                $zOverlap = ($lpItem_pos['z'] < $hpItem_pos['z'] + $hpItem_pos['h'] - FLOAT_EPSILON) && ($lpItem_pos['z'] + $lpItem_pos['h'] > $hpItem_pos['z'] + FLOAT_EPSILON);

                if (!$xOverlap || !$zOverlap) continue; // LP not directly blocking XZ

                // --- Candidate Found: Check if direct swap is possible ---
                error_log("Refine Pass V12: Candidate found in $containerId: HP=$hpItem_id (Y={$hpItem_pos['y']}) blocked by LP=$lpItem_id (Y={$lpItem_pos['y']}). Checking swap feasibility...");

                $fittingOrientationHP = findFittingOrientation($hpItem_details['dimensions_api'], $lpItem_pos);
                $fittingOrientationLP = findFittingOrientation($lpItem_details['dimensions_api'], $hpItem_pos);

                if ($fittingOrientationHP !== null && $fittingOrientationLP !== null) {
                    // --- Direct Swap is Possible! (Same as V10/V11) ---
                    error_log("Refine Pass V12: DIRECT SWAP POSSIBLE between $hpItem_id and $lpItem_id.");
                    // ... (Generate API moves, DB updates, update state, mark swapped) ...
                    // --- Generate API Move Instructions ---
                    $moveHP_api = [
                        'step' => $stepCounter++, 'action' => 'move', 'itemId' => $hpItem_id,
                        'fromContainer' => $containerId, 'fromPosition' => formatApiPosition($hpItem_pos['x'], $hpItem_pos['y'], $hpItem_pos['z'], $hpItem_pos['w'], $hpItem_pos['d'], $hpItem_pos['h']),
                        'toContainer' => $containerId, 'toPosition' => formatApiPosition($lpItem_pos['x'], $lpItem_pos['y'], $lpItem_pos['z'], $fittingOrientationHP['w'], $fittingOrientationHP['d'], $fittingOrientationHP['h'])
                    ];
                    $moveLP_api = [
                        'step' => $stepCounter++, 'action' => 'move', 'itemId' => $lpItem_id,
                        'fromContainer' => $containerId, 'fromPosition' => formatApiPosition($lpItem_pos['x'], $lpItem_pos['y'], $lpItem_pos['z'], $lpItem_pos['w'], $lpItem_pos['d'], $lpItem_pos['h']),
                        'toContainer' => $containerId, 'toPosition' => formatApiPosition($hpItem_pos['x'], $hpItem_pos['y'], $hpItem_pos['z'], $fittingOrientationLP['w'], $fittingOrientationLP['d'], $fittingOrientationLP['h'])
                    ];
                    $swapApiMoves[] = $moveHP_api;
                    $swapApiMoves[] = $moveLP_api;

                    // --- Prepare DB Update Instructions ---
                    $dbUpdateHP = [
                        'action' => 'move', 'itemId' => $hpItem_id, 'containerId' => $containerId,
                        'positionX' => $lpItem_pos['x'], 'positionY' => $lpItem_pos['y'], 'positionZ' => $lpItem_pos['z'],
                        'placedDimensionW' => $fittingOrientationHP['w'], 'placedDimensionD' => $fittingOrientationHP['d'], 'placedDimensionH' => $fittingOrientationHP['h']
                    ];
                    $dbUpdateLP = [
                        'action' => 'move', 'itemId' => $lpItem_id, 'containerId' => $containerId,
                        'positionX' => $hpItem_pos['x'], 'positionY' => $hpItem_pos['y'], 'positionZ' => $hpItem_pos['z'],
                        'placedDimensionW' => $fittingOrientationLP['w'], 'placedDimensionD' => $fittingOrientationLP['d'], 'placedDimensionH' => $fittingOrientationLP['h']
                    ];
                    $swapDbUpdates[$hpItem_id] = $dbUpdateHP;
                    $swapDbUpdates[$lpItem_id] = $dbUpdateLP;

                     // --- Update Placement State ---
                     // Find indices again in potentially modified state
                     $idx_hp_current = -1; $idx_lp_current = -1;
                     foreach($placementState[$containerId] as $idx => $item) {
                         if ($item['id'] === $hpItem_id) $idx_hp_current = $idx;
                         if ($item['id'] === $lpItem_id) $idx_lp_current = $idx;
                         if ($idx_hp_current !== -1 && $idx_lp_current !== -1) break;
                     }

                    if ($idx_hp_current !== -1 && $idx_lp_current !== -1) {
                        $placementState[$containerId][$idx_hp_current]['x'] = $dbUpdateHP['positionX']; $placementState[$containerId][$idx_hp_current]['y'] = $dbUpdateHP['positionY']; $placementState[$containerId][$idx_hp_current]['z'] = $dbUpdateHP['positionZ'];
                        $placementState[$containerId][$idx_hp_current]['w'] = $dbUpdateHP['placedDimensionW']; $placementState[$containerId][$idx_hp_current]['d'] = $dbUpdateHP['placedDimensionD']; $placementState[$containerId][$idx_hp_current]['h'] = $dbUpdateHP['placedDimensionH'];

                        $placementState[$containerId][$idx_lp_current]['x'] = $dbUpdateLP['positionX']; $placementState[$containerId][$idx_lp_current]['y'] = $dbUpdateLP['positionY']; $placementState[$containerId][$idx_lp_current]['z'] = $dbUpdateLP['positionZ'];
                        $placementState[$containerId][$idx_lp_current]['w'] = $dbUpdateLP['placedDimensionW']; $placementState[$containerId][$idx_lp_current]['d'] = $dbUpdateLP['placedDimensionD']; $placementState[$containerId][$idx_lp_current]['h'] = $dbUpdateLP['placedDimensionH'];

                        // Mark swapped
                        $swappedItemIdsThisPass[$hpItem_id] = true;
                        $swappedItemIdsThisPass[$lpItem_id] = true;
                        error_log("Refine Pass V12: State updated for DIRECT swap ($hpItem_id <-> $lpItem_id)");

                         // Important: Reset inner loop index `j` because the item at the original $j
                         // index might have changed. Restarting the inner loop comparison for $i is safest.
                         // However, a simpler approach for now is just to continue, accepting we might miss
                         // secondary swaps involving the moved items in this pass. Let's stick to continue for now.
                         // break; // Could break inner loop and restart j for item i, but increases complexity.
                    } else {
                         error_log("Refine Pass V12 WARN: Could not find indices for direct swap items $hpItem_id or $lpItem_id after potential state change.");
                    }

                } else {
                     // --- Direct Swap Failed - Attempt Complex Move (V12 Enhancement) ---
                     error_log("Refine Pass V12: Direct swap failed for ($hpItem_id, $lpItem_id). Attempting complex move...");

                     // 1. Try find a relocation spot for the LP item
                     error_log("Refine Pass V12: Finding relocation spot for LP item $lpItem_id (currently in $containerId)...");
                     $lpRelocationSpot = findBestRelocationSpot(
                         $lpItem_id,                       // Item to move
                         $lpItem_details['dimensions_api'], // Its original dimensions
                         $containerId,                     // Its current container
                         $lpItem_pos,                      // Its current position
                         $containerDimensionsMap,          // All container data <<< Passed in V12
                         $itemsMasterList,
                         $placementState,                  // Current state passed by reference
                         $hpItem_id,                       // HP item ID for context/logging
                         $hpItem_pos                       // *** Crucial: Tell relocation not to use HP's spot ***
                     );

                     if ($lpRelocationSpot) {
                         error_log("Refine Pass V12: Found relocation spot for LP item $lpItem_id in container {$lpRelocationSpot['containerId']}. Checking if HP item fits in LP's original spot...");

                         // 2. Check if HP item can fit in LP's ORIGINAL spot
                         $hpFittingOrientationInLpSpot = findFittingOrientation(
                             $hpItem_details['dimensions_api'], // HP item original dims
                             $lpItem_pos                        // LP item original box dims (x,y,z implicitly match)
                         );

                         if ($hpFittingOrientationInLpSpot !== null) {
                             // --- Complex Move is Possible! ---
                             error_log("Refine Pass V12: COMPLEX MOVE POSSIBLE for ($hpItem_id, $lpItem_id).");

                             // --- Generate API Moves ---
                             $moveLP_api = [
                                 'step' => $stepCounter++, 'action' => 'move', 'itemId' => $lpItem_id,
                                 'fromContainer' => $containerId, 'fromPosition' => formatApiPosition($lpItem_pos['x'], $lpItem_pos['y'], $lpItem_pos['z'], $lpItem_pos['w'], $lpItem_pos['d'], $lpItem_pos['h']),
                                 'toContainer' => $lpRelocationSpot['containerId'], 'toPosition' => formatApiPosition($lpRelocationSpot['foundX'], $lpRelocationSpot['foundY'], $lpRelocationSpot['foundZ'], $lpRelocationSpot['placedW'], $lpRelocationSpot['placedD'], $lpRelocationSpot['placedH'])
                             ];
                              $moveHP_api = [
                                 'step' => $stepCounter++, 'action' => 'move', 'itemId' => $hpItem_id,
                                 'fromContainer' => $containerId, 'fromPosition' => formatApiPosition($hpItem_pos['x'], $hpItem_pos['y'], $hpItem_pos['z'], $hpItem_pos['w'], $hpItem_pos['d'], $hpItem_pos['h']),
                                 'toContainer' => $containerId, 'toPosition' => formatApiPosition($lpItem_pos['x'], $lpItem_pos['y'], $lpItem_pos['z'], $hpFittingOrientationInLpSpot['w'], $hpFittingOrientationInLpSpot['d'], $hpFittingOrientationInLpSpot['h']) // Move HP to LP's old spot
                             ];
                             $swapApiMoves[] = $moveLP_api; // LP moves first
                             $swapApiMoves[] = $moveHP_api;

                             // --- Prepare DB Updates ---
                              $dbUpdateLP = [
                                 'action' => 'move', 'itemId' => $lpItem_id, 'containerId' => $lpRelocationSpot['containerId'],
                                 'positionX' => $lpRelocationSpot['foundX'], 'positionY' => $lpRelocationSpot['foundY'], 'positionZ' => $lpRelocationSpot['foundZ'],
                                 'placedDimensionW' => $lpRelocationSpot['placedW'], 'placedDimensionD' => $lpRelocationSpot['placedD'], 'placedDimensionH' => $lpRelocationSpot['placedH']
                             ];
                              $dbUpdateHP = [
                                 'action' => 'move', 'itemId' => $hpItem_id, 'containerId' => $containerId, // HP stays in original container
                                 'positionX' => $lpItem_pos['x'], 'positionY' => $lpItem_pos['y'], 'positionZ' => $lpItem_pos['z'], // To LP's original coords
                                 'placedDimensionW' => $hpFittingOrientationInLpSpot['w'], 'placedDimensionD' => $hpFittingOrientationInLpSpot['d'], 'placedDimensionH' => $hpFittingOrientationInLpSpot['h']
                             ];
                             $swapDbUpdates[$lpItem_id] = $dbUpdateLP;
                             $swapDbUpdates[$hpItem_id] = $dbUpdateHP;

                             // --- Update Placement State (More Complex) ---
                             error_log("Refine Pass V12: Updating state for complex move (LP: $lpItem_id to {$lpRelocationSpot['containerId']}, HP: $hpItem_id to LP's old spot)...");

                             // a) Remove LP from its original container/position
                             $lpRemoved = false;
                             if (isset($placementState[$containerId])) {
                                  foreach ($placementState[$containerId] as $idx => $item) {
                                      if ($item['id'] === $lpItem_id) {
                                          unset($placementState[$containerId][$idx]);
                                          $placementState[$containerId] = array_values($placementState[$containerId]); // Re-index
                                          $lpRemoved = true;
                                          error_log("Refine Pass V12: Removed $lpItem_id from state container $containerId.");
                                          break;
                                      }
                                  }
                             }
                             if (!$lpRemoved) { error_log("Refine Pass V12 WARN: Could not find LP item $lpItem_id in state container $containerId to remove."); }

                             // b) Add LP to its new container/position
                             $lpNewContainerId = $lpRelocationSpot['containerId'];
                             if (!isset($placementState[$lpNewContainerId])) {
                                 $placementState[$lpNewContainerId] = [];
                             }
                             $placementState[$lpNewContainerId][] = [
                                 'id' => $lpItem_id, 'x' => $dbUpdateLP['positionX'], 'y' => $dbUpdateLP['positionY'], 'z' => $dbUpdateLP['positionZ'],
                                 'w' => $dbUpdateLP['placedDimensionW'], 'd' => $dbUpdateLP['placedDimensionD'], 'h' => $dbUpdateLP['placedDimensionH']
                             ];
                             error_log("Refine Pass V12: Added $lpItem_id to state container $lpNewContainerId.");

                             // c) Update HP item's position in its original container state
                             $hpUpdated = false;
                              if (isset($placementState[$containerId])) { // Check again as LP removal might have emptied it
                                 foreach ($placementState[$containerId] as $idx => $item) {
                                     if ($item['id'] === $hpItem_id) {
                                         $placementState[$containerId][$idx]['x'] = $dbUpdateHP['positionX']; $placementState[$containerId][$idx]['y'] = $dbUpdateHP['positionY']; $placementState[$containerId][$idx]['z'] = $dbUpdateHP['positionZ'];
                                         $placementState[$containerId][$idx]['w'] = $dbUpdateHP['placedDimensionW']; $placementState[$containerId][$idx]['d'] = $dbUpdateHP['placedDimensionD']; $placementState[$containerId][$idx]['h'] = $dbUpdateHP['placedDimensionH'];
                                         $hpUpdated = true;
                                         error_log("Refine Pass V12: Updated HP item $hpItem_id position in state container $containerId.");
                                         break;
                                     }
                                 }
                             }
                              if (!$hpUpdated) { error_log("Refine Pass V12 WARN: Could not find HP item $hpItem_id in state container $containerId to update position."); }


                             // Mark swapped
                             $swappedItemIdsThisPass[$hpItem_id] = true;
                             $swappedItemIdsThisPass[$lpItem_id] = true;

                             // Recalculate item count for the current container for the loops
                             $itemsInContainer = $placementState[$containerId] ?? [];
                             $itemCount = count($itemsInContainer);
                             // break; // As before, potentially break inner loop and restart j for i. Continue for now.

                         } else {
                              error_log("Refine Pass V12: Complex move failed. HP item $hpItem_id cannot fit into LP item $lpItem_id's original spot even after LP relocation.");
                         }
                     } else {
                          error_log("Refine Pass V12: Complex move failed. Could not find suitable relocation spot for LP item $lpItem_id.");
                     }
                 } // End V12 complex move attempt
            } // end inner loop j
        } // end outer loop i
    } // end container loop

    error_log("--- Finished Enhanced Refinement Swap Pass (V12). Found " . count($swapApiMoves) . " API move steps resulting from swaps. ---");
    return [
        'swapApiMoves' => $swapApiMoves,
        'swapDbUpdates' => $swapDbUpdates,
        // placementState was modified by reference
    ];
}


// #########################################################################
// ## END: Modified Refinement Swap Pass Function (V12)                  ##
// #########################################################################


// #########################################################################
// ## START: Main Script Logic (Adjusted for V12 refine call)            ##
// #########################################################################

// --- Script Start ---
$response = ['success' => false, 'placements' => [], 'rearrangements' => []];
$internalErrors = []; $db = null; $itemsMasterList = [];

// --- Database Connection ---
try { $db = getDbConnection(); if ($db === null) throw new Exception("DB null"); }
catch (Exception $e) { http_response_code(503); error_log("FATAL: DB Connect Error - " . $e->getMessage()); echo json_encode(['success' => false, 'message' => 'DB connection error.']); exit; }

// --- Input Processing ---
$rawData = file_get_contents('php://input'); $requestData = json_decode($rawData, true);
if ($requestData === null || !isset($requestData['items'], $requestData['containers']) || !is_array($requestData['items']) || !is_array($requestData['containers'])) { http_response_code(400); error_log("Placement Error: Invalid JSON input: " . $rawData); echo json_encode(['success' => false, 'message' => 'Invalid input format.']); exit; }
$itemsToPlaceInput = $requestData['items']; $containersInput = $requestData['containers'];
$containerDimensionsMap = []; foreach ($containersInput as $c) { if (isset($c['containerId'], $c['width'], $c['depth'], $c['height'])) { $containerDimensionsMap[$c['containerId']] = ['width' => (float)$c['width'], 'depth' => (float)$c['depth'], 'height' => (float)$c['height'], 'zone' => $c['zone'] ?? 'UnknownZone']; } else { error_log("Skipping invalid container data: ".json_encode($c)); } }
error_log(PLACEMENT_ALGORITHM_NAME . " request: " . count($itemsToPlaceInput) . " items, " . count($containerDimensionsMap) . " valid containers.");

// --- Load Existing Item State from DB ---
$existingPlacedItemsByContainer = [];
try { /* ... (same DB load logic as V11) ... */
    $sqlPlaced = "SELECT i.itemId, i.containerId, i.priority, i.dimensionW, i.dimensionD, i.dimensionH, i.placedDimensionW, i.placedDimensionD, i.placedDimensionH, i.positionX AS posX, i.positionY AS posY, i.positionZ AS posZ, i.preferredContainerId, i.preferredZone FROM items i WHERE i.containerId IS NOT NULL AND i.status = 'stowed'";
    $stmtPlaced = $db->prepare($sqlPlaced); $stmtPlaced->execute(); $placedItemsResult = $stmtPlaced->fetchAll(PDO::FETCH_ASSOC);
    foreach ($placedItemsResult as $item) {
        $containerId = $item['containerId'];
        if (!isset($existingPlacedItemsByContainer[$containerId])) $existingPlacedItemsByContainer[$containerId] = [];
        $placementData = [ 'id' => $item['itemId'], 'x' => (float)$item['posX'], 'y' => (float)$item['posY'], 'z' => (float)$item['posZ'], 'w' => (float)$item['placedDimensionW'], 'd' => (float)$item['placedDimensionD'], 'h' => (float)$item['placedDimensionH'] ];
        $existingPlacedItemsByContainer[$containerId][] = $placementData;
        $apiWidth = (float)($item['dimensionW'] ?: $item['placedDimensionW']); $apiDepth = (float)($item['dimensionD'] ?: $item['placedDimensionD']); $apiHeight = (float)($item['dimensionH'] ?: $item['placedDimensionH']);
        $itemDimsApi = null; if ($apiWidth > 0 && $apiDepth > 0 && $apiHeight > 0) { $itemDimsApi = ['width' => $apiWidth, 'depth' => $apiDepth, 'height' => $apiHeight ]; } else { error_log("WARN: Existing item {$item['itemId']} has invalid dims in DB."); }
        $itemsMasterList[$item['itemId']] = [ 'priority' => (int)($item['priority'] ?? 0), 'dimensions_api' => $itemDimsApi, 'placement' => $placementData, 'preferredContainerId' => $item['preferredContainerId'] ?? null, 'preferredZone' => $item['preferredZone'] ?? null ];
    }
    error_log("Found existing placements for " . count($itemsMasterList) . " items in " . count($existingPlacedItemsByContainer) . " containers from DB.");
} catch (PDOException $e) { http_response_code(500); $response = ['success' => false, 'message' => 'DB error loading existing items.']; error_log("Placement DB Error (fetch existing): " . $e->getMessage()); echo json_encode($response); $db = null; exit; }

// --- Merge incoming item data into master list ---
$validInputItemsCount = 0;
foreach ($itemsToPlaceInput as $item) { /* ... (same merge logic as V11) ... */
    if (isset($item['itemId'])) {
        $itemId = $item['itemId']; $validInputItemsCount++;
        if (!isset($itemsMasterList[$itemId])) { $itemsMasterList[$itemId] = ['priority' => 0, 'dimensions_api' => null, 'placement' => null, 'preferredContainerId' => null, 'preferredZone' => null]; }
        $newWidth = (float)($item['width'] ?? $itemsMasterList[$itemId]['dimensions_api']['width'] ?? 0); $newDepth = (float)($item['depth'] ?? $itemsMasterList[$itemId]['dimensions_api']['depth'] ?? 0); $newHeight = (float)($item['height'] ?? $itemsMasterList[$itemId]['dimensions_api']['height'] ?? 0);
        $itemDims = null; if ($newWidth > 0 && $newDepth > 0 && $newHeight > 0) { $itemDims = ['width' => $newWidth, 'depth' => $newDepth, 'height' => $newHeight]; } else { $itemDims = $itemsMasterList[$itemId]['dimensions_api']; /* Keep old if new invalid */ error_log("WARN: Input item $itemId invalid dims."); }
        $itemsMasterList[$itemId] = [ 'priority' => (int)($item['priority'] ?? $itemsMasterList[$itemId]['priority']), 'dimensions_api' => $itemDims, 'placement' => $itemsMasterList[$itemId]['placement'], 'preferredContainerId' => (!empty($item['preferredContainerId'])) ? $item['preferredContainerId'] : $itemsMasterList[$itemId]['preferredContainerId'], 'preferredZone' => (!empty($item['preferredZone'])) ? $item['preferredZone'] : $itemsMasterList[$itemId]['preferredZone'] ];
    } else { error_log("Skipping input item missing 'itemId'."); }
}
error_log("Items Master List updated. Total items: " . count($itemsMasterList));


// --- Placement Algorithm Logic ---
$currentPlacementState = $existingPlacedItemsByContainer;
$dbUpdates = [];           // Collect DB changes [itemId => dbUpdateData]
$rearrangementSteps = [];  // Collect rearrangement API steps (initial rearrange + refinement moves)
$finalPlacements = [];     // Collect successful placements [itemId => placementResponse]
$stepCounter = 1;
$processedItemIds = [];

// --- Filter Items To Be Processed ---
$itemsToProcess = [];
foreach ($itemsToPlaceInput as $item) { /* ... (same filtering logic as V11) ... */
     if (!isset($item['itemId'])) continue; $itemId = $item['itemId'];
     if (!isset($itemsMasterList[$itemId])) { error_log("Item $itemId from input missing in master. Skipping."); continue; }
     // Only process items that are NOT currently placed ('placement' is null in master list)
     if ($itemsMasterList[$itemId]['placement'] === null) {
          if($itemsMasterList[$itemId]['dimensions_api'] === null) {
              error_log("Item $itemId needs placement but has invalid dims. Skipping.");
              $internalErrors[] = ['itemId' => $itemId, 'reason' => 'Invalid dimensions for placement.'];
          }
          else {
              $itemsToProcess[] = ['itemId' => $itemId]; // Only need ID, details fetched from master list
          }
     } else {
         $processedItemIds[$itemId] = true; // Mark as already processed (it's already placed)
     }
}
error_log("Items requiring placement processing: " . count($itemsToProcess));

// --- Sort Items To Be Processed ---
if (!empty($itemsToProcess)) { /* ... (same sorting logic as V11) ... */
    error_log("Sorting " . count($itemsToProcess) . " items...");
    usort($itemsToProcess, function($a, $b) use ($itemsMasterList) {
         $priorityA = $itemsMasterList[$a['itemId']]['priority'] ?? 0; $priorityB = $itemsMasterList[$b['itemId']]['priority'] ?? 0;
         if ($priorityA !== $priorityB) { return $priorityB <=> $priorityA; } // Highest priority first
         $dimsA = $itemsMasterList[$a['itemId']]['dimensions_api']; $dimsB = $itemsMasterList[$b['itemId']]['dimensions_api'];
         $volumeA = ($dimsA !== null) ? (($dimsA['width'] ?? 0) * ($dimsA['depth'] ?? 0) * ($dimsA['height'] ?? 0)) : 0;
         $volumeB = ($dimsB !== null) ? (($dimsB['width'] ?? 0) * ($dimsB['depth'] ?? 0) * ($dimsB['height'] ?? 0)) : 0;
         if (abs($volumeA - $volumeB) > FLOAT_EPSILON) { return $volumeB <=> $volumeA; } // Largest volume first
         return ($a['itemId'] ?? '') <=> ($b['itemId'] ?? ''); // Fallback to ID for consistent order
    });
     error_log("Items sorted. First: " . ($itemsToProcess[0]['itemId'] ?? 'None'));
}

// --- Split Sorted Items into Priority Tiers ---
$highPrioItems = []; $mediumPrioItems = []; $lowPrioItems = [];
foreach ($itemsToProcess as $item) { /* ... (same tier splitting logic as V11) ... */
    $itemId = $item['itemId']; $prio = $itemsMasterList[$itemId]['priority'];
    if ($prio >= HIGH_PRIORITY_THRESHOLD) { $highPrioItems[] = $item; }
    elseif ($prio <= LOW_PRIORITY_THRESHOLD) { $lowPrioItems[] = $item; }
    else { $mediumPrioItems[] = $item; }
}
error_log("Split into tiers: High=" . count($highPrioItems) . ", Medium=" . count($mediumPrioItems) . ", Low=" . count($lowPrioItems));

// --- Placement Pass 1: High Priority (Aggressive Ideal Spot + Fallback) ---
error_log("--- Starting High Priority Placement Pass (V12 Aggressive) ---");
foreach ($highPrioItems as $itemData) { // itemData only contains itemId now
    $itemId = $itemData['itemId'];
    if (isset($processedItemIds[$itemId])) continue; // Skip if already handled (e.g., placed in DB)
    $result = placeSingleItem( ['itemId' => $itemId], $itemsMasterList, $containerDimensionsMap, $currentPlacementState, $stepCounter, true );
    $processedItemIds[$itemId] = true; // Mark as processed
    if ($result['success']) {
        $finalPlacements[$itemId] = $result['placement'];
        if ($result['rearrangementResult'] && $result['rearrangementResult']['success']) {
            error_log("Item $itemId (High Prio): Placement OK via INITIAL Rearrangement. Reason: " . $result['reason']); // Note context
            if (!empty($result['rearrangementResult']['moves'])) { foreach ($result['rearrangementResult']['moves'] as $move) { $rearrangementSteps[] = $move['apiResponse']; $dbUpdates[$move['itemId']] = $move['dbUpdate']; } }
            if (isset($result['rearrangementResult']['finalPlacement']['apiResponse'])) { $rearrangementSteps[] = $result['rearrangementResult']['finalPlacement']['apiResponse']; }
            if (isset($result['rearrangementResult']['finalPlacement']['dbUpdate'])) { $dbUpdates[$itemId] = $result['rearrangementResult']['finalPlacement']['dbUpdate']; }
        } elseif ($result['dbUpdate']) {
            $dbUpdates[$itemId] = $result['dbUpdate']; error_log("Item $itemId (High Prio): Placement OK (Direct/Fallback). Reason: " . $result['reason']);
        } else { error_log("Item $itemId (High Prio): Placement SUCCESS but no DB update and not initial rearrangement? Reason: " . $result['reason']); $internalErrors[] = ['itemId' => $itemId, 'reason' => 'Placement success state mismatch (initial).']; }
    } else { error_log("Item $itemId (High Prio): Placement FAILED (Initial Attempt). Reason: " . $result['reason']); $internalErrors[] = ['itemId' => $itemId, 'reason' => $result['reason']]; }
}

// --- Placement Pass 2: Medium Priority (Standard Best Available) ---
error_log("--- Starting Medium Priority Placement Pass (V12 Standard) ---");
foreach ($mediumPrioItems as $itemData) { /* ... (same pass 2 logic as V11) ... */
     $itemId = $itemData['itemId']; if (isset($processedItemIds[$itemId])) continue;
     $result = placeSingleItem( ['itemId' => $itemId], $itemsMasterList, $containerDimensionsMap, $currentPlacementState, $stepCounter, false ); // Rearr OFF
     $processedItemIds[$itemId] = true;
     if ($result['success']) {
         $finalPlacements[$itemId] = $result['placement'];
         if ($result['dbUpdate']) { $dbUpdates[$itemId] = $result['dbUpdate']; error_log("Item $itemId (Medium Prio): Placed OK. Reason: " . $result['reason']); }
         else { error_log("Item $itemId (Medium Prio): Success but no DB update? Reason: " . $result['reason']); $internalErrors[] = ['itemId' => $itemId, 'reason' => 'Success but no DB update']; }
     } else { error_log("Item $itemId (Medium Prio): Placement FAILED. Reason: " . $result['reason']); $internalErrors[] = ['itemId' => $itemId, 'reason' => $result['reason']]; }
}

// --- Placement Pass 3: Low Priority (Standard Best Available) ---
error_log("--- Starting Low Priority Placement Pass (V12 Standard) ---");
foreach ($lowPrioItems as $itemData) { /* ... (same pass 3 logic as V11) ... */
     $itemId = $itemData['itemId']; if (isset($processedItemIds[$itemId])) continue;
     $result = placeSingleItem( ['itemId' => $itemId], $itemsMasterList, $containerDimensionsMap, $currentPlacementState, $stepCounter, false ); // Rearr OFF
     $processedItemIds[$itemId] = true;
      if ($result['success']) {
         $finalPlacements[$itemId] = $result['placement'];
          if ($result['dbUpdate']) { $dbUpdates[$itemId] = $result['dbUpdate']; error_log("Item $itemId (Low Prio): Placed OK. Reason: " . $result['reason']); }
          else { error_log("Item $itemId (Low Prio): Success but no DB update? Reason: " . $result['reason']); $internalErrors[] = ['itemId' => $itemId, 'reason' => 'Success but no DB update']; }
     } else { error_log("Item $itemId (Low Prio): Placement FAILED. Reason: " . $result['reason']); $internalErrors[] = ['itemId' => $itemId, 'reason' => $result['reason']]; }
}

// --- V12: Enhanced Refinement Swap Pass ---
$refinementResult = refinePlacementsBySwapping(
    $currentPlacementState,    // Pass by reference
    $itemsMasterList,
    $containerDimensionsMap,   // <<< Pass container map V12
    $stepCounter               // Pass by reference
);

// Merge results from refinement pass
if (!empty($refinementResult['swapApiMoves'])) {
    // Append refinement moves to any moves from the initial HP rearrangement phase
    $rearrangementSteps = array_merge($rearrangementSteps, $refinementResult['swapApiMoves']);
}
if (!empty($refinementResult['swapDbUpdates'])) {
    // Merge swap DB updates, potentially overwriting previous updates for swapped items
    // or updates from the initial HP rearrangement phase if a blocker was later involved in a swap.
    foreach ($refinementResult['swapDbUpdates'] as $itemId => $updateData) {
        $dbUpdates[$itemId] = $updateData;
    }
    error_log("Refinement Pass V12: Merged " . count($refinementResult['swapDbUpdates']) . " DB updates from direct/complex swaps.");
}

// Final results for response object
$response['placements'] = array_values($finalPlacements); // Shows initial successful placements
$response['rearrangements'] = $rearrangementSteps; // Shows ALL moves (initial rearrange + refinement moves)


// --- Update Database ---
$updatedCount = 0; $dbUpdateFailed = false; $actuallyUpdatedIds = [];
if (!empty($dbUpdates)) { /* ... (same DB update logic as V11) ... */
    error_log("DB Update Prep: Processing " . count($dbUpdates) . " final placement/move actions.");
    $updateSql = "UPDATE items SET containerId = :containerId, positionX = :positionX, positionY = :positionY, positionZ = :positionZ, placedDimensionW = :placedW, placedDimensionD = :placedD, placedDimensionH = :placedH, status = 'stowed', lastUpdated = :lastUpdated WHERE itemId = :itemId";
    try {
        $db->beginTransaction();
        $updateStmt = $db->prepare($updateSql);
        $lastUpdated = date(DB_DATETIME_FORMAT);
        $bind_containerId = null; $bind_posX = null; $bind_posY = null; $bind_posZ = null; $bind_placedW = null; $bind_placedD = null; $bind_placedH = null; $bind_itemId = null;
        $updateStmt->bindParam(':containerId', $bind_containerId); $updateStmt->bindParam(':positionX', $bind_posX); $updateStmt->bindParam(':positionY', $bind_posY); $updateStmt->bindParam(':positionZ', $bind_posZ); $updateStmt->bindParam(':placedW', $bind_placedW); $updateStmt->bindParam(':placedD', $bind_placedD); $updateStmt->bindParam(':placedH', $bind_placedH); $updateStmt->bindParam(':lastUpdated', $lastUpdated); $updateStmt->bindParam(':itemId', $bind_itemId, PDO::PARAM_STR);

        foreach ($dbUpdates as $itemId => $updateData) {
             if (!isset($updateData['action'], $updateData['itemId'], $updateData['containerId'], $updateData['positionX'], $updateData['positionY'], $updateData['positionZ'], $updateData['placedDimensionW'], $updateData['placedDimensionD'], $updateData['placedDimensionH']) || $updateData['itemId'] !== $itemId ) { error_log("DB Update Skip: Incomplete data for item $itemId: " . json_encode($updateData)); continue; }
            $bind_containerId = $updateData['containerId']; $bind_posX = $updateData['positionX']; $bind_posY = $updateData['positionY']; $bind_posZ = $updateData['positionZ']; $bind_placedW = $updateData['placedDimensionW']; $bind_placedD = $updateData['placedDimensionD']; $bind_placedH = $updateData['placedDimensionH']; $bind_itemId = $updateData['itemId'];
            if ($updateStmt->execute()) {
                $rowCount = $updateStmt->rowCount();
                if ($rowCount > 0) { $updatedCount++; $actuallyUpdatedIds[] = $bind_itemId; /* error_log("DB Update OK for Item $bind_itemId"); */ }
                else { error_log("DB Update WARN: 0 rows affected for item: $bind_itemId."); }
            } else { $errorInfo = $updateStmt->errorInfo(); $errorMsg = "DB Update FAIL for itemId: " . $bind_itemId . " - Error: " . ($errorInfo[2] ?? 'Unknown PDO Error'); error_log($errorMsg); throw new PDOException($errorMsg); }
        }
        $db->commit();
        error_log("DB Update Commit: Transaction committed. Items affected: $updatedCount. IDs: " . implode(', ', $actuallyUpdatedIds));
        // Set final success based on placement/rearrangement results
        if (!empty($response['placements'])) { $response['success'] = true; } // Success if at least one item was placed
        else if (count($itemsToProcess) === 0 && empty($internalErrors)) { $response['success'] = true; $response['message'] = "No items required placement."; }
        else { $response['success'] = false; $response['message'] = "Placement algorithm ran, but no items could be placed."; } // Failure if items attempted but none placed

    } catch (PDOException $e) {
         if ($db->inTransaction()) { $db->rollBack(); error_log("DB Update ROLLED BACK."); }
         http_response_code(500); $response['success'] = false; $response['placements'] = []; $response['rearrangements'] = [];
         $response['message'] = "DB update failed. Transaction rolled back."; error_log("Placement DB Error (update): " . $e->getMessage()); $dbUpdateFailed = true;
    }
} else { /* ... (same logic as V11) ... */
     if (!empty($response['placements'])) { $response['success'] = true; }
     else if (count($itemsToProcess) === 0 && empty($internalErrors)) { $response['success'] = true; $response['message'] = $response['message'] ?? "No items required placement."; }
     else { $response['success'] = false; $response['message'] = $response['message'] ?? "Placement completed, but no items placed/rearranged and no DB updates pending."; }
     error_log("No DB updates needed.");
}


// --- Finalize and Echo Response ---
if (!$dbUpdateFailed) { /* ... (same logic as V11) ... */
     $attemptedCount = count($itemsToProcess);
     $placedCount = count($response['placements'] ?? []);
     $rearrangeAndSwapStepCount = count($response['rearrangements'] ?? []);
     $swapCount = count($refinementResult['swapApiMoves'] ?? []) / 2; // Each swap adds 2 moves

     if ($response['success']) {
         // If we successfully placed *some* items, but not all attempted, return 207 Partial Content
         if ($attemptedCount > 0 && $placedCount < $attemptedCount) {
             http_response_code(207);
             $response['message'] = $response['message'] ?? "Placement partially successful. Placed: $placedCount/" . $attemptedCount . ".";
             if ($rearrangeAndSwapStepCount > 0) {
                  $response['message'] .= " Includes " . $rearrangeAndSwapStepCount . " rearrangement/swap steps.";
             }
             if (!empty($internalErrors)) { $response['message'] .= " See warnings."; }
         } else { // All attempted items placed, or no items needed placement
              http_response_code(200);
              $response['message'] = $response['message'] ?? ($attemptedCount > 0 ? "Placement successful." : "No items required placement.");
              if ($rearrangeAndSwapStepCount > 0) {
                   $response['message'] .= " Includes " . $rearrangeAndSwapStepCount . " rearrangement/swap steps.";
              }
              // Don't mention warnings here if fully successful unless specifically desired
         }
     } else { // Response success is false (typically means 0 items placed out of those attempted)
          if (http_response_code() < 400) { http_response_code(422); } // Unprocessable Entity if not already an error
          $response['message'] = $response['message'] ?? "Placement failed.";
          if (!empty($internalErrors)) { $response['message'] .= " See warnings for details."; }
     }
     // Always include warnings if they exist
     if (!empty($internalErrors)) { $response['warnings'] = $internalErrors; }
}

// --- Logging Summary ---
$finalResponseSuccess = $response['success'] ?? false; $finalHttpMessage = $response['message'] ?? null;
$finalDbUpdatesAttempted = count($dbUpdates); $finalPlacedCount = count($response['placements'] ?? []);
$finalRearrangementCount = count($response['rearrangements'] ?? []); $finalWarningCount = count($response['warnings'] ?? $internalErrors);
$finalSwapCount = count($refinementResult['swapApiMoves'] ?? []) / 2; // This counts successful direct swaps OR complex moves from refinement

try { /* ... (same logging logic as V11) ... */
    if ($db) {
        $logSql = "INSERT INTO logs (userId, actionType, detailsJson, timestamp) VALUES (:userId, :actionType, :details, :timestamp)"; $logStmt = $db->prepare($logSql);
        $logDetails = [
             'operationType' => 'placement', 'algorithm' => PLACEMENT_ALGORITHM_NAME,
             'requestInputItemCount' => count($itemsToPlaceInput), 'itemsAttemptedProcessing' => count($itemsToProcess),
             'responseSuccess' => $finalResponseSuccess, 'httpStatusCode' => http_response_code(),
             'itemsPlacedCount' => $finalPlacedCount,
             'dbUpdatesAttempted' => $finalDbUpdatesAttempted, 'dbUpdatesSuccessful' => $updatedCount,
             'rearrangementStepsCount' => $finalRearrangementCount, 'swapRefinementCount' => $finalSwapCount, // Includes complex moves
             'warningsOrErrorsCount' => $finalWarningCount, 'finalMessage' => $finalHttpMessage
         ];
        $logParams = [ ':userId' => 'System_PlacementAPI_V12ER', ':actionType' => 'placement_v12_er', ':details' => json_encode($logDetails, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), ':timestamp' => date(DB_DATETIME_FORMAT) ];
        if (!$logStmt->execute($logParams)) { error_log("CRITICAL: Failed to execute placement summary log query!"); }
    }
 } catch (Exception $logEx) { error_log("CRITICAL: Failed during placement summary logging! Error: " . $logEx->getMessage()); }

// --- Send Output ---
if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
$finalResponsePayload = [ 'success' => $response['success'], 'placements' => $response['placements'] ?? [], 'rearrangements' => $response['rearrangements'] ?? [] ];
if (isset($response['message'])) { $finalResponsePayload['message'] = $response['message']; }
if (!empty($response['warnings'])) { $finalResponsePayload['warnings'] = $response['warnings']; }
echo json_encode($finalResponsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
error_log(PLACEMENT_ALGORITHM_NAME . " Finished. HTTP Code: " . http_response_code() . ". Success: " . ($finalResponsePayload['success'] ? 'Yes' : 'No') . ". Placed: " . count($finalResponsePayload['placements']) . ". Rearr Steps: " . count($finalResponsePayload['rearrangements']) . " (Refine Moves: " . count($refinementResult['swapApiMoves'] ?? []) . "). Attempted: " . count($itemsToProcess) . ". DB Updates: $updatedCount/$finalDbUpdatesAttempted. Warnings: " . count($finalResponsePayload['warnings'] ?? []) . ".");
$db = null; exit();

// #########################################################################
// ## END: Main Script Logic                                             ##
// #########################################################################
?>