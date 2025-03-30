<?php // backend/view_items.php

require_once __DIR__ . '/database.php'; // Include the connection helper

echo "Attempting to view data in the 'items' table...\n";
echo "-------------------------------------------------\n";

$db = getDbConnection();

if ($db === null) {
    echo "Error: Could not connect to the database.\n";
    exit(1);
}

try {
    // Select key columns for viewing, order by itemId
    $sql = "SELECT itemId, name, status, currentContainerId, pos_w, pos_d, pos_h, priority, usageLimit, remainingUses, expiryDate 
            FROM items 
            ORDER BY itemId"; 
    $stmt = $db->prepare($sql);
    $stmt->execute();

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC); // Fetch all rows

    if (empty($items)) {
        echo "The 'items' table is currently empty.\n";
    } else {
        echo "Found " . count($items) . " items:\n\n";
        
        // Determine max lengths for formatting (optional, makes output cleaner)
        $maxIdLen = 10; $maxNameLen = 20; $maxContLen = 12; $maxStatLen = 15;
        foreach ($items as $item) {
             if (strlen($item['itemId']) > $maxIdLen) $maxIdLen = strlen($item['itemId']);
             if (strlen($item['name']) > $maxNameLen) $maxNameLen = strlen($item['name']);
             if (strlen($item['currentContainerId']) > $maxContLen) $maxContLen = strlen($item['currentContainerId']);
             if (strlen($item['status']) > $maxStatLen) $maxStatLen = strlen($item['status']);
        }
        $maxNameLen = min($maxNameLen, 30); // Cap name length display

        // Print a header row using calculated max lengths + padding
        printf("%-{$maxIdLen}s | %-{$maxNameLen}s | %-{$maxStatLen}s | %-{$maxContLen}s | %-6s | %-6s | %-6s | %-8s | %-10s | %-10s\n", 
               "Item ID", "Name", "Status", "Container", "Pos W", "Pos D", "Pos H", "Priority", "Rem. Uses", "Expiry");
        // Print separator line based on calculated lengths
        printf(str_repeat('-', $maxIdLen + 3 + $maxNameLen + 3 + $maxStatLen + 3 + $maxContLen + 3 + 7 + 7 + 7 + 9 + 11 + 11) . "\n"); 

        // Print each item row, truncating name if necessary
        foreach ($items as $item) {
            printf("%-{$maxIdLen}s | %-{$maxNameLen}s | %-{$maxStatLen}s | %-{$maxContLen}s | %-6s | %-6s | %-6s | %-8s | %-10s | %-10s\n",
                   $item['itemId'] ?? 'N/A',
                   substr($item['name'] ?? 'N/A', 0, $maxNameLen), // Truncate long names
                   $item['status'] ?? 'N/A',
                   $item['currentContainerId'] ?? 'None',
                   $item['pos_w'] ?? '-',
                   $item['pos_d'] ?? '-',
                   $item['pos_h'] ?? '-',
                   $item['priority'] ?? 'N/A',
                   $item['remainingUses'] ?? 'N/A', // Display N/A if null
                   $item['expiryDate'] ?? 'None' // Display None if null
            );
        }
    }

} catch (PDOException $e) {
    echo "Error viewing 'items' table: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    $db = null; // Close connection
}

echo "\n-------------------------------------------------\n";
echo "Finished viewing 'items' table.\n";
exit(0);

?>