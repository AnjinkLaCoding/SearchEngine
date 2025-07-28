import React, { useState, useRef } from 'react';
import axios from 'axios';

function App() {
  const [keyword, setKeyword] = useState('');
  const [results, setResults] = useState([]);
  const [selectedFiles, setSelectedFiles] = useState(null);
  const [uploadMessage, setUploadMessage] = useState('');
  const [loadingProgress, setLoadingProgress] = useState(0);
  const [isUploading, setIsUploading] = useState(false);
  const fileInputRef = useRef(null);

  // Search documents
  const search = async () => {
    const res = await fetch(`http://127.0.0.1:8000/api/search?keyword=${keyword}`);
    const data = await res.json();
    setResults(data);
  };

  // Upload single or multiple files
  const uploadFiles = async () => {
    if (!selectedFiles) {
      alert("Please select file(s) first!");
      return;
    }

    const formData = new FormData();
    for (let i = 0; i < selectedFiles.length; i++) {
      formData.append('files[]', selectedFiles[i]);
    }

    try {
      setIsUploading(true);
      setLoadingProgress(0);

      const response = await axios.post('http://127.0.0.1:8000/api/upload', formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
        onUploadProgress: (progressEvent) => {
          const percent = Math.round(
            (progressEvent.loaded * 100) / progressEvent.total
          );
          setLoadingProgress(percent);
        },
      });

      setIsUploading(false);
      setLoadingProgress(100);
      setSelectedFiles(null);

      alert(response.data.message);
      fileInputRef.current.value = null;
    } catch (error) {
      setIsUploading(false);
      console.error(error);
      alert('Failed to upload files.');
    }
  };

  const deleteByKeyword = async () => {
    const keyword = prompt("Enter keyword to delete documents containing it:");

    if (!keyword) {
      alert("No keyword provided.");
      return;
    }

    try {
      const response = await fetch(`http://localhost:8000/api/delete-by-keyword?keyword=${encodeURIComponent(keyword)}`, {
        method: 'DELETE'
      });

      const result = await response.json();

      alert(result.message);
    } catch (error) {
      console.error("Error deleting documents:", error);
      alert("An error occurred while deleting documents.");
    }
  };

  const deleteOldDocuments = async () => {
    const day = prompt("Enter the days to delete documents:");

    if (!day) {
      alert("No day provided.");
      return;
    }

    try {
      const response = await fetch(`http://localhost:8000/api/delete-old-documents?day=${encodeURIComponent(day)}`, {
        method: 'DELETE'
      });

      const result = await response.json();

      alert(result.message);
    } catch (error) {
      console.error("Error deleting documents:", error);
      alert("An error occurred while deleting documents.");
    }
  };

  const FixIndex = async () =>{
    try {
      const response = await fetch('http://127.0.0.1:8000/api/fix-index', {
        method: 'POST',
    });

    const data = await response.json();
    console.log(response.data);
    alert('Remove duplicate data success!');
    } catch (error) {
      console.error(error);
      alert('Failed to fix index.');
    }
  }

  return (
      <div className="min-h-screen w-full" style={{ margin: 0, padding: 0 }}>
        <h1 className="text-3xl mb-6 font-bold">📚 Document Search Engine</h1>

        {/* Upload Section */}
        <div className="mb-6">
          <input
            type="file"
            multiple
            ref={fileInputRef}
            onChange={(e) => setSelectedFiles(e.target.files)}
            className="block mb-2"
          />
          <button
            onClick={uploadFiles}
            className="bg-green-600 text-white px-4 py-2 mr-2 rounded"
          >
          Upload File(s)
          </button>
          {isUploading && (
            <div className="mt-4">
              <div className="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
                <div
                  className="bg-green-600 h-4 rounded-full transition-all duration-300"
                  style={{ width: `${loadingProgress}%` }}
                ></div>
              </div>
              <p className="text-sm mt-1">{loadingProgress}%</p>
            </div>
          )}

          {uploadMessage && <p className="mt-2 text-green-600">{uploadMessage}</p>}
        </div>

        <div className="mb-6">
          <button
            onClick={FixIndex}
            className="bg-yellow-600 text-white px-4 py-2 rounded"
          >
          Fix Index
          </button>
          
          <button
            onClick={deleteByKeyword}
            className="bg-red-600 text-white px-4 py-2 rounded"
          >
          Delete By Keyword
          </button>

          <button
            onClick={deleteOldDocuments}
            className="bg-blue-600 text-white px-4 py-2 rounded"
          >
          Delete By Day
          </button>
        </div>

        {/* Search Section */}
        <div className="flex gap-2 mb-4">
          <input
            value={keyword}
            onChange={e => setKeyword(e.target.value)}
            className="border p-2 flex-1"
            placeholder="Search keyword..."
          />
          <button
            onClick={search}
            className="bg-blue-600 text-white px-4 py-2 rounded"
          >
            Search
          </button>
        </div>

        {/* Search Results */}
        <div>
          {results.map(doc => (
            <div key={doc.id} className="mb-4 p-4 border rounded">
              <h2 className="text-xl font-semibold">{doc.title}</h2>
              <p className="text-gray-600 text-sm">{doc.file_name} ({doc.file_size} bytes)</p>
              {/* <p className="mt-2">{doc.content}</p> */}
            </div>
          ))}
        </div>
      </div>
  );
}

export default App;