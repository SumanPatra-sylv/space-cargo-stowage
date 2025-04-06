<?php // backend/waste/return_plan.php (Option B: Manifest All Waste, Steps for Stowed)

require_once __DIR__ . '/../database.php';
$db = null;
$response = [ /* Initialize */ ];

// --- DB Connection ---
try { $db = getDbConnection(); if ($db === null) throw new Exception("DB Connection Failed", 503); }
catch (Exception $e) { /* Handle DB Error */ http_response_code($e->getCode() ?: 503); echo json_encode(['success' => false, 'message' => 'Database connection error.']); exit; }

// --- Input Handling & Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { /* Handle method error */ exit; }
$rawData = file_get_contents('php://input'); $requestData = json_decode($rawData, true);
// Validate required fields...
$requiredFields = ['undockingContainerId', 'undockingDate', 'maxWeight'];
foreach ($requiredFields as $field) { if (!isset($requestData[$field])) { /* Handle missing field error */ exit; } }
$undockingContainerId = trim($requestData['undockingContainerId']);
$undockingDate = trim($requestData['undockingDate']);
$maxWeight = $requestData['maxWeight'];
$userId = $requestData['userId'] ?? 'System_ReturnPlan';
// Validate values...
if (empty($undockingContainerId) || empty($undockingDate) || !is_numeric($maxWeight) || $maxWeight < 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $undockingDate)) {
    /* Handle invalid value error */ exit;
}
$maxWeight = (float)$maxWeight;
error_log("Return Plan request (Option B). Target: $undockingContainerId, Date: $undockingDate, Max Weight: $maxWeight");
// --- End Input Handling ---


