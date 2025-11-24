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

// Define the new application maximum size globally for consistency
// 25 MB = 25 * 1024 * 1024 bytes
const MAX_UPLOAD_SIZE = 26214400; 

/**
 * Handles the single file upload process, including validation and moving the file.
 *
 * @param array|null $file_data The $_FILES entry (e.g., $_FILES["supporting_file"]).
 * @param string $upload_dir The target directory path.
 * @param array $allowed_extensions List of allowed file extensions. Defaults to JPG/JPEG only.
 * @return array An array containing 'success' (bool), 'error' (string), and file details (string|null).
 */
function handleFileUpload(?array $file_data, string $upload_dir, array $allowed_extensions = ['jpg', 'jpeg']): array {
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
    $file_extension = strtolower(pathinfo($result['original_file_name'], PATHINFO_EXTENSION));
    

    // --- Validation Checks ---
    
    // 1. Check file extension (using the passed parameter)
    if (!in_array($file_extension, $allowed_extensions)) {
        $ext_list = strtoupper(implode(', ', $allowed_extensions));
        $result['error'] = "Invalid file type. Only {$ext_list} files are allowed.";
        return $result;
    }
    
    // 2. Check file size (application limit)
    if ($file_data["size"] > MAX_UPLOAD_SIZE) { // <--- Updated to use constant
        $result['error'] = "File is too large. Max size is 25MB.";
        return $result;
    }
    
    // 3. MIME Type Check (The reliable way to check format, only for images)
    if (in_array($file_extension, ['jpg', 'jpeg'])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_data['tmp_name']);
        finfo_close($finfo);

        if ($mime_type !== 'image/jpeg') {
             $result['error'] = "File content verification failed. Only JPEG/JPG format is allowed.";
             return $result;
        }
    }

    // Create a unique file name and path (force .jpg for cleanliness if it was .jpeg)
    $final_extension = ($file_extension === 'jpeg' ? 'jpg' : $file_extension);
    $result['uploaded_file_name'] = uniqid("file_", true) . "." . $final_extension;
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


/**
 * Handles multiple file uploads, restricting to a list of allowed MIME types.
 *
 * @param array|null $files_array The $_FILES entry (e.g., $_FILES['supporting_files'])
 * @param string $upload_dir The target upload directory.
 * @param array $allowed_mime_types List of MIME types allowed. Defaults to image/jpeg only.
 * @return array Contains 'error' string and 'successful_uploads' array.
 */
function handleMultipleFileUpload($files_array, $upload_dir, $allowed_mime_types = ['image/jpeg']) {
    $error = "";
    $successful_uploads = [];
    // $max_file_size is now defined by the constant MAX_UPLOAD_SIZE

    // Check if files array is present and structured correctly
    if (empty($files_array) || !is_array($files_array) || !isset($files_array['name'])) {
        return ['error' => '', 'successful_uploads' => []];
    }
    
    // Ensure the upload directory exists
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            return ['error' => "Upload directory could not be created.", 'successful_uploads' => []];
        }
    }

    $num_files = count($files_array['name']);

    // Process each file one by one
    for ($i = 0; $i < $num_files; $i++) {
        $current_file = [
            'name' => $files_array['name'][$i] ?? '',
            'tmp_name' => $files_array['tmp_name'][$i] ?? '',
            'error' => $files_array['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files_array['size'][$i] ?? 0
        ];

        // 1. Skip if no file was selected for this index
        if ($current_file['error'] === UPLOAD_ERR_NO_FILE) {
            continue; 
        }

        // 2. Check for general upload errors
        if ($current_file['error'] !== UPLOAD_ERR_OK) {
            $error .= "Error uploading '{$current_file['name']}': Code {$current_file['error']}. ";
            continue;
        }

        // 3. Size Check (25MB limit)
        if ($current_file['size'] > MAX_UPLOAD_SIZE) { // <--- Updated to use constant
            $error .= "File '{$current_file['name']}' exceeds the 25MB size limit. ";
            continue; 
        }

        // 4. MIME Type Validation (Strictly JPEG)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $current_file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime_type, $allowed_mime_types)) {
            $error .= "File '{$current_file['name']}' is of type '{$mime_type}'. Only **JPEG/JPG** files are allowed. ";
            continue; // Skip this file
        }

        // 5. Sanitize and Move File
        $original_file_name = basename($current_file['name']);
        
        // Use a standard .jpg extension since we verified it's a JPEG
        $unique_filename = uniqid('req_', true) . '.jpg'; 

        $uploaded_file_path = $upload_dir . $unique_filename;

        if (move_uploaded_file($current_file['tmp_name'], $uploaded_file_path)) {
            $successful_uploads[] = [
                'uploaded_file_name' => $unique_filename,
                'original_file_name' => $original_file_name,
                'uploaded_file_path' => $uploaded_file_path
            ];
        } else {
            $error .= "Failed to save file '{$original_file_name}' on the server. ";
        }
    }

    return [
        'error' => $error,
        'successful_uploads' => $successful_uploads
    ];
}