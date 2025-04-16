// frontend/src/pages/InventoryPage.jsx
import React, { useState, useEffect } from 'react';
// Import getInventory AND exportArrangement
import { getInventory, exportArrangement } from '../services/api';

function InventoryPage() {
  const [inventory, setInventory] = useState([]); // State holds the array of items
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  // Add State for Export
  const [exportLoading, setExportLoading] = useState(false);
  const [exportError, setExportError] = useState(null);
  const [exportSuccessMessage, setExportSuccessMessage] = useState('');


  // Fetch inventory on component mount
  useEffect(() => {
    const fetchInventory = async () => {
      setLoading(true);
      setError(null);
      setInventory([]); // Clear previous inventory while loading
      try {
        // Fetch the object { success, inventory }
        const result = await getInventory(); // Rename to 'result' for clarity
        console.log("Inventory API Response:", result); // Log the full response

        // --- CORRECTED DATA ACCESS ---
        // Check if the fetch was successful and the inventory array exists
        if (result && result.success === true && Array.isArray(result.inventory)) {
             setInventory(result.inventory); // Set state with the inventory array
        } else {
            // Handle cases where data is missing or success is false
            console.error("Invalid inventory data received:", result);
             setInventory([]); // Set to empty array on failure
             // Optionally set an error based on result.message if needed
             setError(result?.message || "Received invalid inventory data format.");
        }
        // --- END CORRECTION ---

      } catch (err) {
        console.error("Failed to fetch inventory:", err);
        setError(`Failed to fetch inventory: ${err.message}`);
        setInventory([]); // Ensure inventory is empty on fetch error
      } finally {
        setLoading(false);
      }
    };
    fetchInventory();
  }, []); // Empty dependency array means run once on mount


  // Handler for Export Button
  const handleExport = async () => {
    setExportLoading(true);
    setExportError(null);
    setExportSuccessMessage('');

    try {
      const response = await exportArrangement(); // Returns raw Response object

      if (!response.ok) {
           let errorMsg = `Export failed with status: ${response.status}`;
           try { const text = await response.text(); if(text) errorMsg += ` - ${text}`; } catch(e) { /* Ignore */ }
          throw new Error(errorMsg);
      }
      // --- File Download Logic ---
      const blob = await response.blob();
      const disposition = response.headers.get('content-disposition');
      let filename = 'stowage_arrangement.csv'; // Default filename
      if (disposition && disposition.includes('attachment')) {
        const filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
        const matches = filenameRegex.exec(disposition);
        if (matches != null && matches[1]) { filename = matches[1].replace(/['"]/g, ''); }
      }
      // Create temporary link and trigger download
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.style.display = 'none'; // Hide the link
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a); // Clean up the link
      window.URL.revokeObjectURL(url); // Release the object URL
      // --- End File Download Logic ---
      setExportSuccessMessage(`Successfully exported ${filename}`);

    } catch (err) {
        console.error("Export failed:", err);
        setExportError(`Export failed: ${err.message}`);
    } finally {
        setExportLoading(false);
    }
  };


  // --- Render Logic ---
  return (
    <div style={{ padding: '20px', fontFamily: 'sans-serif' }}>
      <h2>Inventory / Stowage View</h2>

       {/* Export Button and Messages */}
       <div style={{ marginBottom: '15px', paddingBottom: '15px', borderBottom: '1px solid #eee' }}>
           <button onClick={handleExport} disabled={exportLoading || loading || inventory.length === 0} style={{ padding: '8px 15px' }}>
               {exportLoading ? 'Exporting...' : 'Export Current Arrangement (CSV)'}
           </button>
            {/* Optionally disable export if inventory is empty or loading */}
           {exportLoading && <span style={{ marginLeft: '10px' }}>Processing export...</span>}
           {exportError && <p style={{ color: 'red', marginTop: '5px' }}>Error: {exportError}</p>}
           {exportSuccessMessage && <p style={{ color: 'green', marginTop: '5px' }}>{exportSuccessMessage}</p>}
       </div>

      {loading && <p>Loading inventory...</p>}
      {/* Display error only if not loading */}
      {error && !loading && <p style={{ color: 'red' }}>Error loading inventory: {error}</p>}

      {/* Display message only if not loading, no error, and inventory is empty */}
      {!loading && !error && inventory.length === 0 && (
        <p>No stowed items found in inventory.</p>
      )}

      {/* Display table only if not loading and inventory has items */}
      {!loading && inventory.length > 0 && (
        <table border="1" style={{ borderCollapse: 'collapse', width: '100%', marginTop: '10px' }}>
          <thead>
            <tr style={{backgroundColor: '#f2f2f2'}}>
              <th style={tableHeaderStyle}>Item ID</th>
              <th style={tableHeaderStyle}>Name</th>
              <th style={tableHeaderStyle}>Container</th>
              <th style={tableHeaderStyle}>Position (X,Y,Z)</th>
              {/* Displaying original dimensions and placed dimensions might be useful */}
              {/* <th style={tableHeaderStyle}>Orig Dim (W,D,H)</th> */}
              {/* <th style={tableHeaderStyle}>Placed Dim (W,D,H)</th> */}
              <th style={tableHeaderStyle}>Status</th>
              <th style={tableHeaderStyle}>Mass (kg)</th>
              {/* Add other relevant columns like expiry, uses left? */}
              { <th style={tableHeaderStyle}>Expiry</th> }
              { <th style={tableHeaderStyle}>Uses Left</th> }
            </tr>
          </thead>
          <tbody>
            {inventory.map(item => (
              // Use item.itemId for the key assuming it's unique
              <tr key={item.itemId}>
                <td style={tableCellStyle}>{item.itemId}</td>
                <td style={tableCellStyle}>{item.name}</td>
                <td style={tableCellStyle}>{item.containerId || 'N/A'}</td>
                <td style={tableCellStyle}>
                  {/* Check if position object and coordinates exist */}
                  { (item.position && item.position.x != null && item.position.y != null && item.position.z != null)
                    ? `(${item.position.x?.toFixed(1)}, ${item.position.y?.toFixed(1)}, ${item.position.z?.toFixed(1)})`
                    : 'N/A' // Indicate if position is missing
                  }
                </td>
                {/* Example for showing placed dimensions */}
                {/* <td style={tableCellStyle}>
                     { (item.placedDimensions && item.placedDimensions.w != null)
                         ? `(${item.placedDimensions.w?.toFixed(1)}, ${item.placedDimensions.d?.toFixed(1)}, ${item.placedDimensions.h?.toFixed(1)})`
                         : 'N/A'
                     }
                </td> */}
                <td style={tableCellStyle}>{item.status}</td>
                 {/* Use nullish coalescing for potentially null mass */}
                <td style={tableCellStyle}>{item.mass?.toFixed(1) ?? 'N/A'}</td>
                { <td style={tableCellStyle}>{item.expiryDate || 'N/A'}</td> }
                { <td style={tableCellStyle}>{item.remainingUses ?? 'N/A'}</td> }
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

// Basic inline styles for table
const tableHeaderStyle = { border: '1px solid #ddd', padding: '8px', textAlign: 'left', backgroundColor: '#f2f2f2', fontWeight:'bold' };
const tableCellStyle = { border: '1px solid #ddd', padding: '8px', textAlign: 'left', verticalAlign: 'top' }; // Align top for consistency

export default InventoryPage;