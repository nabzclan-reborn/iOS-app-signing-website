<?php
// start session for CSRF protection
session_start();

// turn on all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// generate a CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    // validate CSRF token
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Invalid CSRF token.';
        send_json_response(false, $error_message, 'error');
        exit;
    }

    $api_token = '111-111-111-111-111'; // Get Token: https://dashboard.api-aries.online/
    $api_endpoint = 'https://api.api-aries.online/v1/cococloud/app-signer';

    if (isset($_FILES['ipa_file']) && $_FILES['ipa_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['ipa_file'];
        
        // Define maximum file size (20GB)
        $max_file_size = 20 * 1024 * 1024 * 1024; // 20GB
        $chunk_size = 20 * 1024 * 1024; // 20MB

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_message = 'File upload error: ' . $file['error'];
            send_json_response(false, $error_message, 'error');
            exit;
        }

        $file_name = pathinfo($file['name'], PATHINFO_FILENAME);
        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        
        if ($file_extension !== 'ipa') {
            $error_message = 'Invalid file type. Please upload an IPA file.';
            send_json_response(false, $error_message, 'error');
            exit;
        }

        if ($file['size'] > $max_file_size) {
            $error_message = 'File size exceeds the maximum limit of 20 GB.';
            send_json_response(false, $error_message, 'error');
            exit;
        }

        if (!file_exists('uploads/')) {
            mkdir('uploads/', 0777, true);
        }

        $unique_file_name = uniqid() . ".ipa";


        $chunk_paths = upload_chunks($file, $chunk_size, $unique_file_name);

  
        $full_file_path = reassemble_file($chunk_paths, $unique_file_name);

  
        $ipa_url = 'https://website/uploads/' . $unique_file_name;

    } elseif (isset($_POST['ipa_direct_link']) && !empty($_POST['ipa_direct_link'])) {

        $ipa_url = filter_var($_POST['ipa_direct_link'], FILTER_VALIDATE_URL);
        
        if ($ipa_url === false) {
            $error_message = 'Invalid URL. Please provide a valid direct link to the IPA file.';
            send_json_response(false, $error_message, 'error');
            exit;
        }

    } else {
        $error_message = 'No file or direct link provided.';
        send_json_response(false, $error_message, 'error');
        exit;
    }

    $curl = curl_init();
    $api_url_with_params = "$api_endpoint?app=$ipa_url";
    curl_setopt_array($curl, [
        CURLOPT_URL => $api_url_with_params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "APITOKEN: $api_token",
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 9999, // Set timeout leave this at 9999 so it can support big files
    ]);

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if (curl_errno($curl)) {
        $error_message = 'cURL error: ' . curl_error($curl);
        send_json_response(false, $error_message, 'error');
        exit;
    }

    curl_close($curl);

    if ($http_code === 200) {
        $response_data = json_decode($response, true);
        if (isset($response_data['status']) && $response_data['status'] === 'success') {
            $success_message = "
            <h3>App Signed Successfully!</h3>
            <p><strong>Original App Name:</strong> " . $response_data['original_app_name'] . "</p>
            <p><strong>Bundle ID:</strong> " . $response_data['bundle_id'] . "</p>
            <p><strong>Signed IPA File:</strong> <a href='" . $response_data['signed_ipa_file'] . "'>Download Signed IPA</a></p>
            <p><strong>Install Signed IPA:</strong> <a href='" . $response_data['plist_install_url'] . "'>Install Signed IPA</a></p>
            <p><strong>Expiration Time:</strong> " . $response_data['exp_time'] . "</p>";
            send_json_response(true, $success_message, 'completed');
        } else {
            $error_message = "Error signing the app: " . $response_data['message'];
            send_json_response(false, $error_message, 'error');
        }
    } else {
        $error_message = "API request failed with status code: $http_code";
        send_json_response(false, $error_message, 'error');
    }
    exit;
}

function upload_chunks($file, $chunk_size, $unique_file_name) {
    $file_handle = fopen($file['tmp_name'], 'rb');
    $chunk_paths = [];
    $chunk_index = 0;

    while (!feof($file_handle)) {
        $chunk_data = fread($file_handle, $chunk_size);
        $chunk_path = 'uploads/' . $unique_file_name . '.part' . $chunk_index;
        if (file_put_contents($chunk_path, $chunk_data) !== false) {
            $chunk_paths[] = $chunk_path;
        }
        $chunk_index++;
    }

    fclose($file_handle);
    return $chunk_paths;
}

function reassemble_file($chunk_paths, $unique_file_name) {
    $temp_file_path = 'uploads/' . $unique_file_name;
    $temp_file_handle = fopen($temp_file_path, 'wb');

    foreach ($chunk_paths as $chunk_path) {
        fwrite($temp_file_handle, file_get_contents($chunk_path));
        unlink($chunk_path); 
    }

    fclose($temp_file_handle);
    return $temp_file_path;
}

