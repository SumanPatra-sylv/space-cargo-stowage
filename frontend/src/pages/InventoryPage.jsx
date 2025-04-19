// frontend/src/pages/InventoryPage.jsx
import React, { useState, useEffect } from 'react';
import { getInventory, exportArrangement } from '../services/api'; // Assuming these are correct

function InventoryPage() {
  // --- MODIFIED: State now holds an object keyed by containerId ---
  const [inventoryByContainer, setInventoryByContainer] = useState({});
  // --- END MODIFICATION ---

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  // State for Export (remains the same)
  const [exportLoading, setExportLoading] = useState(false);
  const [exportError, setExportError] = useState(null);
  const [exportSuccessMessage, setExportSuccessMessage] = useState('');

  // Fetch inventory on component mount
  useEffect(() => {
    const fetchInventory = async () => {
      setLoading(true);
      setError(null);
      // --- MODIFIED: Clear state with an empty object ---
      setInventoryByContainer({});
      // --- END MODIFICATION ---
      try {
        // Fetch the object { success, inventoryByContainer }
        const result = await getInventory();
        console.log("Inventory API Response:", result); // Log the full response

        // --- MODIFIED: Check for the new data structure ---
        if (
          result &&
          result.success === true &&
          typeof result.inventoryByContainer === 'object' && // Check if it's an object
          result.inventoryByContainer !== null // Ensure it's not null
        ) {
          setInventoryByContainer(result.inventoryByContainer); // Set state with the grouped object
        } else {
          // Handle cases where data is missing or success is false
          console.error("Invalid inventory data received:", result);
          setInventoryByContainer({}); // Set to empty object on failure
          setError(result?.error || result?.message || "Received invalid inventory data format.");
        }
        // --- END MODIFICATION ---

      } catch (err) {
        console.error("Failed to fetch inventory:", err);
        setError(`Failed to fetch inventory: ${err.message}`);
        setInventoryByContainer({}); // Ensure inventory is empty object on fetch error
      } finally {
        setLoading(false);
      }
    };
    fetchInventory();
  }, []); // Empty dependency array means run once on mount

  // Handler for Export Button (remains the same)
  const handleExport = async () => {
    setExportLoading(true);
    setExportError(null);
    setExportSuccessMessage('');
    try {
      const response = await exportArrangement();
      if (!response.ok) {
        let errorMsg = `Export failed with status: ${response.status}`;
        try { const text = await response.text(); if (text) errorMsg += ` - ${text}`; } catch (e) { /* Ignore */ }
        throw new Error(errorMsg);
      }
      const blob = await response.blob();
      const disposition = response.headers.get('content-disposition');
      let filename = 'stowage_arrangement.csv';
      if (disposition && disposition.includes('attachment')) {
        const filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
        const matches = filenameRegex.exec(disposition);
        if (matches != null && matches[1]) { filename = matches[1].replace(/['"]/g, ''); }
      }
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.style.display = 'none';
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      window.URL.revokeObjectURL(url);
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

      {/* Export Button and Messages (remains the same) */}
      <div style={{ marginBottom: '15px', paddingBottom: '15px', borderBottom: '1px solid #eee' }}>
        <button
          onClick={handleExport}
          // --- MODIFIED: Disable export if inventory object is empty ---
          disabled={exportLoading || loading || Object.keys(inventoryByContainer).length === 0}
          // --- END MODIFICATION ---
          style={{ padding: '8px 15px' }}
        >
          {exportLoading ? 'Exporting...' : 'Export Current Arrangement (CSV)'}
        </button>
        {exportLoading && <span style={{ marginLeft: '10px' }}>Processing export...</span>}
        {exportError && <p style={{ color: 'red', marginTop: '5px' }}>Error: {exportError}</p>}
        {exportSuccessMessage && <p style={{ color: 'green', marginTop: '5px' }}>{exportSuccessMessage}</p>}
      </div>

      {loading && <p>Loading inventory...</p>}
      {error && !loading && <p style={{ color: 'red' }}>Error loading inventory: {error}</p>}

      {/* --- MODIFIED: Display message if inventory object is empty --- */}
      {!loading && !error && Object.keys(inventoryByContainer).length === 0 && (
        <p>No stowed items found in inventory.</p>
      )}
      {/* --- END MODIFICATION --- */}

      {/* --- MODIFIED: Map over containers (keys of the object) --- */}
      {!loading && !error && Object.keys(inventoryByContainer).length > 0 && (
        <div>
          {Object.keys(inventoryByContainer).map(containerId => (
            <div key={containerId} style={{ marginBottom: '30px' }}>
              {/* Display Container ID as a heading */}
              <h3 style={{ borderBottom: '2px solid #ccc', paddingBottom: '5px' }}>
                Container: {containerId} ({inventoryByContainer[containerId].length} items)
              </h3>

              {/* Render a table for items within this container */}
              {inventoryByContainer[containerId].length > 0 ? (
                <table border="1" style={{ borderCollapse: 'collapse', width: '100%', marginTop: '10px' }}>
                  <thead>
                    <tr style={{ backgroundColor: '#f2f2f2' }}>
                      {/* --- MODIFIED: Add Priority Column --- */}
                      <th style={tableHeaderStyle}>Priority</th>
                      {/* --- END MODIFICATION --- */}
                      <th style={tableHeaderStyle}>Item ID</th>
                      <th style={tableHeaderStyle}>Name</th>
                      <th style={tableHeaderStyle}>Position (X,Y,Z)</th>
                      <th style={tableHeaderStyle}>Placed Dim (W,D,H)</th> {/* Example */}
                      <th style={tableHeaderStyle}>Status</th>
                      <th style={tableHeaderStyle}>Mass (kg)</th>
                      <th style={tableHeaderStyle}>Expiry</th>
                      <th style={tableHeaderStyle}>Uses Left</th>
                      <th style={tableHeaderStyle}>Last Updated</th> {/* Example */}
                    </tr>
                  </thead>
                  <tbody>
                    {/* Map over items in the specific container array */}
                    {inventoryByContainer[containerId].map(item => (
                      <tr key={item.itemId}>
                        {/* --- MODIFIED: Display Priority --- */}
                        <td style={{...tableCellStyle, textAlign: 'center', fontWeight: 'bold'}}>{item.priority ?? 'N/A'}</td>
                        {/* --- END MODIFICATION --- */}
                        <td style={tableCellStyle}>{item.itemId}</td>
                        <td style={tableCellStyle}>{item.name}</td>
                        <td style={tableCellStyle}>
                          {(item.position && item.position.x != null)
                            ? `(${item.position.x?.toFixed(1)}, ${item.position.y?.toFixed(1)}, ${item.position.z?.toFixed(1)})`
                            : 'N/A'
                          }
                        </td>
                         <td style={tableCellStyle}>
                             {(item.placedDimensions && item.placedDimensions.w != null)
                                 ? `(${item.placedDimensions.w?.toFixed(1)}, ${item.placedDimensions.d?.toFixed(1)}, ${item.placedDimensions.h?.toFixed(1)})`
                                 : 'N/A'
                             }
                        </td>
                        <td style={tableCellStyle}>{item.status}</td>
                        <td style={tableCellStyle}>{item.mass?.toFixed(1) ?? 'N/A'}</td>
                        <td style={tableCellStyle}>{item.expiryDate || 'N/A'}</td>
                        <td style={tableCellStyle}>{item.remainingUses ?? 'N/A'}</td>
                        <td style={tableCellStyle}>{item.lastUpdated ? new Date(item.lastUpdated).toLocaleString() : 'N/A'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <p>No items found in this container.</p> // Should not happen if container key exists, but good fallback
              )}
            </div>
          ))}
        </div>
      )}
      {/* --- END MODIFICATION --- */}
    </div>
  );
}

// Basic inline styles for table (remain the same)
const tableHeaderStyle = { border: '1px solid #ddd', padding: '8px', textAlign: 'left', backgroundColor: '#f2f2f2', fontWeight: 'bold' };
const tableCellStyle = { border: '1px solid #ddd', padding: '8px', textAlign: 'left', verticalAlign: 'top' };

export default InventoryPage;