<?php // backend/api/import_items.php

// Set header to return JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow requests from any origin (for development)
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request (pre-flight request for CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// --- Database Connection ---
require_once __DIR__ . '/../database.php';// Include the connection helper function file
error_log("Attempting to get DB connection for item import...");
$db = getDbConnection(); // Call the function to get the PDO object

if ($db === null) {
    error_log("Failed to get DB connection from database.php. Script cannot continue.");
    http_response_code(500); // Internal Server Error
    echo json_encode([
        'success' => false, 
        'message' => 'Critical error: Database connection failed.', 
        'itemsImported' => 0, // Use itemsImported key
        'errors' => []
    ]);
    exit; // Stop script if DB connection fails
}
error_log("Successfully obtained DB connection for item import.");
// --- End Database Connection --- 


// --- Request Handling ---
$response = [
    'success' => false,
    'itemsImported' => 0, // Use itemsImported key
    'errors' => []
];

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Item Import: Invalid request method received: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405); 
    $response['message'] = 'Invalid request method. Only POST is allowed.';
    echo json_encode($response);
    exit;
}

// Check if a file was uploaded
if (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK) {
    $errorCode = isset($_FILES['csvFile']['error']) ? $_FILES['csvFile']['error'] : 'Not Set';
    error_log("Item Import: File upload error. Code: " . $errorCode);
    http_response_code(400); 
    $response['message'] = 'No CSV file uploaded or error during upload.';
    if (isset($_FILES['csvFile']['error'])) {
         $uploadErrors = [ /* ... Error codes ... */ ]; // Keep error code map
         $response['uploadError'] = $uploadErrors[$errorCode] ?? "Unknown upload error code: $errorCode";
         error_log("Item Import: Upload Error Details: " . $response['uploadError']); 
    }
    echo json_encode($response);
    exit;
}

$fileTmpPath = $_FILES['csvFile']['tmp_name'];
$fileName = $_FILES['csvFile']['name'];
$fileSize = $_FILES['csvFile']['size'];

error_log("Item Import: Received upload: " . $fileName . " at temporary path: " . $fileTmpPath . " | Size: " . $fileSize);

// Basic validation (e.g., check file extension)
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if ($fileExtension !== 'csv') {
    error_log("Item Import: Invalid file type uploaded: " . $fileName . " (Extension: " . $fileExtension . ")");
    http_response_code(400); 
    $response['message'] = 'Invalid file type. Only CSV files are accepted.';
    echo json_encode($response);
    exit;
}


// --- Move Uploaded File ---
$destinationPath = __DIR__ . '/../uploads/'; 
$fileToOpen = null; 

// Create uploads directory if it doesn't exist (error handling included)
if (!is_dir($destinationPath)) {
    if (!mkdir($destinationPath, 0777, true)) {
        error_log("Item Import: Failed to create uploads directory: " . $destinationPath . " - Error: " . print_r(error_get_last(), true));
        error_log("Item Import: WARNING: Proceeding using temporary file path due to directory creation failure.");
        $fileToOpen = $fileTmpPath;
    } else {
         error_log("Item Import: Created uploads directory: " . $destinationPath);
         chmod($destinationPath, 0777);
    }
}

// Attempt to move the file if directory creation was successful or dir already existed
if ($fileToOpen === null) {
    if (is_dir($destinationPath) && is_writable($destinationPath)) {
        $safeBaseName = basename($fileName); 
        $newFilePath = $destinationPath . $safeBaseName . "_items_" . time(); // Add suffix to avoid overwrites
        
        error_log("Item Import: Attempting move_uploaded_file from $fileTmpPath to $newFilePath");
        if (move_uploaded_file($fileTmpPath, $newFilePath)) {
            error_log("Item Import: Successfully moved uploaded file to: " . $newFilePath);
            $fileToOpen = $newFilePath; 
        } else {
            error_log("Item Import: Failed to move uploaded file from $fileTmpPath to $newFilePath. Check permissions/paths. Error: " . print_r(error_get_last(), true));
            $fileToOpen = $fileTmpPath; 
        }
    } else {
         error_log("Item Import: Uploads directory is not writable or does not exist: " . $destinationPath . ". Using temporary path.");
         $fileToOpen = $fileTmpPath; 
    }
}
// --- End Move Uploaded File ---


// --- CSV Parsing and Database Insertion ---
$itemsImported = 0; // Changed variable name
$rowNumber = 0; 
$skippedHeader = false;
$stmt = null; 
$handle = null; 

// SQL statement for ITEMS table
$sql = "INSERT INTO items 
            (itemId, name, width, depth, height, mass, priority, expiryDate, usageLimit, remainingUses, preferredZone, status) 
        VALUES 
            (:itemId, :name, :width, :depth, :height, :mass, :priority, :expiryDate, :usageLimit, :remainingUses, :preferredZone, 'stowed')"; 


