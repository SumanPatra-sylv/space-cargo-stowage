<?php // backend/init_db.php (Corrected Schema V2 - Added preferredContainerId)

// Define path to the SQLite database file
$dbPath = __DIR__ . '/cargo_database.sqlite'; // Store DB in backend folder

// --- Database Connection ---
try {
    // Ensure the directory exists if it doesn't (optional, good practice)
    if (!is_dir(__DIR__)) {
        mkdir(__DIR__, 0755, true);
    }

    // Delete existing database file if it exists to ensure a fresh start
    if (file_exists($dbPath)) {
        unlink($dbPath);
        echo "Existing database file deleted.\n";
    }

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Enable error reporting
    $db->exec('PRAGMA foreign_keys = ON;'); // Enforce foreign key constraints
    echo "Database connection established successfully.\n";
} catch (PDOException $e) {
    echo "Database Connection Error: " . $e->getMessage() . "\n";
    exit(1); // Exit if connection fails
}


// --- CREATE TABLES ---
try {
    echo "Starting table creation...\n";

    // Containers Table
    $db->exec("
        CREATE TABLE IF NOT EXISTS containers (
            containerId TEXT PRIMARY KEY,
            zone TEXT NOT NULL,
            width REAL NOT NULL CHECK(width > 0),     -- Added check > 0
            depth REAL NOT NULL CHECK(depth > 0),     -- Added check > 0
            height REAL NOT NULL CHECK(height > 0),   -- Added check > 0
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");
    echo "Table 'containers' created or already exists.\n";

    // Items Table (CORRECTED SCHEMA - Added preferredContainerId)
    $db->exec("
        CREATE TABLE IF NOT EXISTS items (
            itemId TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            dimensionW REAL NOT NULL CHECK(dimensionW > 0), -- Base width dimension (original)
            dimensionD REAL NOT NULL CHECK(dimensionD > 0), -- Base depth dimension (original)
            dimensionH REAL NOT NULL CHECK(dimensionH > 0), -- Base height dimension (original)
            mass REAL CHECK(mass >= 0),                      -- Mass can be 0 or positive
            priority INTEGER NOT NULL CHECK(priority >= 0 AND priority <= 100),
            expiryDate TEXT,                                -- Format: YYYY-MM-DD or NULL
            usageLimit INTEGER,                             -- NULL if unlimited
            remainingUses INTEGER,                          -- Initialized to usageLimit or NULL
            preferredZone TEXT NULL,                        -- Can be NULL if no preference
            preferredContainerId TEXT NULL,                 -- <<<<< ADDED THIS LINE (Can be NULL)
            status TEXT DEFAULT 'available' CHECK(status IN ('available', 'stowed', 'disposed', 'reserved','expired',consumed')), -- Added CHECK constraint
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP,
            lastUpdated TEXT DEFAULT CURRENT_TIMESTAMP,     -- For tracking changes

            -- Location Information (if currently stowed)
            containerId TEXT NULL,                          -- Foreign key (NULL if not placed)
            positionX REAL NULL,                            -- Position X coordinate (NULL if not placed)
            positionY REAL NULL,                            -- Position Y coordinate (NULL if not placed)
            positionZ REAL NULL,                            -- Position Z coordinate (NULL if not placed)
            placedDimensionW REAL NULL,                     -- Width dimension as placed (accounts for rotation)
            placedDimensionD REAL NULL,                     -- Depth dimension as placed
            placedDimensionH REAL NULL,                     -- Height dimension as placed

            -- Foreign Key constraint
            FOREIGN KEY (containerId) REFERENCES containers(containerId)
                ON DELETE SET NULL  -- If container deleted, item becomes unplaced (NULL)
                ON UPDATE CASCADE   -- If containerId changes, update here too
        );
    ");
    echo "Table 'items' created or already exists (Schema includes preferredContainerId).\n"; // Updated message

     // Logs Table
    $db->exec("
        CREATE TABLE IF NOT EXISTS logs (
            logId INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
            userId TEXT,                            -- Who performed the action (or 'System_...')
            actionType TEXT NOT NULL,               -- e.g., 'import', 'placement', 'move', 'update'
            itemId TEXT,                            -- Optional: Related item ID
            detailsJson TEXT,                       -- JSON blob for detailed info
            FOREIGN KEY (itemId) REFERENCES items(itemId) ON DELETE SET NULL -- If item deleted, log keeps reference but FK becomes NULL
        );
    ");
    echo "Table 'logs' created or already exists.\n";

    // Add Indexes for Performance (Optional but Recommended)
    $db->exec("CREATE INDEX IF NOT EXISTS idx_items_status ON items(status);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_items_containerId ON items(containerId);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_actionType ON logs(actionType);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_timestamp ON logs(timestamp);");
    echo "Indexes created or already exist.\n";


    echo "Database schema initialized successfully!\n";

} catch (PDOException $e) {
    echo "Schema Initialization Error: " . $e->getMessage() . "\n";
    // Consider rolling back or cleaning up if partial creation occurred, though CREATE IF NOT EXISTS helps
    exit(1); // Exit on schema error
}

$db = null; // Close connection explicitly
echo "Database connection closed.\n";

?>