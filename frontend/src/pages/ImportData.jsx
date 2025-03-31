// frontend/src/pages/ImportData.jsx
import React, { useState } from 'react';

function ImportData() {
  const [containerFile, setContainerFile] = useState(null);
  const [itemFile, setItemFile] = useState(null);
  const [message, setMessage] = useState('');
  const [isLoading, setIsLoading] = useState(false);

  const handleContainerFileChange = (event) => {
    setContainerFile(event.target.files[0]);
  };

  const handleItemFileChange = (event) => {
    setItemFile(event.target.files[0]);
  };

  const handleUpload = async (file, url, type) => {
    if (!file) {
      setMessage(`Please select a ${type} file.`);
      return;
    }

    setIsLoading(true);
    setMessage(`Uploading ${type} file...`);

    const formData = new FormData();
    formData.append('csvFile', file); // Key must match backend ('csvFile')

    // --- Replace with your actual backend API URL ---
    const API_BASE_URL = 'http://localhost:8000'; // Or port 3000 if using extension
    // --- --------------------------------------- ---

    try {
      const response = await fetch(`${API_BASE_URL}/api/import/${type}s`, { // Assuming endpoint is /api/import/containers or /api/import/items
        method: 'POST',
        body: formData, 
        // Headers are set automatically by fetch for FormData
      });

      const result = await response.json();

      if (!response.ok) {
        // Handle non-2xx responses
         setMessage(`Error uploading ${type}: ${result.message || response.statusText}`);
         console.error("Upload Error Response:", result);
      } else {
         setMessage(`${type} upload successful: ${result.message || (type === 'container' ? result.containersImported : result.itemsImported) + ` ${type}s imported.`}`);
         // Optionally clear the file input
         if (type === 'container') setContainerFile(null);
         if (type === 'item') setItemFile(null);
      }

    } catch (error) {
      setMessage(`Network or other error uploading ${type}: ${error.message}`);
      console.error("Upload Fetch Error:", error);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div>
      <h2>Import Data</h2>
      {message && <p>{message}</p>}

      <fieldset disabled={isLoading}>
        <legend>Import Containers</legend>
        <label htmlFor="container-file">Select Container CSV:</label>
        <input 
          type="file" 
          id="container-file" 
          accept=".csv" 
          onChange={handleContainerFileChange} 
        />
        <button onClick={() => handleUpload(containerFile, '/api/import/containers', 'container')}>
          Upload Containers
        </button>
      </fieldset>

      <br />

      <fieldset disabled={isLoading}>
        <legend>Import Items</legend>
        <label htmlFor="item-file">Select Item CSV:</label>
        <input 
          type="file" 
          id="item-file" 
          accept=".csv" 
          onChange={handleItemFileChange} 
        />
        <button onClick={() => handleUpload(itemFile, '/api/import/items', 'item')}>
          Upload Items
        </button>
      </fieldset>
    </div>
  );
}

export default ImportData;