<?php // backend/api/export/arrangement.php

ini_set('display_errors', 1); 
error_reporting(E_ALL); 

// --- Database Connection ---
// Note: Path is up TWO levels from api/export/
require_once __DIR__ . '/../../database.php'; 
error_log("Export Arrangement API: Attempting DB connection...");
$db = getDbConnection(); 

if ($db === null) { 
    error_log("Export Arrangement API: Failed DB connection.");
    http_response_code(500); 
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error: Database connection failed.";
    exit; 
}
error_log("Export Arrangement API: DB connection successful.");
// --- End Database Connection --- 


// --- Handle GET Request ---
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); 
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: Invalid request method. Only GET is allowed.';
    error_log("Export Arrangement API: Received method " . $_SERVER['REQUEST_METHOD']);
    $db = null; exit; 
}
error_log("Export Arrangement API: Request received.");
// --- End Request Handling ---


// --- Core Logic: Fetch Data and Generate CSV ---
try {
    // Fetch placed items 
    $sql = "SELECT itemId, currentContainerId, pos_w, pos_d, pos_h, width, depth, height 
            FROM items 
            WHERE status = 'stowed' 
            AND currentContainerId IS NOT NULL
            AND pos_w IS NOT NULL AND pos_d IS NOT NULL AND pos_h IS NOT NULL
            ORDER BY currentContainerId, itemId"; // Order for consistent output
            
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $itemCount = count($items);
    error_log("Export Arrangement API: Found " . $itemCount . " placed items to export.");
    
    // Check if we have any items to export
    if ($itemCount === 0) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "No stowed items found to export.";
        exit;
    }

    // --- Generate CSV Output ---
    
    // 1. Set Headers for CSV Download
    $filename = "stowage_arrangement_" . date('Ymd_His') . ".csv";
    // Make sure no other output happens before these headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"'); 

    // 2. Create output stream
    $output = fopen('php://output', 'w'); 
    if ($output === false) {
        throw new Exception("Failed to open output stream");
    }

    // 3. Write Header Row - Add all required parameters including escape char
    fputcsv($output, ['Item ID', 'Container ID', 'Coordinates (W1,D1,H1)', '(W2,D2,H2)'], ',', '"', '\\'); 
    error_log("Export Arrangement API: Writing CSV header.");

    // 4. Write Data Rows
    foreach ($items as $item) {
        // Cast all dimension values to float to avoid calculation errors
        $w1 = isset($item['pos_w']) ? (float)$item['pos_w'] : 0;
        $d1 = isset($item['pos_d']) ? (float)$item['pos_d'] : 0;
        $h1 = isset($item['pos_h']) ? (float)$item['pos_h'] : 0;
        
        $itemW = isset($item['width']) ? (float)$item['width'] : 0;
        $itemD = isset($item['depth']) ? (float)$item['depth'] : 0;
        $itemH = isset($item['height']) ? (float)$item['height'] : 0;
        
        // Format coordinates with 2 decimal places
        $startCoords = sprintf("(%.2f,%.2f,%.2f)", $w1, $d1, $h1);
        $endCoords = sprintf("(%.2f,%.2f,%.2f)", $w1 + $itemW, $d1 + $itemD, $h1 + $itemH);

        // Write row to CSV stream - Include escape parameter
        fputcsv($output, [
            $item['itemId'],
            $item['currentContainerId'],
            $startCoords,
            $endCoords
        ], ',', '"', '\\'); 
    }
    error_log("Export Arrangement API: Finished writing " . $itemCount . " data rows.");

} catch (PDOException $e) {
    error_log("Export Arrangement DB Error: " . $e->getMessage());
    // Cannot reliably send headers/CSV if error happens after header() calls
    if (!headers_sent()) {
         http_response_code(500); 
         header('Content-Type: text/plain; charset=utf-8'); // Override CSV headers if possible
         echo "Error: Database error during export.";
    }
} catch (Exception $e) {
     error_log("Export Arrangement General Error: " . $e->getMessage());
     if (!headers_sent()) {
         http_response_code(500);
         header('Content-Type: text/plain; charset=utf-8');
         echo "Error: General error during export.";
     }
} finally {
    // Close output stream if it was opened
    if (isset($output) && is_resource($output)) {
        fclose($output);
    }
    
    // Close database connection
    $db = null;
}
// --- End Core Logic ---

error_log("Export Arrangement API: Finished.");
exit; // Ensure script stops after outputting CSV data or error
?>