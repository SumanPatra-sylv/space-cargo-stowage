<?php
// --- File: backend/index.php (API Router) ---

// --- 1. Centralized CORS and Headers ---
// Allow requests from your frontend development server
header("Access-Control-Allow-Origin: http://localhost:5173"); // Adjust if needed
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS"); // Allow common methods
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 3600");
// Set Content-Type for JSON by default, individual scripts can override if needed (like CSV export)
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request centrally
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200); // Use 200 OK for OPTIONS preflight
    exit();
}

// --- 2. Error Reporting ---
ini_set('display_errors', 0); // Production: 0, Development: 1
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    // Log errors instead of displaying them, preventing broken JSON
    error_log("PHP Error: [$severity] $message in $file on line $line");
    // Don't execute PHP internal error handler
    // For fatal errors, script execution might stop anyway
    if (error_reporting() & $severity) {
         // If it's a fatal error type, send a generic server error response
         if (in_array($severity, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            // Ensure headers haven't already been sent
            if (!headers_sent()) {
                 http_response_code(500);
                 echo json_encode(['success' => false, 'message' => 'Internal Server Error']);
            }
            exit();
         }
    }
     return true; // Prevent default PHP error handling for non-fatal errors
});


// --- 3. Basic Routing ---
$requestUri = $_SERVER['REQUEST_URI'];
$basePath = '/api'; // Define the base path for our API routes

// Remove query string from URI for routing
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Basic check if it's an API request
if (strpos($requestPath, $basePath) === 0) {
    // Extract the specific route part after /api/
    $route = substr($requestPath, strlen($basePath));
    $route = trim($route, '/'); // Remove leading/trailing slashes

    // Define mappings from route to script file
    // Ensure these filenames match your actual files and structure
    $routes = [
        // General
        'items' => 'get_items.php',             // GET /api/items
        'containers' => 'get_containers.php',   // GET /api/containers
        'placement' => 'placement.php',         // POST /api/placement
        'search' => 'search.php',               // GET /api/search
        'retrieve' => 'retrieve.php',           // POST /api/retrieve
        'place' => 'place.php',                 // POST /api/place (Manual Place)
        'inventory' => 'inventory.php',         // GET /api/inventory
        'logs' => 'logs.php',                   // GET /api/logs
        'log-action' => 'log_action.php',       // POST /api/log-action  <<< NEW ROUTE ADDED HERE

        // Import/Export
        'import/items' => 'import_items.php',       // POST /api/import/items
        'import/containers' => 'import_containers.php',// POST /api/import/containers
        'export/arrangement' => 'export/arrangement.php', // GET /api/export/arrangement

        // Simulate
        'simulate/day' => 'simulate/day.php',       // POST /api/simulate/day

        // Waste
        'waste/identify' => 'waste/identify.php',   // GET /api/waste/identify
        'waste/return-plan' => 'waste/return_plan.php', // POST /api/waste/return-plan
        'waste/complete-undocking' => 'waste/complete_undocking.php' // POST /api/waste/complete-undocking

        // Add other routes here as needed
    ];

    // Check if the requested route exists in our map
    if (array_key_exists($route, $routes)) {
        $scriptToInclude = $routes[$route];
        $scriptPath = __DIR__ . '/' . $scriptToInclude;

        // Check if the script file actually exists
        // Handle potential subdirectories defined in the route value (e.g., 'waste/identify.php')
        if (file_exists($scriptPath)) {
             // Include the target script.
             // Database connection will be handled within each script via getDbConnection()
             require_once $scriptPath;
             exit(); // Stop script execution after included script finishes
        } else {
            // Script file not found - Internal Server Error
            http_response_code(500);
             error_log("Router Error: Script file not found for route '$route': $scriptPath");
            echo json_encode(['success' => false, 'message' => 'Server configuration error (script missing).']);
            exit();
        }
    } else {
        // Route not defined in our map - Not Found
        http_response_code(404);
        error_log("Router Error: Route not found: $requestPath (Route parsed as: '$route')");
        echo json_encode(['success' => false, 'message' => 'API endpoint not found.']);
        exit();
    }

} else {
    // Request URI does not start with /api/ - Not Found
    http_response_code(404);
    error_log("Router Error: Request URI does not match API base path: $requestPath");
    echo json_encode(['success' => false, 'message' => 'Resource not found.']);
    exit();
}

?>