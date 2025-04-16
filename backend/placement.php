<?php
// --- File: backend/placement.php (V10 - Refinement Swap Pass) ---
ini_set('max_execution_time', 360); // Increased slightly for refinement pass
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/database.php';

// #########################################################################
// ## START: Constants & Config                                          ##
// #########################################################################

define('DB_DATETIME_FORMAT', 'Y-m-d H:i:s');
define('PLACEMENT_ALGORITHM_NAME', 'SurfaceHeuristic_BestFit_V10_RefineSwap'); // Updated name

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
// ## START: Core Helper Functions (Includes existing + new helpers)      ##
// #########################################################################

// --- generateOrientations(...) - Unchanged ---
function generateOrientations(array $dimensions): array {
    // ... (same as V9) ...
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
function boxesOverlap(array $box1, array $box2): bool {
    // ... (same as V9) ...
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
function findSpaceForItem(string $itemId, array $itemDimensionsApi, int $itemPriority, string $containerId, array $containerDimensionsApi, array $existingItems): ?array {
    // ... (same as V9) ...
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
function findBestRelocationSpot(/* ... */): ?array {
    // ... (same as V9) ...
    // Note: This function is used during rearrangement, not during the refinement swap pass.
     $itemToMoveId = func_get_arg(0); $itemDimensions = func_get_arg(1);
     $originalContainerId = func_get_arg(2); $originalPosition = func_get_arg(3);
     $allContainerDimensions = func_get_arg(4); $itemsMasterList = func_get_arg(5);
     $currentPlacementState = func_get_arg(6); $highPriorityItemId = func_get_arg(7);
     $idealSpotToClear = func_get_arg(8);

     $orientations = generateOrientations($itemDimensions);
    if (empty($orientations)) return null;
    $containerSearchOrder = [];
    if (isset($allContainerDimensions[$originalContainerId])) { $containerSearchOrder[$originalContainerId] = $allContainerDimensions[$originalContainerId]; }
    // Add others
    foreach($allContainerDimensions as $cId => $cData) { if ($cId !== $originalContainerId && !isset($containerSearchOrder[$cId])) { $containerSearchOrder[$cId] = $cData; } }


    foreach ($containerSearchOrder as $containerId => $containerDims) {
        $itemsInThisContainer = [];
        if (isset($currentPlacementState[$containerId])) { foreach ($currentPlacementState[$containerId] as $item) { if ($item['id'] !== $itemToMoveId) { $itemsInThisContainer[] = $item; } } }
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
                 $isOriginalPos = false; if ($containerId === $originalContainerId) { if (abs($x - (float)$originalPosition['x']) < POSITION_EPSILON && abs($y - (float)$originalPosition['y']) < POSITION_EPSILON && abs($z - (float)$originalPosition['z']) < POSITION_EPSILON) { $isOriginalPos = true; } }
                 if ($isOriginalPos) continue;
                 $isIdealSpot = false; if ($containerId === $originalContainerId) { if (boxesOverlap($potentialPlacement, $idealSpotToClear)) { $isIdealSpot = true; } }
                 if ($isIdealSpot) continue;

                 $currentScore = ($y * ACCESSIBILITY_SCORE_WEIGHT_Y) + ($z * ACCESSIBILITY_SCORE_WEIGHT_Z) + ($x * ACCESSIBILITY_SCORE_WEIGHT_X);
                 if ($bestScoreInContainer === null || $currentScore < $bestScoreInContainer) {
                     $bestScoreInContainer = $currentScore;
                     $bestPlacementInContainer = ['containerId' => $containerId, 'foundX' => $x, 'foundY' => $y, 'foundZ' => $z, 'placedW' => $itemW, 'placedD' => $itemD, 'placedH' => $itemH ];
                 }
            }
        }
        if ($bestPlacementInContainer !== null) {
            error_log("findBestRelocationSpot: Found valid spot for $itemToMoveId in $containerId at (Y={$bestPlacementInContainer['foundY']}, Z={$bestPlacementInContainer['foundZ']}, X={$bestPlacementInContainer['foundX']})");
            return $bestPlacementInContainer;
        }
    }
    error_log("findBestRelocationSpot: Could not find any valid relocation spot for $itemToMoveId.");
    return null;
}

// --- attemptRearrangementForHighPriorityItem(...) - Unchanged ---
function attemptRearrangementForHighPriorityItem(/* ... */): array {
    // ... (same as V9) ...
    $itemIdToPlace = func_get_arg(0); $itemDimensionsApi = func_get_arg(1); $itemPriority = func_get_arg(2);
    $preferredContainerId = func_get_arg(3); $preferredZone = func_get_arg(4); $allContainerDimensions = func_get_arg(5);
    $currentPlacementState = func_get_arg(6); $itemsMasterList = func_get_arg(7); $stepCounter = func_get_arg(8);

    error_log("Rearrange Triggered for $itemIdToPlace (Prio: $itemPriority, PrefCont: ".($preferredContainerId ?? 'None').", PrefZone: ".($preferredZone ?? 'None').")");
    $rearrangementMoves = []; $tempPlacementState = $currentPlacementState;
    $targetContainerIds = []; $targetZone = null; $foundInZone = false;

    if ($preferredContainerId !== null && isset($allContainerDimensions[$preferredContainerId])) {
        $targetContainerIds[] = $preferredContainerId; $targetZone = $allContainerDimensions[$preferredContainerId]['zone'] ?? null;
    } elseif ($preferredZone !== null) {
        $targetZone = $preferredZone;
        foreach ($allContainerDimensions as $cId => $cData) { if (($cData['zone'] ?? null) === $targetZone) { $targetContainerIds[] = $cId; $foundInZone = true; } }
    }

    if (empty($targetContainerIds)) { /* ... handle no target ... */ return ['success' => false, 'reason' => 'No valid target for rearrangement', 'moves' => [], 'finalPlacement' => null, 'newState' => $currentPlacementState, 'nextStep' => $stepCounter]; }
     error_log("Rearrange for $itemIdToPlace: Final target containers: " . implode(', ', $targetContainerIds));

    foreach ($targetContainerIds as $targetContainerId) {
        error_log("Rearrange for $itemIdToPlace: Assessing target $targetContainerId");
        if (!isset($allContainerDimensions[$targetContainerId])) continue;
        $targetContainerDims = $allContainerDimensions[$targetContainerId];
        $itemsInTargetContainerOriginal = $tempPlacementState[$targetContainerId] ?? [];

        $potentialIdealPlacement = findSpaceForItem($itemIdToPlace, $itemDimensionsApi, $itemPriority, $targetContainerId, $targetContainerDims, []);
        if ($potentialIdealPlacement === null) { error_log("Rearrange [$itemIdToPlace]: Doesn't fit in $targetContainerId."); continue; }
        $idealCoords = ['x' => $potentialIdealPlacement['foundX'], 'y' => $potentialIdealPlacement['foundY'], 'z' => $potentialIdealPlacement['foundZ'], 'w' => $potentialIdealPlacement['placedW'], 'd' => $potentialIdealPlacement['placedD'], 'h' => $potentialIdealPlacement['placedH']];
        error_log("Rearrange [$itemIdToPlace]: Ideal in $targetContainerId: (Y={$idealCoords['y']}, Z={$idealCoords['z']}, X={$idealCoords['x']})");

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
        if ($foundNonMovableBlocker) { error_log("Rearrange [$itemIdToPlace]: Aborting $targetContainerId due to non-movable blocker."); continue; }

        $allBlockersRelocatedSuccessfully = true; $currentMovesForThisAttempt = [];
        $tempStateForThisAttempt = $tempPlacementState; // Re-copy state for this attempt

        if (!empty($blockersToMove)) {
            error_log("Rearrange [$itemIdToPlace]: Relocating " . count($blockersToMove) . " blockers in $targetContainerId.");
            foreach ($blockersToMove as $blockerInfo) {
                $blockerId = $blockerInfo['id']; $blockerData = $blockerInfo['data']; $blockerOriginalDimensions = $blockerInfo['originalDims'];
                error_log("Rearrange [$itemIdToPlace]: Finding spot for blocker $blockerId...");
                $relocationSpot = findBestRelocationSpot($blockerId, $blockerOriginalDimensions, $targetContainerId, $blockerData, $allContainerDimensions, $itemsMasterList, $tempStateForThisAttempt, $itemIdToPlace, $idealCoords);

                if ($relocationSpot) {
                    $newContId = $relocationSpot['containerId']; $newX = $relocationSpot['foundX']; $newY = $relocationSpot['foundY']; $newZ = $relocationSpot['foundZ']; $newW = $relocationSpot['placedW']; $newD = $relocationSpot['placedD']; $newH = $relocationSpot['placedH'];
                    error_log("Rearrange [$itemIdToPlace]: Relocated blocker $blockerId to $newContId (Y=$newY, Z=$newZ, X=$newX).");
                    $moveData = [ 'step' => $stepCounter + count($currentMovesForThisAttempt), 'action' => 'move','itemId' => $blockerId, 'fromContainer' => $targetContainerId,'fromPosition' => formatApiPosition($blockerData['x'], $blockerData['y'], $blockerData['z'], $blockerData['w'], $blockerData['d'], $blockerData['h']), 'toContainer' => $newContId,'toPosition' => formatApiPosition($newX, $newY, $newZ, $newW, $newD, $newH) ];
                    $dbUpdateData = ['action' => 'move', 'itemId' => $blockerId, 'containerId' => $newContId, 'positionX' => $newX, 'positionY' => $newY, 'positionZ' => $newZ, 'placedDimensionW' => $newW, 'placedDimensionD' => $newD, 'placedDimensionH' => $newH ];
                    $currentMovesForThisAttempt[] = ['apiResponse' => $moveData, 'itemId' => $blockerId, 'dbUpdate' => $dbUpdateData];
                    // Update temp state
                     $found = false; if (isset($tempStateForThisAttempt[$targetContainerId])) { foreach ($tempStateForThisAttempt[$targetContainerId] as $idx => $item) { if ($item['id'] === $blockerId) { unset($tempStateForThisAttempt[$targetContainerId][$idx]); $tempStateForThisAttempt[$targetContainerId] = array_values($tempStateForThisAttempt[$targetContainerId]); $found = true; break; } } }
                     if (!$found) error_log("Rearrange WARN [$itemIdToPlace]: Blocker $blockerId not found in old container $targetContainerId state.");
                     if (!isset($tempStateForThisAttempt[$newContId])) $tempStateForThisAttempt[$newContId] = [];
                     $tempStateForThisAttempt[$newContId][] = ['id' => $blockerId, 'x' => $newX, 'y' => $newY, 'z' => $newZ, 'w' => $newW, 'd' => $newD, 'h' => $newH];
                } else {
                    error_log("Rearrange FAIL [$itemIdToPlace]: Could not relocate blocker $blockerId. Aborting $targetContainerId.");
                    $allBlockersRelocatedSuccessfully = false; $currentMovesForThisAttempt = []; break;
                }
            }
        }
        if (!$allBlockersRelocatedSuccessfully) { error_log("Rearrange [$itemIdToPlace]: Skipping $targetContainerId due to blocker move failure."); continue; }

        $finalPlacementCheck = findSpaceForItem($itemIdToPlace, $itemDimensionsApi, $itemPriority, $targetContainerId, $targetContainerDims, $tempStateForThisAttempt[$targetContainerId] ?? []);
        $isIdealSpotFound = false;
        if ($finalPlacementCheck !== null && abs($finalPlacementCheck['foundX'] - $idealCoords['x']) < POSITION_EPSILON && abs($finalPlacementCheck['foundY'] - $idealCoords['y']) < POSITION_EPSILON && abs($finalPlacementCheck['foundZ'] - $idealCoords['z']) < POSITION_EPSILON) { $isIdealSpotFound = true; }

        if ($isIdealSpotFound) {
             error_log("Rearrange SUCCESS [$itemIdToPlace]: Final placement verified in ideal spot in $targetContainerId.");
            $finalX = $finalPlacementCheck['foundX']; $finalY = $finalPlacementCheck['foundY']; $finalZ = $finalPlacementCheck['foundZ']; $finalW = $finalPlacementCheck['placedW']; $finalD = $finalPlacementCheck['placedD']; $finalH = $finalPlacementCheck['placedH'];
            $committedMoves = []; $currentStep = $stepCounter;
            foreach ($currentMovesForThisAttempt as $move) { $move['apiResponse']['step'] = $currentStep++; $committedMoves[] = $move; }
            $finalPlacementData = [
                 'apiResponse' => ['step' => $currentStep++, 'action' => 'place', 'itemId' => $itemIdToPlace, 'fromContainer' => null, 'fromPosition' => null, 'toContainer' => $targetContainerId, 'toPosition' => formatApiPosition($finalX, $finalY, $finalZ, $finalW, $finalD, $finalH)],
                 'placementResponse' => ['itemId' => $itemIdToPlace, 'containerId' => $targetContainerId, 'position' => formatApiPosition($finalX, $finalY, $finalZ, $finalW, $finalD, $finalH)],
                 'dbUpdate' => ['action' => 'place', 'itemId' => $itemIdToPlace, 'containerId' => $targetContainerId, 'positionX' => $finalX, 'positionY' => $finalY, 'positionZ' => $finalZ, 'placedDimensionW' => $finalW, 'placedDimensionD' => $finalD, 'placedDimensionH' => $finalH ]
             ];
             if (!isset($tempStateForThisAttempt[$targetContainerId])) $tempStateForThisAttempt[$targetContainerId] = [];
             $tempStateForThisAttempt[$targetContainerId][] = ['id' => $itemIdToPlace, 'x' => $finalX, 'y' => $finalY, 'z' => $finalZ, 'w' => $finalW, 'd' => $finalD, 'h' => $finalH];
             $tempStateForThisAttempt[$targetContainerId] = array_values($tempStateForThisAttempt[$targetContainerId]);
             return ['success' => true,'reason' => 'Rearrangement successful in container ' . $targetContainerId,'moves' => $committedMoves,'finalPlacement' => $finalPlacementData,'newState' => $tempStateForThisAttempt,'nextStep' => $currentStep ];
        } else {
            error_log("Rearrange FAIL [$itemIdToPlace]: FINAL CHECK FAILED in $targetContainerId. Spot found: " . json_encode($finalPlacementCheck));
            continue;
        }
    } // End loop through target containers

    error_log("Rearrange FAIL [$itemIdToPlace]: Exhausted all target containers.");
    return ['success' => false, 'reason' => 'Could not find suitable rearrangement solution in any target container.', 'moves' => [], 'finalPlacement' => null, 'newState' => $currentPlacementState, 'nextStep' => $stepCounter];
}

// --- formatApiPosition(...) - Unchanged ---
function formatApiPosition(float $x, float $y, float $z, float $w, float $d, float $h): array {
    // ... (same as V9) ...
     return [
        'startCoordinates' => ['width' => round($x, 3), 'depth' => round($y, 3), 'height' => round($z, 3)],
        'endCoordinates' => ['width' => round($x + $w, 3), 'depth' => round($y + $d, 3), 'height' => round($z + $h, 3)]
    ];
}

// --- placeSingleItem(...) - Unchanged ---
function placeSingleItem(
    array $itemToPlaceData,
    array $itemsMasterList,
    array $containerDimensionsMap,
    array &$currentPlacementState, // Pass by reference
    int &$stepCounter,             // Pass by reference
    bool $enableRearrangement
): array {
    // ... (same V9 implementation with fallback logic) ...
    $currentItemId = $itemToPlaceData['itemId'] ?? null;
    if ($currentItemId === null || !isset($itemsMasterList[$currentItemId])) { /* ... handle error ... */ return ['success' => false, 'reason' => 'Invalid item data.', /* ... */]; }
    $itemMasterData = $itemsMasterList[$currentItemId];
    $itemDimensionsApi = $itemMasterData['dimensions_api'] ?? null;
    $currentItemPriority = $itemMasterData['priority'];
    $preferredContainerIdSpecific = $itemMasterData['preferredContainerId']; $preferredZone = $itemMasterData['preferredZone'];
    if ($preferredContainerIdSpecific === '') $preferredContainerIdSpecific = null; if ($preferredZone === '') $preferredZone = null;
    if (!$itemDimensionsApi || ($itemDimensionsApi['width'] ?? 0) <= 0) { /* ... handle error ... */ return ['success' => false, 'reason' => "Invalid dimensions for $currentItemId.", /* ... */]; }
    $tier = ($currentItemPriority >= HIGH_PRIORITY_THRESHOLD) ? 'High' : (($currentItemPriority <= LOW_PRIORITY_THRESHOLD) ? 'Low' : 'Medium');
    error_log("placeSingleItem Processing Item $currentItemId (Priority: $currentItemPriority, Tier: $tier, PrefZone: " . ($preferredZone ?? 'None') . ", PrefCont: " . ($preferredContainerIdSpecific ?? 'None') . ", RearrEnabled: ".($enableRearrangement?'Yes':'No').")");

    // --- Determine Container Search Order ---
    $containersToTryIds = []; $processedIds = [];
    if ($preferredContainerIdSpecific !== null && isset($containerDimensionsMap[$preferredContainerIdSpecific])) { $containersToTryIds[] = $preferredContainerIdSpecific; $processedIds[$preferredContainerIdSpecific] = true; }
    if ($preferredZone !== null) { foreach ($containerDimensionsMap as $cId => $cData) { if (!isset($processedIds[$cId]) && ($cData['zone'] ?? null) === $preferredZone) { $containersToTryIds[] = $cId; $processedIds[$cId] = true; } } }
    elseif ($preferredContainerIdSpecific !== null && isset($containerDimensionsMap[$preferredContainerIdSpecific]['zone'])) { $specificContainerZone = $containerDimensionsMap[$preferredContainerIdSpecific]['zone']; if ($specificContainerZone !== null) { foreach ($containerDimensionsMap as $cId => $cData) { if (!isset($processedIds[$cId]) && ($cData['zone'] ?? null) === $specificContainerZone) { $containersToTryIds[] = $cId; $processedIds[$cId] = true; } } } }
    foreach ($containerDimensionsMap as $cId => $cData) { if (!isset($processedIds[$cId])) { $containersToTryIds[] = $cId; } }

    // --- Attempt Direct Placement - Find BEST *OVERALL* spot ---
    $bestOverallPlacementData = null; $bestOverallAdjustedScore = null; $bestOverallContainerId = null; $idealSpotBlockedInPreferred = false;
    foreach ($containersToTryIds as $containerId) {
        if (!isset($containerDimensionsMap[$containerId])) continue;
        $containerDimensionsApi = $containerDimensionsMap[$containerId];
        $itemsCurrentlyInContainer = $currentPlacementState[$containerId] ?? [];
        $containerZone = $containerDimensionsMap[$containerId]['zone'] ?? null;
        $placementInThisContainer = findSpaceForItem( $currentItemId, $itemDimensionsApi, $currentItemPriority, $containerId, $containerDimensionsApi, $itemsCurrentlyInContainer );
        if ($placementInThisContainer !== null) {
             $geometricScore = $placementInThisContainer['score']; $adjustedScore = $geometricScore;
             $isCurrentContainerPreferred = false;
             if ($preferredContainerIdSpecific !== null) { if ($preferredContainerIdSpecific === $containerId) $isCurrentContainerPreferred = true; }
             elseif ($preferredZone !== null) { if ($containerZone === $preferredZone) $isCurrentContainerPreferred = true; }
             else { $isCurrentContainerPreferred = true; }
             if (!$isCurrentContainerPreferred) { $adjustedScore += PREFERRED_ZONE_SCORE_PENALTY; }
             else { if ($geometricScore > FLOAT_EPSILON) $idealSpotBlockedInPreferred = true; }
             if ($bestOverallAdjustedScore === null || $adjustedScore < $bestOverallAdjustedScore) { $bestOverallAdjustedScore = $adjustedScore; $bestOverallPlacementData = $placementInThisContainer; $bestOverallContainerId = $containerId; }
        }
    }

    // --- Process Best Found Spot (Decision Logic) ---
    $triggerRearrangement = false; $placeDirectly = false; $rearrangementResult = null;
    if ($bestOverallPlacementData !== null) {
        $chosenContainerId = $bestOverallContainerId; $chosenContainerZone = $containerDimensionsMap[$chosenContainerId]['zone'] ?? null;
        $isChosenSpotPreferred = false;
        if ($preferredContainerIdSpecific !== null) { if ($preferredContainerIdSpecific === $chosenContainerId) $isChosenSpotPreferred = true; }
        elseif ($preferredZone !== null) { if ($chosenContainerZone === $preferredZone) $isChosenSpotPreferred = true; }
        else { $isChosenSpotPreferred = true; }

        if ($tier === 'High' && $enableRearrangement) {
            if (!$isChosenSpotPreferred || $idealSpotBlockedInPreferred) { $triggerRearrangement = true; error_log("Item $currentItemId (High Prio): Rearrangement Triggered (SpotPref:".($isChosenSpotPreferred?'Y':'N').",IdealBlocked:".($idealSpotBlockedInPreferred?'Y':'N').")"); }
            else { $placeDirectly = true; error_log("Item $currentItemId (High Prio): Best spot preferred/ideal. Place directly."); }
        } else { $placeDirectly = true; error_log("Item $currentItemId ($tier Prio): Place directly."); }
    } else {
        error_log("Item $currentItemId: No direct spot found.");
        if ($tier === 'High' && $enableRearrangement && ($preferredContainerIdSpecific !== null || $preferredZone !== null)) { $triggerRearrangement = true; error_log("Item $currentItemId (High Prio): Triggering rearrange (no spot found)."); }
    }

    // --- Perform Action ---
    if ($placeDirectly) {
        $foundX = (float)$bestOverallPlacementData['foundX']; $foundY = (float)$bestOverallPlacementData['foundY']; $foundZ = (float)$bestOverallPlacementData['foundZ'];
        $placedW = (float)$bestOverallPlacementData['placedW']; $placedD = (float)$bestOverallPlacementData['placedD']; $placedH = (float)$bestOverallPlacementData['placedH'];
        $chosenContainerId = $bestOverallContainerId;
        error_log("Item $currentItemId: Placing directly in $chosenContainerId at (Y=$foundY, Z=$foundZ, X=$foundX).");
        if (!isset($currentPlacementState[$chosenContainerId])) { $currentPlacementState[$chosenContainerId] = []; }
        $currentPlacementState[$chosenContainerId][] = [ 'id' => $currentItemId, 'x' => $foundX, 'y' => $foundY, 'z' => $foundZ, 'w' => $placedW, 'd' => $placedD, 'h' => $placedH ];
        $currentPlacementState[$chosenContainerId] = array_values($currentPlacementState[$chosenContainerId]);
        return [ 'success' => true, 'reason' => 'Placed directly.', 'placement' => ['itemId' => $currentItemId, 'containerId' => $chosenContainerId, 'position' => formatApiPosition($foundX, $foundY, $foundZ, $placedW, $placedD, $placedH)], 'rearrangementResult' => null, 'dbUpdate' => ['action' => 'place', 'itemId' => $currentItemId, 'containerId' => $chosenContainerId, 'positionX' => $foundX, 'positionY' => $foundY, 'positionZ' => $foundZ, 'placedDimensionW' => $placedW, 'placedDimensionD' => $placedD, 'placedDimensionH' => $placedH ] ];
    } elseif ($triggerRearrangement) {
        error_log("Item $currentItemId (High Prio): Calling attemptRearrangement...");
        $rearrangementResult = attemptRearrangementForHighPriorityItem( $currentItemId, $itemDimensionsApi, $currentItemPriority, $preferredContainerIdSpecific, $preferredZone, $containerDimensionsMap, $currentPlacementState, $itemsMasterList, $stepCounter );
        if ($rearrangementResult['success']) {
            error_log("Item $currentItemId: Rearrangement successful.");
            $currentPlacementState = $rearrangementResult['newState']; $stepCounter = $rearrangementResult['nextStep'];
            return [ 'success' => true, 'reason' => 'Placed via rearrangement.', 'placement' => $rearrangementResult['finalPlacement']['placementResponse'], 'rearrangementResult' => $rearrangementResult, 'dbUpdate' => null ];
        } else {
            error_log("Item $currentItemId: Rearrangement FAILED. Reason: " . ($rearrangementResult['reason'] ?? 'Unknown'));
            // --- START: FALLBACK LOGIC ---
            $fallbackPossible = false; $fallbackReason = "";
            if ($bestOverallPlacementData !== null) {
                $fallbackContainerId = $bestOverallContainerId; $fallbackContainerZone = $containerDimensionsMap[$fallbackContainerId]['zone'] ?? null;
                $isFallbackSpotPreferred = false;
                if ($preferredContainerIdSpecific !== null) { if ($preferredContainerIdSpecific === $fallbackContainerId) $isFallbackSpotPreferred = true; }
                elseif ($preferredZone !== null) { if ($fallbackContainerZone === $preferredZone) $isFallbackSpotPreferred = true; }
                else { $isFallbackSpotPreferred = true; }
                if ($isFallbackSpotPreferred) { error_log("Item $currentItemId: Attempting fallback in $fallbackContainerId"); $fallbackPossible = true; }
                else { $fallbackReason = "Original best spot ($fallbackContainerId) not preferred."; error_log("Item $currentItemId: Not falling back: $fallbackReason"); }
            } else { $fallbackReason = "No initial spot found."; error_log("Item $currentItemId: Cannot fallback: $fallbackReason"); }
            if ($fallbackPossible) {
                $foundX = (float)$bestOverallPlacementData['foundX']; $foundY = (float)$bestOverallPlacementData['foundY']; $foundZ = (float)$bestOverallPlacementData['foundZ']; $placedW = (float)$bestOverallPlacementData['placedW']; $placedD = (float)$bestOverallPlacementData['placedD']; $placedH = (float)$bestOverallPlacementData['placedH'];
                error_log("Item $currentItemId: Placing via fallback in $fallbackContainerId at (Y=$foundY, Z=$foundZ, X=$foundX).");
                if (!isset($currentPlacementState[$fallbackContainerId])) { $currentPlacementState[$fallbackContainerId] = []; }
                $currentPlacementState[$fallbackContainerId][] = [ 'id' => $currentItemId, 'x' => $foundX, 'y' => $foundY, 'z' => $foundZ, 'w' => $placedW, 'd' => $placedD, 'h' => $placedH ];
                $currentPlacementState[$fallbackContainerId] = array_values($currentPlacementState[$fallbackContainerId]);
                return [ 'success' => true, 'reason' => 'Placed via fallback after failed rearrangement.', 'placement' => ['itemId' => $currentItemId, 'containerId' => $fallbackContainerId, 'position' => formatApiPosition($foundX, $foundY, $foundZ, $placedW, $placedD, $placedH)], 'rearrangementResult' => $rearrangementResult, 'dbUpdate' => ['action' => 'place', 'itemId' => $currentItemId, 'containerId' => $fallbackContainerId, 'positionX' => $foundX, 'positionY' => $foundY, 'positionZ' => $foundZ, 'placedDimensionW' => $placedW, 'placedDimensionD' => $placedD, 'placedDimensionH' => $placedH ] ];
            } else {
                 return ['success' => false, 'reason' => ($rearrangementResult['reason'] ?? 'Rearrangement failed.') . ($fallbackReason ? " Fallback failed: $fallbackReason" : ''), 'placement' => null, 'rearrangementResult' => $rearrangementResult, 'dbUpdate' => null];
            }
            // --- END: FALLBACK LOGIC ---
        }
    } else {
        return ['success' => false, 'reason' => 'No placement space found and rearrangement/fallback not applicable.', 'placement' => null, 'rearrangementResult' => null, 'dbUpdate' => null];
    }
}


// --- NEW HELPER FUNCTION for Refinement Pass ---
/**
 * Checks if an item (given its API dimensions) can fit exactly into a target box
 * using one of its orientations.
 *
 * @param array $itemApiDims Dimensions ('width', 'depth', 'height') of the item.
 * @param array $targetBox   Placement box details ('w', 'd', 'h') to fit into.
 * @return ?array Returns the dimensions ['w', 'd', 'h'] of the fitting orientation, or null if none fit.
 */
function findFittingOrientation(array $itemApiDims, array $targetBox): ?array {
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


// --- NEW Refinement Pass Function ---
/**
 * Post-processing pass to identify and perform direct swaps between
 * higher-priority items placed behind lower-priority items.
 *
 * @param array &$placementState      Current placement state (passed by reference, will be modified).
 * @param array $itemsMasterList      Master list with item details (priority, dimensions).
 * @param int   &$stepCounter         Current step counter (passed by reference, will be incremented).
 * @return array Structure containing:
 *               'swapApiMoves' => array (API move instructions for swaps performed)
 *               'swapDbUpdates' => array (DB update instructions for swapped items [itemId => dbUpdate])
 */
function refinePlacementsBySwapping(
    array &$placementState, // Modifies state directly
    array $itemsMasterList,
    int &$stepCounter       // Modifies step counter
): array {
    error_log("--- Starting Refinement Swap Pass ---");
    $swapApiMoves = [];
    $swapDbUpdates = [];
    $swappedItemIdsThisPass = []; // Prevent swapping same item back and forth within the pass

    // Iterate through each container in the current placement state
    foreach ($placementState as $containerId => $itemsInContainer) {
        $itemCount = count($itemsInContainer);
        if ($itemCount < 2) continue; // Need at least two items to swap

        error_log("Refine Pass: Checking container $containerId with $itemCount items.");

        // Use nested loops to compare all unique pairs (indices i, j where j > i)
        for ($i = 0; $i < $itemCount; $i++) {
            for ($j = $i + 1; $j < $itemCount; $j++) {
                // Get current placement data directly from the (potentially modified) state
                $itemA_pos = $placementState[$containerId][$i]; // Item at index i
                $itemB_pos = $placementState[$containerId][$j]; // Item at index j
                $itemA_id = $itemA_pos['id'];
                $itemB_id = $itemB_pos['id'];

                 // Skip if either item was already involved in a swap in this pass to avoid potential cycles/redundancy
                 if (isset($swappedItemIdsThisPass[$itemA_id]) || isset($swappedItemIdsThisPass[$itemB_id])) {
                    // error_log("Refine Pass: Skipping pair ($itemA_id, $itemB_id), one already swapped.");
                    continue;
                 }

                // Get priorities and original dimensions from master list
                $itemA_details = $itemsMasterList[$itemA_id] ?? null;
                $itemB_details = $itemsMasterList[$itemB_id] ?? null;
                if (!$itemA_details || !$itemB_details || !$itemA_details['dimensions_api'] || !$itemB_details['dimensions_api']) {
                    error_log("Refine Pass WARN: Missing details or dimensions for pair ($itemA_id, $itemB_id). Skipping.");
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
                // 1. Is LP physically in front? (Lower Y coordinate)
                if ($lpItem_pos['y'] >= $hpItem_pos['y'] - FLOAT_EPSILON) {
                    continue; // LP is not in front of HP
                }

                // 2. Do their XZ projections overlap? (Check if LP is within HP's "retrieval shaft")
                $xOverlap = ($lpItem_pos['x'] < $hpItem_pos['x'] + $hpItem_pos['w'] - FLOAT_EPSILON) &&
                            ($lpItem_pos['x'] + $lpItem_pos['w'] > $hpItem_pos['x'] + FLOAT_EPSILON);
                $zOverlap = ($lpItem_pos['z'] < $hpItem_pos['z'] + $hpItem_pos['h'] - FLOAT_EPSILON) &&
                            ($lpItem_pos['z'] + $lpItem_pos['h'] > $hpItem_pos['z'] + FLOAT_EPSILON);

                if (!$xOverlap || !$zOverlap) {
                    // error_log("Refine Pass: Pair ($hpItem_id, $lpItem_id): LP in front but XZ projections do not overlap."); // Verbose
                    continue; // LP is in front but not directly blocking the path based on XZ overlap
                }

                // --- Candidate Found: Check if direct swap is possible ---
                error_log("Refine Pass: Candidate found in $containerId: HP=$hpItem_id (Y={$hpItem_pos['y']}) blocked by LP=$lpItem_id (Y={$lpItem_pos['y']}). Checking swap feasibility...");

                // Can HP fit into LP's current box?
                $fittingOrientationHP = findFittingOrientation($hpItem_details['dimensions_api'], $lpItem_pos);
                // Can LP fit into HP's current box?
                $fittingOrientationLP = findFittingOrientation($lpItem_details['dimensions_api'], $hpItem_pos);

                if ($fittingOrientationHP !== null && $fittingOrientationLP !== null) {
                    // --- Swap is Possible! ---
                    error_log("Refine Pass: SWAP POSSIBLE between $hpItem_id and $lpItem_id in $containerId.");

                    // 1. Generate API Move Instructions (BEFORE modifying state)
                    $moveHP_api = [
                        'step' => $stepCounter++,
                        'action' => 'move', 'itemId' => $hpItem_id,
                        'fromContainer' => $containerId, 'fromPosition' => formatApiPosition($hpItem_pos['x'], $hpItem_pos['y'], $hpItem_pos['z'], $hpItem_pos['w'], $hpItem_pos['d'], $hpItem_pos['h']),
                        'toContainer' => $containerId, 'toPosition' => formatApiPosition($lpItem_pos['x'], $lpItem_pos['y'], $lpItem_pos['z'], $fittingOrientationHP['w'], $fittingOrientationHP['d'], $fittingOrientationHP['h']) // Use LP's coords, HP's fitting dims
                    ];
                    $moveLP_api = [
                        'step' => $stepCounter++,
                        'action' => 'move', 'itemId' => $lpItem_id,
                        'fromContainer' => $containerId, 'fromPosition' => formatApiPosition($lpItem_pos['x'], $lpItem_pos['y'], $lpItem_pos['z'], $lpItem_pos['w'], $lpItem_pos['d'], $lpItem_pos['h']),
                        'toContainer' => $containerId, 'toPosition' => formatApiPosition($hpItem_pos['x'], $hpItem_pos['y'], $hpItem_pos['z'], $fittingOrientationLP['w'], $fittingOrientationLP['d'], $fittingOrientationLP['h']) // Use HP's coords, LP's fitting dims
                    ];
                    $swapApiMoves[] = $moveHP_api;
                    $swapApiMoves[] = $moveLP_api;

                    // 2. Prepare DB Update Instructions
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
                    $swapDbUpdates[$hpItem_id] = $dbUpdateHP; // Overwrite previous DB update if any
                    $swapDbUpdates[$lpItem_id] = $dbUpdateLP; // Overwrite previous DB update if any

                    // 3. Update the Placement State (critical: modify the array being iterated over)
                    // Find original indices again just to be safe, though $i and $j should be correct
                    $idx_hp = ($hpItem_id === $itemA_id) ? $i : $j;
                    $idx_lp = ($lpItem_id === $itemA_id) ? $i : $j;

                    // Update HP item's position in the state array
                    $placementState[$containerId][$idx_hp]['x'] = $dbUpdateHP['positionX'];
                    $placementState[$containerId][$idx_hp]['y'] = $dbUpdateHP['positionY'];
                    $placementState[$containerId][$idx_hp]['z'] = $dbUpdateHP['positionZ'];
                    $placementState[$containerId][$idx_hp]['w'] = $dbUpdateHP['placedDimensionW'];
                    $placementState[$containerId][$idx_hp]['d'] = $dbUpdateHP['placedDimensionD'];
                    $placementState[$containerId][$idx_hp]['h'] = $dbUpdateHP['placedDimensionH'];

                     // Update LP item's position in the state array
                    $placementState[$containerId][$idx_lp]['x'] = $dbUpdateLP['positionX'];
                    $placementState[$containerId][$idx_lp]['y'] = $dbUpdateLP['positionY'];
                    $placementState[$containerId][$idx_lp]['z'] = $dbUpdateLP['positionZ'];
                    $placementState[$containerId][$idx_lp]['w'] = $dbUpdateLP['placedDimensionW'];
                    $placementState[$containerId][$idx_lp]['d'] = $dbUpdateLP['placedDimensionD'];
                    $placementState[$containerId][$idx_lp]['h'] = $dbUpdateLP['placedDimensionH'];

                    // Mark these items as swapped in this pass
                    $swappedItemIdsThisPass[$hpItem_id] = true;
                    $swappedItemIdsThisPass[$lpItem_id] = true;

                     // Since we modified the array, the indices $i and $j might now point to
                     // different items if swaps occurred earlier in the inner loop.
                     // To keep it simple for V1, we accept this limitation and continue.
                     // A more robust solution might store swap pairs and apply them after iterating.
                     error_log("Refine Pass: State updated for swap ($hpItem_id <-> $lpItem_id)");

                } else {
                     error_log("Refine Pass: Swap NOT possible between $hpItem_id and $lpItem_id (items do not fit in swapped locations). HP fits? ".($fittingOrientationHP?'Yes':'No').". LP fits? ".($fittingOrientationLP?'Yes':'No').".");
                }
            } // end inner loop j
        } // end outer loop i
    } // end container loop

    error_log("--- Finished Refinement Swap Pass. Found " . count($swapApiMoves) / 2 . " potential swaps. ---");
    return [
        'swapApiMoves' => $swapApiMoves,
        'swapDbUpdates' => $swapDbUpdates,
        // placementState was modified by reference
    ];
}


// #########################################################################
// ## END: Core Helper Functions                                         ##
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
try { /* ... (same DB load logic as V9) ... */
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
foreach ($itemsToPlaceInput as $item) { /* ... (same merge logic as V9, ensuring dimensions_api is valid) ... */
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
$rearrangementSteps = [];  // Collect rearrangement API steps (moves + place)
$finalPlacements = [];     // Collect successful placements [itemId => placementResponse]
$stepCounter = 1;
$processedItemIds = [];

// --- Filter Items To Be Processed ---
$itemsToProcess = [];
foreach ($itemsToPlaceInput as $item) { /* ... (same filtering logic as V9) ... */
     if (!isset($item['itemId'])) continue; $itemId = $item['itemId'];
     if (!isset($itemsMasterList[$itemId])) { error_log("Item $itemId from input missing in master. Skipping."); continue; }
     if ($itemsMasterList[$itemId]['placement'] === null) {
          if($itemsMasterList[$itemId]['dimensions_api'] === null) { error_log("Item $itemId needs placement but has invalid dims."); $internalErrors[] = ['itemId' => $itemId, 'reason' => 'Invalid dimensions.']; }
          else { $itemsToProcess[] = $item; }
     } else { $processedItemIds[$itemId] = true; }
}
error_log("Items requiring placement processing: " . count($itemsToProcess));

// --- Sort Items To Be Processed ---
if (!empty($itemsToProcess)) { /* ... (same sorting logic as V9) ... */
    error_log("Sorting " . count($itemsToProcess) . " items...");
    usort($itemsToProcess, function($a, $b) use ($itemsMasterList) {
         $priorityA = $itemsMasterList[$a['itemId']]['priority'] ?? 0; $priorityB = $itemsMasterList[$b['itemId']]['priority'] ?? 0;
         if ($priorityA !== $priorityB) { return $priorityB <=> $priorityA; }
         $dimsA = $itemsMasterList[$a['itemId']]['dimensions_api']; $dimsB = $itemsMasterList[$b['itemId']]['dimensions_api'];
         $volumeA = ($dimsA !== null) ? (($dimsA['width'] ?? 0) * ($dimsA['depth'] ?? 0) * ($dimsA['height'] ?? 0)) : 0;
         $volumeB = ($dimsB !== null) ? (($dimsB['width'] ?? 0) * ($dimsB['depth'] ?? 0) * ($dimsB['height'] ?? 0)) : 0;
         if (abs($volumeA - $volumeB) > FLOAT_EPSILON) { return $volumeB <=> $volumeA; }
         return ($a['itemId'] ?? '') <=> ($b['itemId'] ?? '');
    });
     error_log("Items sorted. First: " . ($itemsToProcess[0]['itemId'] ?? 'None'));
}

// --- Split Sorted Items into Priority Tiers ---
$highPrioItems = []; $mediumPrioItems = []; $lowPrioItems = [];
foreach ($itemsToProcess as $item) { /* ... (same tier splitting logic as V9) ... */
    $itemId = $item['itemId']; $prio = $itemsMasterList[$itemId]['priority'];
    if ($prio >= HIGH_PRIORITY_THRESHOLD) { $highPrioItems[] = $item; }
    elseif ($prio <= LOW_PRIORITY_THRESHOLD) { $lowPrioItems[] = $item; }
    else { $mediumPrioItems[] = $item; }
}
error_log("Split into tiers: High=" . count($highPrioItems) . ", Medium=" . count($mediumPrioItems) . ", Low=" . count($lowPrioItems));

// --- Placement Pass 1: High Priority (Rearrangement Enabled) ---
error_log("--- Starting High Priority Placement Pass ---");
foreach ($highPrioItems as $itemData) { /* ... (same pass 1 logic as V9) ... */
    $itemId = $itemData['itemId']; if (isset($processedItemIds[$itemId])) continue;
    $result = placeSingleItem( $itemData, $itemsMasterList, $containerDimensionsMap, $currentPlacementState, $stepCounter, true ); // Rearr ON
    $processedItemIds[$itemId] = true;
    if ($result['success']) {
        $finalPlacements[$itemId] = $result['placement'];
        if ($result['dbUpdate']) { $dbUpdates[$itemId] = $result['dbUpdate']; error_log("Item $itemId (High Prio): Placed OK (Direct/Fallback). Reason: " . $result['reason']); }
        if ($result['rearrangementResult'] && $result['rearrangementResult']['success']) {
            error_log("Item $itemId (High Prio): Placed OK (Rearrangement). Reason: " . $result['reason']);
            if (!empty($result['rearrangementResult']['moves'])) { foreach ($result['rearrangementResult']['moves'] as $move) { $rearrangementSteps[] = $move['apiResponse']; $dbUpdates[$move['itemId']] = $move['dbUpdate']; } }
            if(isset($result['rearrangementResult']['finalPlacement']['apiResponse'])) { $rearrangementSteps[] = $result['rearrangementResult']['finalPlacement']['apiResponse']; }
            if(isset($result['rearrangementResult']['finalPlacement']['dbUpdate'])) { $dbUpdates[$itemId] = $result['rearrangementResult']['finalPlacement']['dbUpdate']; }
        }
    } else { error_log("Item $itemId (High Prio): Placement FAILED. Reason: " . $result['reason']); $internalErrors[] = ['itemId' => $itemId, 'reason' => $result['reason']]; }
}

// --- Placement Pass 2: Medium Priority (Rearrangement Disabled) ---
error_log("--- Starting Medium Priority Placement Pass ---");
foreach ($mediumPrioItems as $itemData) { /* ... (same pass 2 logic as V9) ... */
     $itemId = $itemData['itemId']; if (isset($processedItemIds[$itemId])) continue;
     $result = placeSingleItem( $itemData, $itemsMasterList, $containerDimensionsMap, $currentPlacementState, $stepCounter, false ); // Rearr OFF
     $processedItemIds[$itemId] = true;
     if ($result['success']) {
         $finalPlacements[$itemId] = $result['placement'];
         if ($result['dbUpdate']) { $dbUpdates[$itemId] = $result['dbUpdate']; error_log("Item $itemId (Medium Prio): Placed OK. Reason: " . $result['reason']); }
         else { error_log("Item $itemId (Medium Prio): Success but no DB update? Reason: " . $result['reason']); $internalErrors[] = ['itemId' => $itemId, 'reason' => 'Success but no DB update']; }
     } else { error_log("Item $itemId (Medium Prio): Placement FAILED. Reason: " . $result['reason']); $internalErrors[] = ['itemId' => $itemId, 'reason' => $result['reason']]; }
}

// --- Placement Pass 3: Low Priority (Rearrangement Disabled) ---
error_log("--- Starting Low Priority Placement Pass ---");
foreach ($lowPrioItems as $itemData) { /* ... (same pass 3 logic as V9) ... */
     $itemId = $itemData['itemId']; if (isset($processedItemIds[$itemId])) continue;
     $result = placeSingleItem( $itemData, $itemsMasterList, $containerDimensionsMap, $currentPlacementState, $stepCounter, false ); // Rearr OFF
     $processedItemIds[$itemId] = true;
      if ($result['success']) {
         $finalPlacements[$itemId] = $result['placement'];
          if ($result['dbUpdate']) { $dbUpdates[$itemId] = $result['dbUpdate']; error_log("Item $itemId (Low Prio): Placed OK. Reason: " . $result['reason']); }
          else { error_log("Item $itemId (Low Prio): Success but no DB update? Reason: " . $result['reason']); $internalErrors[] = ['itemId' => $itemId, 'reason' => 'Success but no DB update']; }
     } else { error_log("Item $itemId (Low Prio): Placement FAILED. Reason: " . $result['reason']); $internalErrors[] = ['itemId' => $itemId, 'reason' => $result['reason']]; }
}

// --- V10 Addition: Refinement Swap Pass ---
$refinementResult = refinePlacementsBySwapping(
    $currentPlacementState, // Pass by reference
    $itemsMasterList,
    $stepCounter            // Pass by reference
);

// Merge results from refinement pass
if (!empty($refinementResult['swapApiMoves'])) {
    $rearrangementSteps = array_merge($rearrangementSteps, $refinementResult['swapApiMoves']);
}
if (!empty($refinementResult['swapDbUpdates'])) {
    // Merge swap DB updates, potentially overwriting previous updates for swapped items
    foreach ($refinementResult['swapDbUpdates'] as $itemId => $updateData) {
        $dbUpdates[$itemId] = $updateData;
    }
    error_log("Refinement Pass: Merged " . count($refinementResult['swapDbUpdates']) . " DB updates from swaps.");
}
// Update $finalPlacements if items were swapped?
// The $finalPlacements array holds the *initial* successful placement.
// The $rearrangementSteps now includes the *moves* to the final swapped positions.
// The $dbUpdates holds the *final* state for the DB.
// This seems consistent. The 'placements' response shows where things initially went,
// and 'rearrangements' shows the moves (including swaps) to get to the final state.


// Final results for response object
$response['placements'] = array_values($finalPlacements);
$response['rearrangements'] = $rearrangementSteps;


// --- Update Database ---
$updatedCount = 0; $dbUpdateFailed = false; $actuallyUpdatedIds = [];
if (!empty($dbUpdates)) { /* ... (same DB update logic as V9) ... */
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
        if (!empty($response['placements']) || !empty($response['rearrangements'])) { $response['success'] = true; }
        else if (count($itemsToProcess) === 0 && empty($internalErrors)) { $response['success'] = true; $response['message'] = "No items required placement."; }
        else { $response['success'] = false; $response['message'] = "Placement completed, but no items placed/rearranged."; }

    } catch (PDOException $e) {
         if ($db->inTransaction()) { $db->rollBack(); error_log("DB Update ROLLED BACK."); }
         http_response_code(500); $response['success'] = false; $response['placements'] = []; $response['rearrangements'] = [];
         $response['message'] = "DB update failed. Transaction rolled back."; error_log("Placement DB Error (update): " . $e->getMessage()); $dbUpdateFailed = true;
    }
} else {
     // No DB updates needed - set success based on results
     if (!empty($response['placements']) || !empty($response['rearrangements'])) { $response['success'] = true; }
     else if (count($itemsToProcess) === 0 && empty($internalErrors)) { $response['success'] = true; $response['message'] = $response['message'] ?? "No items required placement."; }
     else { $response['success'] = false; $response['message'] = $response['message'] ?? "Placement completed, but no items placed/rearranged."; }
     error_log("No DB updates needed.");
}


// --- Finalize and Echo Response ---
if (!$dbUpdateFailed) { /* ... (same finalization logic as V9) ... */
     $attemptedCount = count($itemsToProcess); $placedCount = count($response['placements'] ?? []);
     $swapCount = count($refinementResult['swapApiMoves'] ?? []) / 2; // Each swap adds 2 moves

     if ($response['success']) {
         if ($attemptedCount > 0 && $placedCount < $attemptedCount) {
             http_response_code(207); $response['message'] = $response['message'] ?? "Placement partially successful. Placed: $placedCount/" . $attemptedCount . ".";
         } else {
              http_response_code(200); $response['message'] = $response['message'] ?? ($attemptedCount > 0 ? "Placement successful." : "No items required placement.");
         }
          if (!empty($response['rearrangements'])) {
              $moveCount = 0; foreach($response['rearrangements'] as $step) { if($step['action'] === 'move') $moveCount++; }
              $response['message'] .= " Includes " . count($response['rearrangements']) . " rearrangement/swap steps (" . $moveCount . " moves, " . $swapCount . " swaps).";
          }
     } else {
          if (http_response_code() < 400) { http_response_code(422); }
          $response['message'] = $response['message'] ?? "Placement failed.";
     }
     if (!empty($internalErrors)) { $response['warnings'] = $internalErrors; if($response['success'] && $attemptedCount > 0 && $placedCount < $attemptedCount) { $response['message'] .= " See warnings."; } elseif(!$response['success']) { $response['message'] .= " See warnings for details."; } }
}

// --- Logging Summary ---
$finalResponseSuccess = $response['success'] ?? false; $finalHttpMessage = $response['message'] ?? null;
$finalDbUpdatesAttempted = count($dbUpdates); $finalPlacedCount = count($response['placements'] ?? []);
$finalRearrangementCount = count($response['rearrangements'] ?? []); $finalWarningCount = count($response['warnings'] ?? $internalErrors);
$finalSwapCount = count($refinementResult['swapApiMoves'] ?? []) / 2;

try { /* ... (same logging logic as V9, maybe add swap count) ... */
    if ($db) {
        $logSql = "INSERT INTO logs (userId, actionType, detailsJson, timestamp) VALUES (:userId, :actionType, :details, :timestamp)"; $logStmt = $db->prepare($logSql);
        $logDetails = [
             'operationType' => 'placement', 'algorithm' => PLACEMENT_ALGORITHM_NAME,
             'requestInputItemCount' => count($itemsToPlaceInput), 'itemsAttemptedProcessing' => count($itemsToProcess),
             'responseSuccess' => $finalResponseSuccess, 'httpStatusCode' => http_response_code(),
             'itemsPlacedCount' => $finalPlacedCount,
             'dbUpdatesAttempted' => $finalDbUpdatesAttempted, 'dbUpdatesSuccessful' => $updatedCount,
             'rearrangementStepsCount' => $finalRearrangementCount, 'swapRefinementCount' => $finalSwapCount, // Added swap count
             'warningsOrErrorsCount' => $finalWarningCount, 'finalMessage' => $finalHttpMessage
         ];
        $logParams = [ ':userId' => 'System_PlacementAPI_V10RS', ':actionType' => 'placement_v10_rs', ':details' => json_encode($logDetails, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), ':timestamp' => date(DB_DATETIME_FORMAT) ];
        if (!$logStmt->execute($logParams)) { error_log("CRITICAL: Failed to execute placement summary log query!"); }
    }
 } catch (Exception $logEx) { error_log("CRITICAL: Failed during placement summary logging! Error: " . $logEx->getMessage()); }

// --- Send Output ---
if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
$finalResponsePayload = [ 'success' => $response['success'], 'placements' => $response['placements'] ?? [], 'rearrangements' => $response['rearrangements'] ?? [] ];
if (isset($response['message'])) { $finalResponsePayload['message'] = $response['message']; }
if (!empty($response['warnings'])) { $finalResponsePayload['warnings'] = $response['warnings']; }
echo json_encode($finalResponsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
error_log(PLACEMENT_ALGORITHM_NAME . " Finished. HTTP Code: " . http_response_code() . ". Success: " . ($finalResponsePayload['success'] ? 'Yes' : 'No') . ". Placed: " . count($finalResponsePayload['placements']) . ". Rearr Steps: " . count($finalResponsePayload['rearrangements']) . " (Swaps: " . $finalSwapCount . "). Attempted: " . count($itemsToProcess) . ". DB Updates: $updatedCount/$finalDbUpdatesAttempted. Warnings: " . count($finalResponsePayload['warnings'] ?? []) . ".");
$db = null; exit();

?>