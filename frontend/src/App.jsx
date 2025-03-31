import './App.css' // Keep or remove default CSS import as desired
import ImportData from './pages/ImportData'; // Make sure this import is correct

function App() {
  return (
    <> 
      <h1>Space Cargo Management</h1>
      <hr /> {/* Added a separator */}
      <ImportData /> 
      {/* Add other page components later */}
    </>
  )
}

export default App