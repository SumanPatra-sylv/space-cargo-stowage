<?php // backend/api/search.php

ini_set('display_errors', 1); 
error_reporting(E_ALL); 

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); 
// No OPTIONS check needed for GET

// --- Database Connection ---
require_once __DIR__ . '/../database.php'; 
error_log("Search API: Attempting DB connection...");
$db = getDbConnection(); 

if ($db === null) {
    error_log("Search API: Failed DB connection.");
    http_response_code(500); 
    echo json_encode(['success' => false, 'found' => false, 'message' => 'Database connection failed.']);
    exit; 
}
error_log("Search API: DB connection successful.");
// --- End Database Connection --- 


// --- Response Structure ---
$response = [
    'success' => false, 
    'found' => false,
    'message' => ''
    // 'item' and 'retrievalSteps' added later
];


// --- Handle GET Request & Query Parameters ---
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); 
    $response['message'] = 'Invalid request method. Only GET is allowed.';
    error_log("Search API: Received method " . $_SERVER['REQUEST_METHOD']);
    echo json_encode($response);
    $db = null; exit; // Exit after sending response
}

$itemIdSearch = $_GET['itemId'] ?? null;
$itemNameSearch = $_GET['itemName'] ?? null;
$userId = $_GET['userId'] ?? null; 

if (empty($itemIdSearch) && empty($itemNameSearch)) {
    http_response_code(400);
    $response['message'] = 'Missing required query parameter: itemId or itemName.';
    error_log("Search API: Missing search term.");
    echo json_encode($response);
    $db = null; exit; 
}
error_log("Search request received. ItemID: $itemIdSearch, ItemName: $itemNameSearch, UserID: $userId");


// --- Find Matching Placed Items in DB ---
$foundItems = [];
$searchTerm = null;
$searchParam = '';
try {
    // Select item details AND container zone using JOIN
    $sql = "SELECT i.itemId, i.name, i.currentContainerId, i.width, i.depth, i.height, i.pos_w, i.pos_d, i.pos_h, i.status, i.priority, i.remainingUses, i.expiryDate, i.preferredZone, c.zone as containerZone 
            FROM items i
            LEFT JOIN containers c ON i.currentContainerId = c.containerId
            WHERE i.status = 'stowed' AND i.currentContainerId IS NOT NULL"; 

    if (!empty($itemIdSearch)) {
        $sql .= " AND i.itemId = :searchTerm";
        $searchParam = ':searchTerm';
        $searchTerm = $itemIdSearch;
    } else {
        $sql .= " AND i.name = :searchTerm"; // Exact name match
        $searchParam = ':searchTerm';
        $searchTerm = $itemNameSearch;
    }

    $stmt = $db->prepare($sql);
    if ($searchTerm !== null) { 
       $stmt->bindParam($searchParam, $searchTerm);
    }
    $stmt->execute();
    $foundItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("Search API: Found " . count($foundItems) . " potential matches in database.");

    if (empty($foundItems)) {
        $response['success'] = true; 
        $response['found'] = false;
        $response['message'] = "No stowed item found matching the criteria.";
        http_response_code(200); 
        echo json_encode($response);
        $db = null; 
        exit;
    }

} catch (PDOException $e) {
     http_response_code(500);
     $response['message'] = 'Error searching for items: ' . $e->getMessage();
     error_log("Search DB Error: " . $e->getMessage());
     echo json_encode($response);
     $db = null; 
     exit;
}


// --- Get ALL Placed Items (for Obstruction Calculation) ---
$allItemsByContainer = []; 
 try {
    // Select item locations, dimensions and NAME
    $sqlAll = "SELECT itemId, name, currentContainerId, pos_w, pos_d, pos_h, width, depth, height FROM items WHERE currentContainerId IS NOT NULL";
    $stmtAll = $db->prepare($sqlAll);
    $stmtAll->execute();
    $allItemsResult = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($allItemsResult as $item) {
         $containerId = $item['currentContainerId'];
         if (!isset($allItemsByContainer[$containerId])) { $allItemsByContainer[$containerId] = []; }
         // Store using common keys
         $allItemsByContainer[$containerId][$item['itemId']] = [ 
             'id'=> $item['itemId'],
             'name' => $item['name'], // Store name
             'x' => (float)$item['pos_w'], 'y' => (float)$item['pos_d'], 'z' => (float)$item['pos_h'], 
             'w' => (float)$item['width'], 'd' => (float)$item['depth'], 'h' => (float)$item['height'] 
         ];
    }
     error_log("Search API: Fetched all " . count($allItemsResult) . " placed item locations for obstruction check.");

} catch (PDOException $e) {
     http_response_code(500);
     $response['message'] = 'Error fetching all item placements: ' . $e->getMessage();
     error_log("Search API: Error fetching all placements - " . $e->getMessage());
     echo json_encode($response);
     $db = null;
     exit;
}


// --- Calculate Retrieval Cost and Steps for Each Found Item ---
$candidates = []; 