try {
    // --- MODIFIED: Step 1: Identify ALL Waste Items (regardless of location) ---
    $sqlAllWaste = "SELECT
                    i.itemId, i.name, i.mass, i.priority, i.status, i.usageLimit, i.remainingUses, i.expiryDate,
                    i.containerId AS currentContainerId, -- Keep current location if exists
                    i.positionX AS dbPosX, i.positionY AS dbPosY, i.positionZ AS dbPosZ,
                    i.placedDimensionW AS dbPlacedW, i.placedDimensionD AS dbPlacedD, i.placedDimensionH AS dbPlacedH,
                    CASE
                        WHEN i.status = 'waste_expired' THEN 'Expired'
                        WHEN i.status = 'waste_depleted' THEN 'Out of Uses'
                        -- No need to recalculate here, just use status
                        ELSE 'Unknown Waste Status'
                    END as reason
                FROM items i
                WHERE
                    i.status IN ('waste_expired', 'waste_depleted') -- Find items ALREADY marked as waste
                ";
    $stmtAllWaste = $db->prepare($sqlAllWaste);
    $stmtAllWaste->execute();
    $allWasteItems = $stmtAllWaste->fetchAll(PDO::FETCH_ASSOC);
    error_log("Return Plan (Option B): Found " . count($allWasteItems) . " total items with waste status.");

    if (empty($allWasteItems)) {
        // Same response as before if no waste items exist at all
        $response['success'] = true; $response['message'] = "No waste items found in the system.";
        $response['returnManifest'] = ['undockingContainerId'=>$undockingContainerId, 'undockingDate'=>$undockingDate, 'returnItems'=>[], 'totalVolume'=>0, 'totalWeight'=>0];
        http_response_code(200); echo json_encode($response); $db = null; exit;
    }
    // --- End Identify All Waste ---


    // --- Step 2: Sort and Select ALL Waste Items based on Weight ---
    usort($allWasteItems, function($a, $b) { /* ... Same sort logic: priority asc, expiry asc ... */
        $prioA = $a['priority'] ?? 50; $prioB = $b['priority'] ?? 50; if ($prioA !== $prioB) { return $prioA <=> $prioB; }
        $expiryA = $a['expiryDate'] ?? '9999-12-31'; $expiryB = $b['expiryDate'] ?? '9999-12-31'; return strcmp($expiryA, $expiryB);
    });

    $selectedWasteItemsForManifest = []; // Items meeting weight limit for the manifest
    $selectedStowedWasteItems = [];    // Subset of selected items that are currently stowed
    $currentWeight = 0.0;
    $currentVolume = 0.0; // Volume only calculated for stowed items
    $epsilon = 0.001;

    foreach ($allWasteItems as $item) {
        $itemMass = (float)($item['mass'] ?? 0);
        if (($currentWeight + $itemMass) <= ($maxWeight + $epsilon)) {
            $selectedWasteItemsForManifest[] = $item; // Add to manifest list
            $currentWeight += $itemMass;

            // Check if it's stowed AND has dimensions to calculate volume/retrieval steps
            $isStowedWithDims = ($item['currentContainerId'] !== null &&
                                 $item['dbPosX'] !== null && // Check one pos coord
                                 isset($item['dbPlacedW'], $item['dbPlacedD'], $item['dbPlacedH']));

            if ($isStowedWithDims) {
                 $selectedStowedWasteItems[] = $item; // Add to list needing steps/volume calc
                 $itemVolume = (float)$item['dbPlacedW'] * (float)$item['dbPlacedD'] * (float)$item['dbPlacedH'];
                 $currentVolume += $itemVolume;
                 error_log("Return Plan (Option B): Selected STOWED item {$item['itemId']} (Mass: $itemMass). Current Weight: $currentWeight / $maxWeight");
            } else {
                  error_log("Return Plan (Option B): Selected FLOATING waste item {$item['itemId']} (Mass: $itemMass). Current Weight: $currentWeight / $maxWeight");
            }
        } else {
             error_log("Return Plan (Option B): Skipping item {$item['itemId']} (Mass: $itemMass) - exceeds max weight $maxWeight.");
        }
    }
    error_log("Return Plan (Option B): Selected " . count($selectedWasteItemsForManifest) . " items for manifest. Stowed items needing steps: " . count($selectedStowedWasteItems) . ". Total Weight: $currentWeight, Stowed Volume: $currentVolume");
    // --- End Selection ---


    // --- Step 3: Fetch All Locations (Only needed if stowed items were selected) ---
    $allItemsByContainer = [];
    if (!empty($selectedStowedWasteItems)) {
         try { $sqlAll = "SELECT itemId, name, containerId, positionX, positionY, positionZ, placedDimensionW, placedDimensionD, placedDimensionH FROM items WHERE containerId IS NOT NULL AND positionX IS NOT NULL";
             $stmtAll = $db->prepare($sqlAll); $stmtAll->execute(); $allItemsResult = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
             foreach ($allItemsResult as $item) { /* ... build $allItemsByContainer map using x,y,z,w,d,h keys ... */
                 $containerId = $item['containerId']; if (!isset($allItemsByContainer[$containerId])) { $allItemsByContainer[$containerId] = []; }
                 if (isset($item['itemId'], $item['name'], $item['positionX'], $item['positionY'], $item['positionZ'], $item['placedDimensionW'], $item['placedDimensionD'], $item['placedDimensionH'])) {
                     $allItemsByContainer[$containerId][$item['itemId']] = [ 'id' => $item['itemId'], 'name' => $item['name'], 'x' => (float)$item['positionX'], 'y' => (float)$item['positionY'], 'z' => (float)$item['positionZ'], 'w' => (float)$item['placedDimensionW'], 'd' => (float)$item['placedDimensionD'], 'h' => (float)$item['placedDimensionH'] ];
                 } else { error_log("Return Plan Warning: Missing data for item {$item['itemId']} during map build."); }
             }
             error_log("Return Plan (Option B): Built location map for " . count($allItemsResult) . " items.");
         } catch (PDOException $e) { throw new Exception("Error fetching item locations: " . $e->getMessage()); }
    }
    // --- End Fetch Locations ---


    // --- Step 4: Generate Retrieval & Return Plan Steps (ONLY for stowed waste) ---
    $combinedRetrievalSteps = [];
    $returnPlanSteps = []; // Move steps only for stowed items
    $masterStepCounter = 0;
    $planStepCounter = 0;

    // Iterate through the *stowed* waste items selected
    foreach ($selectedStowedWasteItems as $itemToRetrieve) {
        $targetItemId = $itemToRetrieve['itemId'];
        $containerId = $itemToRetrieve['currentContainerId'];

        // Generate Return Plan step for this stowed item
        $planStepCounter++;
        $returnPlanSteps[] = [ 'step' => $planStepCounter, 'itemId' => $targetItemId, 'itemName' => $itemToRetrieve['name'], 'fromContainer' => $containerId, 'toContainer' => $undockingContainerId ];

        // Generate Retrieval Steps for this stowed item
        $targetItemBox = $allItemsByContainer[$containerId][$targetItemId] ?? null;
        if ($targetItemBox === null) {
            error_log("Return Plan Warning: Missing location data for stowed waste item $targetItemId in $containerId.");
            $response['errors'][] = ['itemId' => $targetItemId, 'message' => 'Internal error generating retrieval steps (missing location).'];
            continue; // Skip retrieval steps for this one
        }
        // Find obstructions...
        $obstructionItemsData = []; $itemsInSameContainer = $allItemsByContainer[$containerId] ?? [];
        foreach ($itemsInSameContainer as $otherItemId => $otherItemBox) {
             if ($targetItemId === $otherItemId) continue;
             if ($otherItemBox['x'] > ($targetItemBox['x'] + $epsilon)) { if (doBoxesOverlapYZ($targetItemBox, $otherItemBox)) { $obstructionItemsData[] = [ 'boxData' => $otherItemBox, 'itemId' => $otherItemId, 'itemName' => $otherItemBox['name'] ]; } }
        }
        usort($obstructionItemsData, fn($a, $b) => $b['boxData']['x'] <=> $a['boxData']['x']);
        // Build retrieval steps...
        foreach ($obstructionItemsData as $obstructor) { $masterStepCounter++; $combinedRetrievalSteps[] = [ 'step' => $masterStepCounter, 'action' => 'remove', 'itemId' => $obstructor['itemId'], 'itemName' => $obstructor['itemName'], 'containerId' => $containerId, 'position' => formatPositionForApi($obstructor['boxData']) ]; }
        $masterStepCounter++; $combinedRetrievalSteps[] = [ 'step' => $masterStepCounter, 'action' => 'retrieve', 'itemId' => $targetItemId, 'itemName' => $itemToRetrieve['name'], 'containerId' => $containerId, 'position' => formatPositionForApi($targetItemBox) ];
        foreach (array_reverse($obstructionItemsData) as $obstructor) { $masterStepCounter++; $combinedRetrievalSteps[] = [ 'step' => $masterStepCounter, 'action' => 'placeBack', 'itemId' => $obstructor['itemId'], 'itemName' => $obstructor['itemName'], 'containerId' => $containerId, 'position' => formatPositionForApi($obstructor['boxData']) ]; }

    } // End foreach selectedStowedWasteItem
    // --- End Generate Steps ---


    // --- Step 5: Generate Manifest (Includes ALL selected waste) ---
    $manifestItems = [];
    foreach ($selectedWasteItemsForManifest as $item) {
        $manifestItems[] = [ 'itemId' => $item['itemId'], 'name' => $item['name'], 'reason' => $item['reason'] ];
    }

    $response['returnPlan'] = $returnPlanSteps; // Contains only moves for stowed items
    $response['returnManifest'] = [
        'undockingContainerId' => $undockingContainerId,
        'undockingDate' => $undockingDate,
        'returnItems' => $manifestItems, // Contains ALL selected waste (stowed or not)
        'totalVolume' => round($currentVolume, 3), // Volume only from stowed items
        'totalWeight' => round($currentWeight, 3)  // Weight from ALL selected items
    ];
    // --- End Manifest ---


    // --- Finalize Response ---
    $response['success'] = true;
    $response['retrievalSteps'] = $combinedRetrievalSteps; // Contains only steps for stowed items
    $response['message'] = "Return plan generated. Manifest includes " . count($manifestItems) . " items. " . count($returnPlanSteps) . " items require moving.";
    // Add warnings if items were skipped or encountered errors...
    if (!empty($response['errors'])) { $response['message'] .= " Some items encountered errors (see 'errors' array)."; }
    http_response_code(200);

} catch (Exception $e) {
    // ... (Keep existing general/PDO exception handling) ...
     http_response_code(500); $response['success'] = false; $response['message'] = 'Error generating return plan: ' . $e->getMessage(); error_log("Return Plan Exception: " . $e->getMessage());
     $response['returnPlan'] = []; $response['retrievalSteps'] = []; $response['returnManifest'] = null; if (empty($response['errors'])) { $response['errors'] = [['message' => $e->getMessage()]]; }
     if (!headers_sent()) { echo json_encode($response); } $db = null; exit;
} finally { $db = null; }


// --- Final Output ---
if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode($response); }
error_log("Return Plan API (Option B): Finished.");

// --- HELPER FUNCTIONS (Keep doBoxesOverlapYZ and formatPositionForApi) ---
function doBoxesOverlapYZ(array $box1, array $box2): bool { /* ... */ }
function formatPositionForApi(array $internalBoxData): array { /* ... */ }

?>