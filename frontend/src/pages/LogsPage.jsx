// frontend/src/pages/LogsPage.jsx
import React, { useState } from 'react';
import { getLogs } from '../services/api'; // Assuming getLogs is correctly defined in api.js

function LogsPage() {
  // State for filter inputs
  const [filters, setFilters] = useState({
    startDate: '',
    endDate: '',
    itemId: '',
    userId: '',
    actionType: ''
  });
  // State for the fetched log entries
  const [logs, setLogs] = useState([]);
  // State for loading indicator
  const [loading, setLoading] = useState(false);
  // State for displaying errors
  const [error, setError] = useState(null);
  // State for informational messages (e.g., "No logs found")
  const [lastFetchMessage, setLastFetchMessage] = useState('');

  // Handler for filter input changes
  const handleFilterChange = (e) => {
    const { name, value } = e.target;
    setFilters(prev => ({ ...prev, [name]: value }));
  };

  // Handler for fetching logs based on filters
  const handleFetchLogs = async (e) => {
     e.preventDefault(); // Prevent default form submission if called from form
    setLoading(true);
    setError(null);
    setLogs([]); // Clear previous logs
    setLastFetchMessage(''); // Clear previous message

    // Create a clean filter object, removing empty values so they aren't sent as query params
     const activeFilters = Object.entries(filters)
        .filter(([key, value]) => value != null && value !== '') // Ensure value is not null or empty string
        .reduce((obj, [key, value]) => {
            obj[key] = value;
            return obj;
        }, {});

    console.log("Fetching logs with filters:", activeFilters); // Log active filters

    try {
      // Call the API function
      const response = await getLogs(activeFilters); // Expects { success, logs, message }
      console.log("Logs API Response:", response); // Log the full response

      // Check the success flag from the API response
      if (response.success) {
          setLogs(response.logs || []); // Set logs state with the array from response.logs
          setLastFetchMessage(response.message || `Fetched ${response.logs?.length || 0} logs.`); // Use message from API
          if (!response.logs || response.logs.length === 0) {
             // Optionally update message more specifically if needed
             setLastFetchMessage(response.message || 'No logs found matching the specified criteria.');
          }
      } else {
          // Handle API returning success: false
          setError(response.message || "Failed to fetch logs (API error).");
          setLogs([]); // Ensure logs are cleared
          setLastFetchMessage('');
      }

    } catch (err) {
      // Handle network errors or other exceptions during fetch
      console.error("Fetch logs failed:", err);
      setError(`Failed to fetch logs: ${err.message}`);
      setLogs([]); // Clear logs on error
      setLastFetchMessage('');
    } finally {
      // Always set loading to false after attempt
      setLoading(false);
    }
  };

  // --- Helper function to render the 'details' object nicely ---
  const renderLogDetails = (details) => {
      if (!details) {
          return 'N/A'; // Handle null or undefined details
      }
      // Check if it's an object (already decoded by backend)
      if (typeof details === 'object' && details !== null) {
         // Display as key-value pairs
         return (
            <ul style={{ margin: 0, paddingLeft: '15px', fontSize: '0.9em', listStyle: 'none' }}>
                {Object.entries(details).map(([key, value]) => (
                    // Simple stringify for value, might need more complex rendering for nested objects
                    <li key={key} style={{ marginBottom: '2px' }}>
                        <strong style={{ color: '#333' }}>{key}:</strong> {JSON.stringify(value)}
                    </li>
                ))}
                {Object.keys(details).length === 0 && <li>(No details)</li>}
            </ul>
         )
      }
       // If backend failed to decode and sent an error object inside details
       if (typeof details === 'object' && details?.error) {
            return <span style={{color:'orange'}}>Error decoding: {details.error}</span>;
       }
      // Fallback if it's not an object (e.g., a simple string or number)
      return String(details);
  };

  // --- JSX Rendering ---
  return (
    <div style={{ padding: '20px', fontFamily: 'sans-serif' }}>
      <h2>View System Logs</h2>

      {/* Filter Form */}
      <form onSubmit={handleFetchLogs} style={{ border: '1px solid #eee', padding: '15px', marginBottom: '20px', backgroundColor: '#f9f9f9', borderRadius: '5px' }}>
         <h4>Filter Logs</h4>
         <div style={{ display: 'flex', flexWrap: 'wrap', gap: '15px' }}>
            <div>
                <label htmlFor="startDate">Start Date: </label><br/>
                <input id="startDate" type="date" name="startDate" value={filters.startDate} onChange={handleFilterChange} disabled={loading} style={{ padding: '5px' }}/>
            </div>
            <div>
                <label htmlFor="endDate">End Date: </label><br/>
                <input id="endDate" type="date" name="endDate" value={filters.endDate} onChange={handleFilterChange} disabled={loading} style={{ padding: '5px' }}/>
            </div>
            <div>
                <label htmlFor="itemId">Item ID: </label><br/>
                <input id="itemId" type="text" name="itemId" value={filters.itemId} onChange={handleFilterChange} placeholder="e.g., ITM001" disabled={loading} style={{ padding: '5px' }}/>
            </div>
            <div>
                <label htmlFor="userId">User ID: </label><br/>
                <input id="userId" type="text" name="userId" value={filters.userId} onChange={handleFilterChange} placeholder="e.g., astronaut_01" disabled={loading} style={{ padding: '5px' }}/>
            </div>
            <div>
                <label htmlFor="actionType">Action Type: </label><br/>
                {/* Ensure these values match backend logging */}
                <select id="actionType" name="actionType" value={filters.actionType} onChange={handleFilterChange} disabled={loading} style={{ padding: '5px' }}>
                    <option value="">Any</option>
                    <option value="import_containers">Import Containers</option>
                    <option value="import_items">Import Items</option>
                    <option value="placement">Placement (Algorithm)</option>
                    <option value="retrieve">Retrieve</option>
                    <option value="manual_place">Manual Place</option>
                    <option value="disposal">Disposal (Undocking)</option>
                    <option value="simulate">Simulate Day</option>
                    {/* Add 'export', 'waste_identify', 'waste_plan' if logged */}
                </select>
            </div>
         </div>
         <button type="submit" style={{marginTop: '15px', padding: '8px 15px'}} disabled={loading}>
            {loading ? 'Fetching...' : 'Fetch Logs'}
        </button>
      </form>

      {/* Error Display Area */}
       {error && <p style={{ color: 'red', border: '1px solid red', padding: '10px' }}>Error: {error}</p>}

      {/* Log Results Area */}
       <h3>Log Entries</h3>
       {loading && <p>Loading logs...</p>}
       {!loading && <p style={{ fontStyle: 'italic', color: '#555' }}>{lastFetchMessage || 'Click "Fetch Logs" to view entries.'}</p>}

       {logs.length > 0 && !loading && (
           <div style={{ maxHeight: '500px', overflowY: 'auto', border: '1px solid #ccc' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                    <tr style={{backgroundColor: '#f2f2f2'}}>
                        <th style={tableHeaderStyle}>Timestamp</th>
                        <th style={tableHeaderStyle}>Action</th>
                        <th style={tableHeaderStyle}>User ID</th>
                        <th style={tableHeaderStyle}>Item ID</th>
                        <th style={tableHeaderStyle}>Details</th>
                    </tr>
                </thead>
                <tbody>
                    {logs.map(log => (
                        <tr key={log.logId}>
                            {/* Format timestamp, handle nulls for other fields */}
                            <td style={tableCellStyle} title={log.timestamp}>{log.timestamp ? new Date(log.timestamp).toLocaleString() : 'N/A'}</td>
                            <td style={tableCellStyle}>{log.actionType || 'N/A'}</td>
                            <td style={tableCellStyle}>{log.userId || 'N/A'}</td>
                            <td style={tableCellStyle}>{log.itemId || 'N/A'}</td>
                            <td style={tableCellStyle}>{renderLogDetails(log.details)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
           </div>
       )}
    </div>
  );
}

// Basic inline styles for table readability
const tableHeaderStyle = { border: '1px solid #ddd', padding: '8px', textAlign: 'left', backgroundColor: '#f2f2f2', position: 'sticky', top: 0 }; // Sticky header
const tableCellStyle = { border: '1px solid #ddd', padding: '8px', textAlign: 'left', verticalAlign: 'top' }; // Align top for better details view


export default LogsPage;