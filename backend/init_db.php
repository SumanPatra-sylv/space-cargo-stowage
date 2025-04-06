<?php // backend/init_db.php (Corrected Schema)

// Define path to the SQLite database file
$dbPath = __DIR__ . '/cargo_database.sqlite'; // Store DB in backend folder

// --- Database Connection ---
try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON;');
    echo "Database connection established successfully.\n";
} catch (PDOException $e) {
    echo "Database Connection Error: " . $e->getMessage() . "\n";
    exit(1);
}


// --- CREATE TABLES ---
try {
    // Containers Table (Keep as is - seems correct)
    $db->exec("
        CREATE TABLE IF NOT EXISTS containers (
            containerId TEXT PRIMARY KEY,
            zone TEXT NOT NULL,
            width REAL NOT NULL,
            depth REAL NOT NULL,
            height REAL NOT NULL,
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");
    echo "Table 'containers' created or already exists.\n";

    // Items Table (MODIFIED SCHEMA)
    $db->exec("
        CREATE TABLE IF NOT EXISTS items (
            itemId TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            dimensionW REAL NOT NULL,          -- CORRECTED: Base width dimension (internal)
            dimensionD REAL NOT NULL,          -- CORRECTED: Base depth dimension (internal)
            dimensionH REAL NOT NULL,          -- CORRECTED: Base height dimension (internal)
            mass REAL,
            priority INTEGER NOT NULL CHECK(priority >= 0 AND priority <= 100),
            expiryDate TEXT,                 -- YYYY-MM-DD or NULL
            usageLimit INTEGER,              -- NULL if unlimited
            remainingUses INTEGER,           -- Initialized to usageLimit or NULL
            preferredZone TEXT,
            status TEXT DEFAULT 'available', -- CORRECTED: Default should be available for new items
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP,
            lastUpdated TEXT DEFAULT CURRENT_TIMESTAMP, -- ADDED: Last updated timestamp

            -- Location Information (if currently stowed)
            containerId TEXT,                -- CORRECTED: Foreign key (NULL if not placed)
            positionX REAL,                  -- CORRECTED: Position X coordinate (NULL if not placed)
            positionY REAL,                  -- CORRECTED: Position Y coordinate (NULL if not placed)
            positionZ REAL,                  -- CORRECTED: Position Z coordinate (NULL if not placed)
            placedDimensionW REAL,           -- ADDED: Width dimension as placed (accounts for rotation)
            placedDimensionD REAL,           -- ADDED: Depth dimension as placed
            placedDimensionH REAL,           -- ADDED: Height dimension as placed

            -- Foreign Key constraint
            FOREIGN KEY (containerId) REFERENCES containers(containerId) -- CORRECTED: References 'containerId' column
                ON DELETE SET NULL
                ON UPDATE CASCADE
        );
    ");
    echo "Table 'items' created or already exists (Schema Updated).\n"; // Updated message

     // Logs Table (Keep as is - seems correct)
    $db->exec("
        CREATE TABLE IF NOT EXISTS logs (
            logId INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
            userId TEXT,
            actionType TEXT NOT NULL,
            itemId TEXT,
            detailsJson TEXT,
            FOREIGN KEY (itemId) REFERENCES items(itemId) ON DELETE SET NULL
        );
    ");
    echo "Table 'logs' created or already exists.\n";


    echo "Database schema initialized successfully!\n";

} catch (PDOException $e) {
    echo "Schema Initialization Error: " . $e->getMessage() . "\n";
    exit(1);
}

$db = null; // Close connection

?>