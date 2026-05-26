<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Return 400 on invalid method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get SendGrid API key from environment
$sendgrid_api_key = getenv('SENDGRID_API_KEY');
if (!$sendgrid_api_key) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'SendGrid API key not configured']);
    exit;
}

// Required fields
$required_fields = ['name', 'email', 'phone', 'quantity'];

// Validate required fields
foreach ($required_fields as $field) {
    if (empty($_POST[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing $field"]);
        exit;
    }
}

// Sanitize and validate inputs
$name = sanitize_input($_POST['name']);
$email = sanitize_input($_POST['email']);
$phone = sanitize_input($_POST['phone']);
$quantity = sanitize_input($_POST['quantity']);

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email format']);
    exit;
}

// Generate order ID
$order_id = '0716-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

// Handle file uploads
$attachments = [];
$logo_attachments = [];
$preview_png = null;
$design_json = null;
$max_file_size = 10 * 1024 * 1024; // 10MB per file
$total_size = 0;
$max_total = 75 * 1024 * 1024; // 75MB total
$allowed_types = ['image/png', 'image/jpeg', 'image/svg+xml'];
$allowed_extensions = ['png', 'jpg', 'jpeg', 'svg'];

if (isset($_FILES) && !empty($_FILES)) {
    foreach ($_FILES as $field_name => $file_input) {
        if ($file_input['error'] === UPLOAD_ERR_NO_FILE) {
            continue; // Optional file not provided
        }
        
        if ($file_input['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'File upload error']);
            exit;
        }
        
        // Check file size
        if ($file_input['size'] > $max_file_size) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'File exceeds 10MB limit']);
            exit;
        }
        
        // Check total size
        $total_size += $file_input['size'];
        if ($total_size > $max_total) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Total upload size exceeds 75MB limit']);
            exit;
        }
        
        // Validate file type
        $file_mime = mime_content_type($file_input['tmp_name']);
        $file_ext = strtolower(pathinfo($file_input['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_mime, $allowed_types) || !in_array($file_ext, $allowed_extensions)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'File type not allowed. Accept: PNG, JPG, SVG']);
            exit;
        }
        
        // Read file and convert to base64
        $file_content = file_get_contents($file_input['tmp_name']);
        $file_base64 = base64_encode($file_content);
        
        // Categorize by field name
        if (strpos($field_name, 'logo') !== false) {
            $logo_attachments[] = [
                'filename' => $file_input['name'],
                'type' => $file_mime,
                'content' => $file_base64,
                'disposition' => 'attachment'
            ];
        } elseif ($field_name === 'previewPng') {
            $preview_png = [
                'filename' => $file_input['name'],
                'type' => $file_mime,
                'content' => $file_base64
            ];
        }
        
        // All images go to business email
        $attachments[] = [
            'filename' => $file_input['name'],
            'type' => $file_mime,
            'content' => $file_base64
        ];
    }
}

// Handle design JSON (POST parameter)
if (isset($_POST['designJson']) && !empty($_POST['designJson'])) {
    $design_json = $_POST['designJson'];
    
    // Add JSON as attachment to business email
    $json_base64 = base64_encode($design_json);
    $attachments[] = [
        'filename' => 'design.json',
        'type' => 'application/json',
        'content' => $json_base64
    ];
}

// Build email to customer (with preview mockup)
$customer_subject = "Design Submitted - Order ID: $order_id";
$customer_html = build_customer_email_diy($name, $order_id, $email, $phone, $quantity);
$customer_attachments = [];
if ($preview_png) {
    $customer_attachments[] = $preview_png;
}

// Build email to business (with all files)
$business_subject = "New DIY Design Order - $order_id - $name";
$business_html = build_business_email_diy($name, $email, $phone, $quantity, $order_id);

// Send email to customer (with preview PNG if available)
$customer_email_result = send_sendgrid_email(
    $sendgrid_api_key,
    'info@axletargets.com',
    $email,
    $customer_subject,
    $customer_html,
    $customer_attachments
);

if (!$customer_email_result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Email could not be sent']);
    exit;
}

// Send email to business (with all files: logos, mockup PNG, JSON)
$business_email_result = send_sendgrid_email(
    $sendgrid_api_key,
    'info@axletargets.com',
    'info@axletargets.com',
    $business_subject,
    $business_html,
    $attachments
);

if (!$business_email_result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Email could not be sent']);
    exit;
}

// Success response
echo json_encode([
    'success' => true,
    'message' => 'Design submitted',
    'orderId' => $order_id
]);
exit;

/**
 * Sanitize user input to prevent XSS
 */
function sanitize_input($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Build customer confirmation email HTML
 */
function build_customer_email_diy($name, $order_id, $email, $phone, $quantity) {
    return "
    <html>
    <body style='font-family: Arial, sans-serif; color: #333;'>
        <h2>Design Submitted Successfully</h2>
        <p>Hi $name,</p>
        <p>Thank you for submitting your custom design! We've received your DIY order.</p>
        
        <h3>Order Details</h3>
        <p><strong>Order ID:</strong> $order_id</p>
        <p><strong>Email:</strong> $email</p>
        <p><strong>Phone:</strong> $phone</p>
        <p><strong>Quantity:</strong> $quantity</p>
        
        <p>Your mockup preview is attached. We will review your design files and contact you shortly with confirmation and any next steps.</p>
        <p>Thank you for choosing Axle Targets!</p>
        
        <hr>
        <p style='font-size: 12px; color: #666;'>Axle Targets | Custom Design & Printing</p>
    </body>
    </html>
    ";
}

/**
 * Build business notification email HTML
 */
function build_business_email_diy($name, $email, $phone, $quantity, $order_id) {
    return "
    <html>
    <body style='font-family: Arial, sans-serif; color: #333;'>
        <h2>New DIY Design Order - $order_id</h2>
        
        <h3>Customer Information</h3>
        <p><strong>Name:</strong> $name</p>
        <p><strong>Email:</strong> $email</p>
        <p><strong>Phone:</strong> $phone</p>
        
        <h3>Order Details</h3>
        <p><strong>Order ID:</strong> $order_id</p>
        <p><strong>Quantity:</strong> $quantity</p>
        
        <h3>Attached Files</h3>
        <ul>
            <li>Logo files (PNG, JPG, SVG)</li>
            <li>Preview mockup (PNG)</li>
            <li>Design JSON configuration</li>
        </ul>
        
        <p>Review design files and contact customer for approval/revisions.</p>
        
        <hr>
        <p style='font-size: 12px; color: #666;'>Axle Targets Order Management</p>
    </body>
    </html>
    ";
}

/**
 * Send email via SendGrid REST API
 */
function send_sendgrid_email($api_key, $from_email, $to_email, $subject, $html_content, $attachments = []) {
    $url = 'https://api.sendgrid.com/v3/mail/send';
    
    // Build attachments array
    $attachment_data = [];
    foreach ($attachments as $attachment) {
        $attachment_data[] = [
            'filename' => $attachment['filename'],
            'type' => $attachment['type'],
            'disposition' => isset($attachment['disposition']) ? $attachment['disposition'] : 'attachment',
            'content' => $attachment['content']
        ];
    }
    
    $payload = [
        'personalizations' => [
            [
                'to' => [
                    ['email' => $to_email]
                ],
                'subject' => $subject
            ]
        ],
        'from' => ['email' => $from_email],
        'content' => [
            [
                'type' => 'text/html',
                'value' => $html_content
            ]
        ]
    ];
    
    if (!empty($attachment_data)) {
        $payload['attachments'] = $attachment_data;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // SendGrid returns 202 for accepted email
    return ($http_code === 202);
}
?>
