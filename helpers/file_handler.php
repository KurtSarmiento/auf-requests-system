<?php
// helpers/file_handler.php

// Helper: convert php ini size like "8M" to bytes
function phpSizeToBytes(string $size): int {
    $unit = strtoupper(substr($size, -1));
    $bytes = (int) $size;
    switch ($unit) {
        case 'G': $bytes *= 1024;
        case 'M': $bytes *= 1024;
        case 'K': $bytes *= 1024;
    }
    return $bytes;
}

/**
 * Handles the file upload process, including validation and moving the file.
 *
 * @param array|null $file_data The $_FILES entry (e.g., $_FILES["supporting_file"]).
 * @param string $upload_dir The target directory path.
 * @return array An array containing 'success' (bool), 'error' (string), and file details (string|null).
 */
function handleFileUpload(?array $file_data, string $upload_dir): array {
    $result = [
        'success' => false,
        'error' => '',
        'uploaded_file_name' => null,
        'original_file_name' => null,
        'uploaded_file_path' => null,
    ];

    if ($file_data === null || !isset($file_data["error"])) {
        // No file field present or file was not selected (acceptable)
        $result['success'] = true;
        return $result;
    }

    $fileError = $file_data["error"];
    
    // Handle INI size error explicitly
    if ($fileError === UPLOAD_ERR_INI_SIZE || $fileError === UPLOAD_ERR_FORM_SIZE) {
        $result['error'] = "File is too large. Max allowed by server is " . ini_get('upload_max_filesize') . ".";
        return $result;
    } 
    
    if ($fileError === UPLOAD_ERR_NO_FILE) {
        // No file uploaded - acceptable
        $result['success'] = true;
        return $result;
    } 
    
    if ($fileError !== UPLOAD_ERR_OK) {
        $result['error'] = "A file was selected but an error occurred during upload (Code: " . $fileError . ").";
        return $result;
    }
    
    // Process OK file upload
    $result['original_file_name'] = basename($file_data["name"]);
    $file_extension = pathinfo($result['original_file_name'], PATHINFO_EXTENSION);
    $allowed_file_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    $max_size = 5 * 1024 * 1024; // 5MB application limit

    // Check file extension
    if (!in_array(strtolower($file_extension), $allowed_file_extensions)) {
        $result['error'] = "Invalid file type. Only PDF, images (JPG/PNG), and Word documents are allowed.";
        return $result;
    }
    
    // Check file size (application limit)
    if ($file_data["size"] > $max_size) {
        $result['error'] = "File is too large. Max size is 5MB.";
        return $result;
    }

    // Create a unique file name and path
    $result['uploaded_file_name'] = uniqid("file_") . "." . $file_extension;
    $result['uploaded_file_path'] = $upload_dir . $result['uploaded_file_name'];

    // Ensure the upload directory exists
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
             $result['error'] = "Failed to create upload directory: " . $upload_dir;
             return $result;
        }
    }

    // Move the uploaded file
    if (!move_uploaded_file($file_data["tmp_name"], $result['uploaded_file_path'])) {
        $result['error'] = "Failed to move uploaded file.";
        return $result;
    }
    
    $result['success'] = true;
    return $result;
}
?>