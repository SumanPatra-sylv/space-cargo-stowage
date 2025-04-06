<?php // backend/export/arrangement.php (Modified for central index.php handling)

/* --- REMOVED/COMMENTED OUT - Handled by index.php ---
// ini_set('display_errors', 1); // Handled by index.php
// error_reporting(E_ALL);      // Handled by index.php
--- END REMOVED/COMMENTED OUT --- */


// --- Database Connection ---
// Use __DIR__ for robustness. Path goes up one level from 'export' to 'backend'.
require_once __DIR__ . '/../database.php'; // Adjusted path
error_log("Export Arrangement API: Attempting DB connection...");
$db = getDbConnection();

if ($db === null) {
    error_log("Export Arrangement API: Failed DB connection.");
    http_response_code(500); // Internal Server Error
    // Set plain text header for error message - KEEP THIS
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error: Database connection failed.";
    exit; // Exit is necessary here to stop further processing/output
}
error_log("Export Arrangement API: DB connection successful.");
// --- End Database Connection ---


// --- Handle GET Request ---
// This check remains valid for the specific endpoint
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); // Method Not Allowed
    // Set plain text header for error message - KEEP THIS
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: Invalid request method. Only GET is allowed.';
    error_log("Export Arrangement API: Received method " . $_SERVER['REQUEST_METHOD']);
    $db = null;
    exit; // Exit is necessary here
}
error_log("Export Arrangement API: Request received.");
// --- End Request Handling ---


// --- Core Logic: Fetch Data and Generate CSV ---
$output = null; // Initialize output variable
try {
    // Fetch placed items
    // *** NOTE: Verify these DB column names match your schema ***
    $sql = "SELECT itemId, containerId, -- Assuming 'containerId' stores current container
                   positionX AS pos_w, positionY AS pos_d, positionZ AS pos_h, -- Assuming position columns
                   placedDimensionW AS width, placedDimensionD AS depth, placedDimensionH AS height -- Assuming placed dimension columns
            FROM items
            WHERE status = 'stowed'
            AND containerId IS NOT NULL
            AND positionX IS NOT NULL AND positionY IS NOT NULL AND positionZ IS NOT NULL
            AND placedDimensionW IS NOT NULL AND placedDimensionD IS NOT NULL AND placedDimensionH IS NOT NULL
            ORDER BY containerId, itemId"; // Order for consistent output

    $stmt = $db->prepare($sql);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $itemCount = count($items);
    error_log("Export Arrangement API: Found " . $itemCount . " placed items to export.");

    // Check if we have any items to export
    if ($itemCount === 0) {
        http_response_code(404); // Not Found
        // Set plain text header for message - KEEP THIS
        header('Content-Type: text/plain; charset=utf-8');
        echo "No stowed items found to export.";
        $db = null;
        exit; // Exit is necessary here
    }

    // --- Generate CSV Output ---

    // 1. Set Headers for CSV Download - KEEP THESE
    $filename = "stowage_arrangement_" . date('Ymd_His') . ".csv";
    // Make sure no other output happens before these headers
    header('Content-Type: text/csv; charset=utf-8'); // KEEP: Essential for CSV
    header('Content-Disposition: attachment; filename="' . $filename . '"'); // KEEP: Essential for download

    // 2. Create output stream
    $output = fopen('php://output', 'w');
    if ($output === false) {
        // If stream fails, we might not be able to send headers reliably. Log and exit.
        error_log("Export Arrangement API FATAL: Failed to open php://output stream.");
        // Avoid sending more headers if some were already sent.
        if (!headers_sent()) {
            http_response_code(500);
             // Set plain text header for error message - KEEP THIS
            header('Content-Type: text/plain; charset=utf-8');
            echo "Error: Failed to create output for CSV.";
        }
        $db = null;
        exit; // Exit is necessary here
    }

    // 3. Write Header Row - Adjust based on actual selected columns and desired CSV format
    // Using (Start X,Y,Z) and (Placed Width, Depth, Height) might be clearer
    fputcsv($output, ['ItemID', 'ContainerID', 'PositionX', 'PositionY', 'PositionZ', 'PlacedWidth', 'PlacedDepth', 'PlacedHeight'], ',', '"', '\\');
    error_log("Export Arrangement API: Writing CSV header.");

    // 4. Write Data Rows
    foreach ($items as $item) {
        // Use the direct values fetched
        $posX = $item['pos_w'] ?? 0;
        $posY = $item['pos_d'] ?? 0;
        $posZ = $item['pos_h'] ?? 0;

        $placedW = $item['width'] ?? 0; // Already aliased to width
        $placedD = $item['depth'] ?? 0; // Already aliased to depth
        $placedH = $item['height'] ?? 0; // Already aliased to height

        // Write row to CSV stream - Include escape parameter
        fputcsv($output, [
            $item['itemId'],
            $item['containerId'],
            sprintf('%.3f', $posX), // Format numbers if desired
            sprintf('%.3f', $posY),
            sprintf('%.3f', $posZ),
            sprintf('%.3f', $placedW),
            sprintf('%.3f', $placedD),
            sprintf('%.3f', $placedH)
        ], ',', '"', '\\');
    }
    error_log("Export Arrangement API: Finished writing " . $itemCount . " data rows.");

} catch (PDOException $e) {
    error_log("Export Arrangement DB Error: " . $e->getMessage());
    // Cannot reliably send headers/CSV if error happens after header() calls
    if (!headers_sent()) {
         http_response_code(500); // Internal Server Error
         // Set plain text header for error message - KEEP THIS
         header('Content-Type: text/plain; charset=utf-8'); // Override CSV headers if possible
         echo "Error: Database error during export.";
    }
    // Fall through to finally block
} catch (Exception $e) {
     error_log("Export Arrangement General Error: " . $e->getMessage());
     if (!headers_sent()) {
         http_response_code(500); // Internal Server Error
         // Set plain text header for error message - KEEP THIS
         header('Content-Type: text/plain; charset=utf-8');
         echo "Error: General error during export.";
     }
     // Fall through to finally block
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
// Exit is necessary here to ensure no further output from index.php interferes with the CSV/error output.
exit;
?>