function send_json_response($success, $message, $stage) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'stage' => $stage
    ]);
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>IPA Signing Tool</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            width: 90%;
            margin: 50px auto;
            background-color: white;
            padding: 30px;
            box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
            text-align: center;
        }
        h2 {
            color: #333;
            font-size: 28px;
            margin-bottom: 20px;
        }
        p {
            font-size: 16px;
            color: #666;
        }
        .toggle-bar {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }
        .toggle-option {
            flex: 1;
            padding: 10px;
            cursor: pointer;
            border: 1px solid #ddd;
            background-color: #f4f4f4;
            text-align: center;
            transition: background-color 0.3s ease;
        }
        .toggle-option.active {
            background-color: #4CAF50;
            color: white;
        }
        .file-input, .direct-link-input {
            font-size: 16px;
            margin: 20px 0;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            width: 100%;
        }
        .submit-btn {
            background-color: #4CAF50;
            color: white;
            padding: 12px 25px;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s ease-in-out;
            width: 100%;
            box-shadow: 0px 4px 6px rgba(0, 0, 0, 0.1);
        }
        .submit-btn:hover {
            background-color: #45a049;
        }
        .loading-circle {
            display: none;
            margin: 20px auto;
            border: 4px solid #f3f3f3;
            border-radius: 50%;
            border-top: 4px solid #4CAF50;
            width: 40px;
            height: 40px;
            animation: spin 2s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .status-message {
            margin-top: 20px;
            font-size: 18px;
            color: #333;
            font-weight: bold;
        }

        @media (max-width: 768px) {
            .container {
                width: 100%;
                padding: 20px;
            }
            h2 {
                font-size: 24px;
            }
            .submit-btn {
                font-size: 14px;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Sign Your IPA File</h2>
        <p>Choose whether to upload your IPA file or use a direct link:</p>

        <div class="toggle-bar">
            <div class="toggle-option active" id="upload-file-option">Upload File</div>
            <div class="toggle-option" id="direct-link-option">Use Direct Link (Faster)</div>
        </div>

        <form id="upload-form" enctype="multipart/form-data" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <input type="file" name="ipa_file" class="file-input" required>
            

            <input type="text" name="ipa_direct_link" class="direct-link-input" placeholder="Enter direct link to IPA file" style="display: none;">

            <input type="submit" value="Upload and Sign" class="submit-btn">
        </form>

        <div class="loading-circle"></div>
        <div class="status-message"></div>

        <div id="result-message"></div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const fileOption = document.getElementById('upload-file-option');
        const linkOption = document.getElementById('direct-link-option');
        const fileInput = document.querySelector('.file-input');
        const linkInput = document.querySelector('.direct-link-input');

        fileOption.addEventListener('click', function() {
            fileOption.classList.add('active');
            linkOption.classList.remove('active');
            fileInput.style.display = 'block';
            linkInput.style.display = 'none';
            fileInput.setAttribute('required', 'true'); 
            linkInput.removeAttribute('required');
        });

        linkOption.addEventListener('click', function() {
            linkOption.classList.add('active');
            fileOption.classList.remove('active');
            fileInput.style.display = 'none';
            linkInput.style.display = 'block';
            linkInput.setAttribute('required', 'true'); 
            fileInput.removeAttribute('required');
        });

        document.getElementById('upload-form').addEventListener('submit', function(e) {
            e.preventDefault(); 

            document.querySelector('.loading-circle').style.display = 'block';
            document.querySelector('.status-message').textContent = 'Uploading... Please wait.';
            document.getElementById('result-message').innerHTML = '';

            let formData = new FormData(this);
            let xhr = new XMLHttpRequest();
            xhr.open('POST', 'index.php', true);

            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        let response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            document.querySelector('.status-message').textContent = 'Signing process completed successfully!';
                            document.getElementById('result-message').innerHTML = response.message;
                        } else {
                            document.querySelector('.status-message').textContent = 'Error occurred during the process.';
                            document.getElementById('result-message').innerHTML = '<div class="error-message">' + response.message + '</div>';
                        }
                    } catch (e) {
                        document.querySelector('.status-message').textContent = 'Error parsing response from server.';
                        document.getElementById('result-message').innerHTML = '<div class="error-message">Error parsing response from server.</div>';
                    }
                } else {
                    document.querySelector('.status-message').textContent = 'Error occurred during upload.';
                    document.getElementById('result-message').innerHTML = '<div class="error-message">Error occurred during upload.</div>';
                }
                document.querySelector('.loading-circle').style.display = 'none';
            };

            xhr.upload.onprogress = function(event) {
                if (event.lengthComputable) {
                    let percentComplete = (event.loaded / event.total) * 100;
                    document.querySelector('.status-message').textContent = 'Uploading: ' + percentComplete.toFixed(2) + '%';
                }
            };

            xhr.send(formData);
        });
    });
    </script>
</body>
</html>