if (empty($fileToOpen)) {
     error_log("ITEM IMPORT FATAL: File path to open is empty.");
     http_response_code(500);
     $response['message'] = "Internal server error: Could not determine file path to process.";
     echo json_encode($response);
     exit;
}

error_log("Item Import: Final check before fopen - Path: $fileToOpen | Exists: " . (file_exists($fileToOpen)?'Yes':'No') . " | Readable: " . (is_readable($fileToOpen)?'Yes':'No'));

$db->beginTransaction();

try {
    $stmt = $db->prepare($sql); 

    ini_set('auto_detect_line_endings', TRUE); 
    error_log("Item Import: Attempting fopen on: " . $fileToOpen); 
    $handle = fopen($fileToOpen, "r"); 

    if ($handle !== FALSE) {
        error_log("Item Import: fopen successful for: " . $fileToOpen); 

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) { 
            if ($data === NULL) { 
                 error_log("Item Import: fgetcsv returned NULL (likely blank line), skipping. Row number was: " . $rowNumber);
                 continue; 
            }

            $rowNumber++; 

            if ($rowNumber == 1 && !$skippedHeader) {
                 error_log("Item Import: Skipping header row: " . print_r($data, true));
                 $skippedHeader = true; 
                 continue; 
            }

            error_log("Item Import: Processing Row $rowNumber: " . print_r($data, true)); 

            // --- Validation for ITEMS (expects 10 columns) ---
            if (count($data) !== 10) { 
                 $response['errors'][] = ['row' => $rowNumber, 'message' => 'Wrong number of columns (' . count($data) . '). Expected exactly 10.'];
                 error_log("Item Import: Row $rowNumber skipped: Wrong number of columns (" . count($data) . ").");
                 continue; 
            }

            // Assign data to variables
             $itemId        = isset($data[0]) ? trim($data[0]) : '';
             $name          = isset($data[1]) ? trim($data[1]) : '';
             $width         = isset($data[2]) ? trim($data[2]) : '';
             $depth         = isset($data[3]) ? trim($data[3]) : '';
             $height        = isset($data[4]) ? trim($data[4]) : '';
             $mass_str      = isset($data[5]) ? trim($data[5]) : ''; // Read as string first
             $priority_str  = isset($data[6]) ? trim($data[6]) : ''; // Read as string first
             $expiryDate    = isset($data[7]) ? trim($data[7]) : '';
             $usageLimit_str= isset($data[8]) ? trim($data[8]) : ''; // Read as string first
             $preferredZone = isset($data[9]) ? trim($data[9]) : '';

             // Detailed Validation
             if (empty($itemId)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Item ID empty']; error_log("Item Import: Row $rowNumber skipped: Empty Item ID."); continue; }
             if (empty($name)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Name empty']; error_log("Item Import: Row $rowNumber skipped: Empty Name."); continue; }
             // Dimensions
             if (!is_numeric($width)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Width not numeric: '.$width]; error_log("Item Import: Row $rowNumber skipped: Width not numeric ($width)."); continue; }
             if (!is_numeric($depth)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Depth not numeric: '.$depth]; error_log("Item Import: Row $rowNumber skipped: Depth not numeric ($depth)."); continue; }
             if (!is_numeric($height)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Height not numeric: '.$height]; error_log("Item Import: Row $rowNumber skipped: Height not numeric ($height)."); continue; }
             if ($width <= 0) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Width not positive: '.$width]; error_log("Item Import: Row $rowNumber skipped: Width not positive ($width)."); continue; }
             if ($depth <= 0) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Depth not positive: '.$depth]; error_log("Item Import: Row $rowNumber skipped: Depth not positive ($depth)."); continue; }
             if ($height <= 0) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Height not positive: '.$height]; error_log("Item Import: Row $rowNumber skipped: Height not positive ($height)."); continue; }
             // Mass (optional, but if present must be numeric >= 0)
             $mass = null;
             if (!empty($mass_str)) {
                 if (!is_numeric($mass_str)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Mass not numeric: '.$mass_str]; error_log("Item Import: Row $rowNumber skipped: Mass not numeric ($mass_str)."); continue; }
                 if ($mass_str < 0) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Mass cannot be negative: '.$mass_str]; error_log("Item Import: Row $rowNumber skipped: Negative mass ($mass_str)."); continue; }
                 $mass = (float)$mass_str;
             }
             // Priority (required, numeric, 0-100)
             if (!is_numeric($priority_str)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Priority not numeric: '.$priority_str]; error_log("Item Import: Row $rowNumber skipped: Priority not numeric ($priority_str)."); continue; }
             $priority = (int)$priority_str;
             if ($priority < 0 || $priority > 100) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Priority must be 0-100: '.$priority]; error_log("Item Import: Row $rowNumber skipped: Priority out of range ($priority)."); continue; }
             // Expiry Date (optional, check format if present - basic check YYYY-MM-DD)
             if (!empty($expiryDate) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) {
                 $response['errors'][] = ['row' => $rowNumber, 'message' => 'Invalid expiry date format (use YYYY-MM-DD): '.$expiryDate]; 
                 error_log("Item Import: Row $rowNumber skipped: Invalid expiry date format ($expiryDate)."); 
                 continue;
             }
             $expiryDate = empty($expiryDate) ? null : $expiryDate; // Use null if empty
             // Usage Limit (optional, integer >= 0 if present)
             $usageLimit = null;
             $remainingUses = null; // Will be set based on usageLimit
             if (!empty($usageLimit_str)) {
                 // Check if it's a whole number (integer)
                 if (!ctype_digit($usageLimit_str)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Usage limit must be a non-negative integer: '.$usageLimit_str]; error_log("Item Import: Row $rowNumber skipped: Usage limit not integer ($usageLimit_str)."); continue; }
                 $usageLimit = (int)$usageLimit_str;
                 $remainingUses = $usageLimit; // Set remaining uses initially
             } else {
                  $remainingUses = null; // If no usage limit, remaining uses is also null/not applicable
             }
             // Preferred Zone (optional)
             $preferredZone = empty($preferredZone) ? null : $preferredZone;

            // --- Database Insertion ---
             try {
                 if (!$stmt) { throw new Exception("DB statement not prepared."); }
                 // Bind all parameters for the items table
                 $stmt->bindParam(':itemId', $itemId);
                 $stmt->bindParam(':name', $name);
                 $stmt->bindParam(':width', $width);
                 $stmt->bindParam(':depth', $depth);
                 $stmt->bindParam(':height', $height);
                 $stmt->bindParam(':mass', $mass); // Bind potentially null value
                 $stmt->bindParam(':priority', $priority);
                 $stmt->bindParam(':expiryDate', $expiryDate); // Bind potentially null value
                 $stmt->bindParam(':usageLimit', $usageLimit); // Bind potentially null value
                 $stmt->bindParam(':remainingUses', $remainingUses); // Bind potentially null value
                 $stmt->bindParam(':preferredZone', $preferredZone); // Bind potentially null value
                 
                 $stmt->execute();
                 $itemsImported++; // Use itemsImported
                 error_log("Item Import: Row $rowNumber inserted successfully: $itemId");
             } catch (PDOException $e) {
                 $response['errors'][] = ['row' => $rowNumber, 'message' => 'DB Error for ' . $itemId . ': ' . $e->getMessage()];
                 error_log("Item Import: Row $rowNumber DB Error for $itemId: " . $e->getMessage());
             }
            // --- End Insertion ---

        } // End while loop

        error_log("Item Import: Exited while loop.");
        if (is_resource($handle)) { fclose($handle); error_log("Item Import: Closed file handle."); } 
        else { error_log("Item Import: File handle was not valid before fclose."); }

    } else { // if fopen fails
        error_log("Item Import: fopen FAILED for path: " . $fileToOpen . " - Error: " . print_r(error_get_last(), true)); 
        throw new Exception("Could not open the CSV file at: " . $fileToOpen); 
    } // End if fopen

    if ($db->inTransaction()) {
        $db->commit();
        error_log("Item Import: Transaction committed. Total imported: $itemsImported");
    } else {
         error_log("Item Import: Skipped commit as no transaction was active.");
    }
    $response['success'] = true;

} catch (Exception $e) {
    if ($db->inTransaction()) { $db->rollBack(); error_log("Item Import: Transaction rolled back due to exception."); }
    http_response_code(500); 
    $response['success'] = false;
    $response['message'] = 'Error processing item CSV file: ' . $e->getMessage();
    error_log("ITEM IMPORT EXCEPTION CAUGHT: " . $e->getMessage());

} finally {
    if ($handle && is_resource($handle)) { fclose($handle); error_log("Item Import: Closed file handle in finally block."); }
    $stmt = null;
    $db = null;
    error_log("Item Import: Script execution finished."); 
}


// --- Send Response ---
if (!$response['success']) {
    if (http_response_code() === 200) { http_response_code(400); }
    if (empty($response['message'])) { $response['message'] = "Item import failed. Check server logs."; }
} else if (!empty($response['errors']) && $itemsImported == 0) {
    http_response_code(400); 
    $response['message'] = "Item import failed. No items were imported validly.";
} else if (!empty($response['errors'])) {
    http_response_code(200); 
    $response['message'] = "Item import partially successful. Some rows were skipped - see errors for details.";
} else {
    http_response_code(200); 
    $response['message'] = "Item import successful.";
}

$response['itemsImported'] = $itemsImported; // Use itemsImported

echo json_encode($response, JSON_PRETTY_PRINT); 

?>