foreach ($foundItems as $itemInstance) {
    $targetItemId = $itemInstance['itemId'];
    $containerId = $itemInstance['currentContainerId'];
    $itemsInSameContainer = $allItemsByContainer[$containerId] ?? [];

    $targetItemBox = $allItemsByContainer[$containerId][$targetItemId] ?? null; 
    if ($targetItemBox === null) {
        error_log("Search API Warning: Target item $targetItemId not found in obstruction map for container $containerId. Skipping.");
        continue; 
    }
    error_log("Analyzing item $targetItemId in container $containerId at pos (" . $targetItemBox['x'] . "," . $targetItemBox['y'] . "," . $targetItemBox['z'] . ")");

    $obstructionCount = 0;
    $retrievalSteps = []; 
    $obstructionItemsData = []; 

    // Check every OTHER item in the same container
    foreach ($itemsInSameContainer as $otherItemId => $otherItemBox) {
        if ($targetItemId === $otherItemId) { continue; } 

        // Check if 'otherItem' is BLOCKING 'targetItem'
        $isObstruction = false;
        if ($otherItemBox['y'] < $targetItemBox['y']) { // 1. Is it physically in front?
             $xOverlap = ($targetItemBox['x'] < $otherItemBox['x'] + $otherItemBox['w']) && ($targetItemBox['x'] + $targetItemBox['w'] > $otherItemBox['x']);
             $zOverlap = ($targetItemBox['z'] < $otherItemBox['z'] + $otherItemBox['h']) && ($targetItemBox['z'] + $targetItemBox['h'] > $otherItemBox['z']);
             if ($xOverlap && $zOverlap) { 
                  error_log("--> Item $targetItemId is obstructed by $otherItemId at (" . $otherItemBox['x'] . "," . $otherItemBox['y'] . "," . $otherItemBox['z'] . ")");
                  $isObstruction = true; 
             }
        }

        if ($isObstruction) {
            $obstructionCount++;
            $obstructionItemsData[] = ['itemId' => $otherItemId, 'itemName' => $otherItemBox['name']]; // Use name stored earlier
        }
    } // End foreach other item
    error_log("Item $targetItemId has $obstructionCount obstructions.");

    // Build retrieval steps array
    $stepCounter = 0;
    // Add removal steps first
    foreach ($obstructionItemsData as $itemToMove) {
         $stepCounter++;
         $retrievalSteps[] = ['step' => $stepCounter, 'action' => 'remove', 'itemId' => $itemToMove['itemId'], 'itemName' => $itemToMove['itemName']]; 
         error_log("Adding step $stepCounter: remove " . $itemToMove['itemId']);
    }
    // Add retrieve target step
    $stepCounter++;
    $retrievalSteps[] = ['step' => $stepCounter, 'action' => 'retrieve', 'itemId' => $targetItemId, 'itemName' => $itemInstance['name']];
     error_log("Adding step $stepCounter: retrieve $targetItemId");
    // Add place back steps in reverse order of removal
    foreach (array_reverse($obstructionItemsData) as $itemToPlaceBack) {
         $stepCounter++;
         $retrievalSteps[] = ['step' => $stepCounter, 'action' => 'placeBack', 'itemId' => $itemToPlaceBack['itemId'], 'itemName' => $itemToPlaceBack['itemName']];
         error_log("Adding step $stepCounter: placeBack " . $itemToPlaceBack['itemId']);
    }

    // Store candidate details
    $expiryTimestamp = $itemInstance['expiryDate'] ? strtotime($itemInstance['expiryDate']) : PHP_INT_MAX; 
    $candidates[] = [
        'item' => $itemInstance, 
        'obstructionCount' => $obstructionCount, 
        'retrievalSteps' => $retrievalSteps,
        'expiryScore' => $expiryTimestamp 
    ];

} // End foreach found item instance


// --- Select the Best Candidate ---
if (empty($candidates)) {
    $response['success'] = true; 
    $response['found'] = false;
    $response['message'] = "Item located, but internal error occurred calculating retrieval.";
    error_log("Search API: No valid candidates generated despite finding items initially.");
    http_response_code(500); 
} else {
    // Sort candidates
    usort($candidates, function($a, $b) {
        if ($a['obstructionCount'] !== $b['obstructionCount']) { return $a['obstructionCount'] <=> $b['obstructionCount']; }
        if ($a['expiryScore'] !== $b['expiryScore']) { return $a['expiryScore'] <=> $b['expiryScore']; }
        return 0; 
    });

    $bestCandidate = $candidates[0]; 

    // Prepare response
    $response['success'] = true;
    $response['found'] = true;
    $response['item'] = [
        'itemId' => $bestCandidate['item']['itemId'],
        'name' => $bestCandidate['item']['name'],
        'containerId' => $bestCandidate['item']['currentContainerId'],
        'zone' => $bestCandidate['item']['containerZone'] ?? 'Unknown', 
        'position' => [
            'startCoordinates' => [
                'width' => (float)$bestCandidate['item']['pos_w'],
                'depth' => (float)$bestCandidate['item']['pos_d'],
                'height' => (float)$bestCandidate['item']['pos_h']
            ],
             'endCoordinates' => [ 
                'width' => (float)$bestCandidate['item']['pos_w'] + (float)$bestCandidate['item']['width'],
                'depth' => (float)$bestCandidate['item']['pos_d'] + (float)$bestCandidate['item']['depth'],
                'height' => (float)$bestCandidate['item']['pos_h'] + (float)$bestCandidate['item']['height']
            ]
        ]
         // Optional: Add other item details
         // 'priority': $bestCandidate['item']['priority'], ...
    ];
    $response['retrievalSteps'] = $bestCandidate['retrievalSteps']; 
    $response['message'] = "Best retrieval option found with " . $bestCandidate['obstructionCount'] . " obstructions.";
    http_response_code(200);
    error_log("Search API: Best candidate found: Item " . $bestCandidate['item']['itemId'] . " in " . $bestCandidate['item']['currentContainerId'] . " with " . $bestCandidate['obstructionCount'] . " obstructions.");
}

// --- Final Output ---
echo json_encode($response, JSON_PRETTY_PRINT); 
$db = null; // Close connection
error_log("Search API: Finished.");

// --- !!! HELPER FUNCTIONS (If needed, but not strictly required for search logic itself) !!! ---
// You might not need boxesOverlap here unless you do more complex retrieval path checks

?>