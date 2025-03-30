<?php // backend/setup_obstruction_test.php

require_once __DIR__ . '/database.php'; // Include the connection helper

echo "Attempting to set up obstruction test case...\n";
echo "---------------------------------------------\n";

$db = getDbConnection();

if ($db === null) {
    echo "Error: Could not connect to the database.\n";
    exit(1);
}

try {
    // Ensure items exist first (optional, but safer)
    // You might already have these from import_items
    $db->exec("INSERT OR IGNORE INTO items (itemId, name, width, depth, height, priority) VALUES ('ITM001', 'Food Pack Alpha', 10, 10, 20, 80);");
    $db->exec("INSERT OR IGNORE INTO items (itemId, name, width, depth, height, priority) VALUES ('ITM003', 'Screwdriver Set', 20, 15, 5, 60);");
    $db->exec("INSERT OR IGNORE INTO items (itemId, name, width, depth, height, priority) VALUES ('OTHER01', 'Obstacle', 5, 5, 5, 50);"); // Another potential item

    // --- CORE LOGIC: Place items for the test ---

    // 1. Clear previous placements for these specific items
    $clearSql = "UPDATE items SET currentContainerId = NULL, pos_w = NULL, pos_d = NULL, pos_h = NULL 
                 WHERE itemId IN ('ITM001', 'ITM003', 'OTHER01')";
    $stmtClear = $db->prepare($clearSql);
    $stmtClear->execute();
    echo "Cleared previous placements for test items.\n";

    // 2. Place ITM003 (the obstructing item) at the front
    $placeObstacleSql = "UPDATE items SET 
                            currentContainerId = 'contA', 
                            pos_w = 10, 
                            pos_d = 0,  -- At the very front
                            pos_h = 0,
                            status = 'stowed' 
                         WHERE itemId = 'ITM003'";
    $stmtObstacle = $db->prepare($placeObstacleSql);
    if ($stmtObstacle->execute()) {
        echo "Placed obstructing item ITM003 at (10, 0, 0) in contA.\n";
    } else {
        echo "Error placing obstructing item ITM003: " . print_r($stmtObstacle->errorInfo(), true) . "\n";
    }

    // 3. Place ITM001 (the target item) directly behind ITM003
    //    Make sure its position (pos_d = 15) starts *after* ITM003's depth (which is 15)
    //    Let's place it at Y=15 for a clear gap test, or Y=5 to be closer. Let's use Y=5.
    $placeTargetSql = "UPDATE items SET 
                            currentContainerId = 'contA', 
                            pos_w = 10,  -- Same X as ITM003
                            pos_d = 5,   -- BEHIND ITM003 (ITM003 depth=15, so Y=5 is behind Y=0)
                            pos_h = 0,   -- Same Z as ITM003
                            status = 'stowed' 
                        WHERE itemId = 'ITM001'";
    $stmtTarget = $db->prepare($placeTargetSql);
     if ($stmtTarget->execute()) {
        echo "Placed target item ITM001 at (10, 5, 0) in contA (behind ITM003).\n";
    } else {
        echo "Error placing target item ITM001: " . print_r($stmtTarget->errorInfo(), true) . "\n";
    }

   // Optional: Place another item NOT involved in obstruction for completeness
    $placeOtherSql = "UPDATE items SET currentContainerId = 'contA', pos_w = 0, pos_d = 0, pos_h = 0, status = 'stowed' WHERE itemId = 'OTHER01'";
    $stmtOther = $db->prepare($placeOtherSql);
    $stmtOther->execute();
    echo "Placed OTHER01 at (0,0,0) in contA.\n";


} catch (PDOException $e) {
    echo "Error setting up test case: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    $db = null; // Close connection
}

echo "---------------------------------------------\n";
echo "Obstruction test case setup complete.\n";
exit(0);

?>