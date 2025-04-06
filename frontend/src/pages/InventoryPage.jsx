// frontend/src/pages/InventoryPage.jsx
import React, { useState, useEffect } from 'react';
// Import getInventory AND exportArrangement
import { getInventory, exportArrangement } from '../services/api';

function InventoryPage() {
  const [inventory, setInventory] = useState([]);
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
      try {
        // *** Assuming getInventory returns the array directly based on previous api.js ***
        // *** If getInventory returns { success, inventory }, access data.inventory ***
        const data = await getInventory();
        console.log("Inventory Data Received:", data); // Log received data
        setInventory(data || []); // Ensure it's an array
      } catch (err) {
        console.error("Failed to fetch inventory:", err);
        setError(`Failed to fetch inventory: ${err.message}`);
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
      a.href = url; a.download = filename;
      document.body.appendChild(a); a.click(); a.remove();
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

       {/* Export Button and Messages */}
       <div style={{ marginBottom: '15px', paddingBottom: '15px', borderBottom: '1px solid #eee' }}>
           <button onClick={handleExport} disabled={exportLoading} style={{ padding: '8px 15px' }}>
               {exportLoading ? 'Exporting...' : 'Export Current Arrangement (CSV)'}
           </button>
           {exportLoading && <span style={{ marginLeft: '10px' }}>Processing export...</span>}
           {exportError && <p style={{ color: 'red', marginTop: '5px' }}>Error: {exportError}</p>}
           {exportSuccessMessage && <p style={{ color: 'green', marginTop: '5px' }}>{exportSuccessMessage}</p>}
       </div>

      {loading && <p>Loading inventory...</p>}
      {error && <p style={{ color: 'red' }}>Error loading inventory: {error}</p>}

      {!loading && inventory.length === 0 && !error && (
        <p>No stowed items found in inventory.</p>
      )}

      {/* Ensure table structure is clean without extraneous whitespace nodes */}
      {!loading && inventory.length > 0 && (
        <table border="1" style={{ borderCollapse: 'collapse', width: '100%' }}>
          <thead>
            <tr style={{backgroundColor: '#f2f2f2'}}>{/* Direct children must be th */}
              <th style={tableHeaderStyle}>Item ID</th>
              <th style={tableHeaderStyle}>Name</th>
              <th style={tableHeaderStyle}>Container</th>
              <th style={tableHeaderStyle}>Position (X,Y,Z)</th>
              <th style={tableHeaderStyle}>Status</th>
              <th style={tableHeaderStyle}>Mass (kg)</th>
            </tr>
          </thead>
          <tbody>
            {inventory.map(item => (
              <tr key={item.itemId}>{/* Direct children must be td */}
                <td style={tableCellStyle}>{item.itemId}</td>
                <td style={tableCellStyle}>{item.name}</td>
                <td style={tableCellStyle}>{item.containerId || 'N/A'}</td>
                <td style={tableCellStyle}>
                  { (item.position && item.position.x != null && item.position.y != null && item.position.z != null)
                    ? `(${item.position.x?.toFixed(1)}, ${item.position.y?.toFixed(1)}, ${item.position.z?.toFixed(1)})`
                    : 'N/A'
                  }
                </td>
                <td style={tableCellStyle}>{item.status}</td>
                <td style={tableCellStyle}>{item.mass?.toFixed(1) ?? 'N/A'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

// Basic inline styles for table
const tableHeaderStyle = { border: '1px solid #ddd', padding: '8px', textAlign: 'left', backgroundColor: '#f2f2f2' };
const tableCellStyle = { border: '1px solid #ddd', padding: '8px', textAlign: 'left' };


export default InventoryPage;