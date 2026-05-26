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
$required_fields = ['name', 'email', 'phone', 'street', 'city', 'state', 'zip', 'company', 'template', 'quantity'];

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
$street = sanitize_input($_POST['street']);
$city = sanitize_input($_POST['city']);
$state = sanitize_input($_POST['state']);
$zip = sanitize_input($_POST['zip']);
$company = sanitize_input($_POST['company']);
$template = sanitize_input($_POST['template']);
$quantity = sanitize_input($_POST['quantity']);
$notes = isset($_POST['notes']) ? sanitize_input($_POST['notes']) : '';

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
$max_file_size = 10 * 1024 * 1024; // 10MB per file
$total_size = 0;
$max_total = 75 * 1024 * 1024; // 75MB total
$allowed_types = ['image/png', 'image/jpeg', 'image/svg+xml'];
$allowed_extensions = ['png', 'jpg', 'jpeg', 'svg'];

if (isset($_FILES) && !empty($_FILES)) {
    foreach ($_FILES as $file_input) {
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
        
        $attachments[] = [
            'filename' => $file_input['name'],
            'type' => $file_mime,
            'content' => $file_base64
        ];
    }
}

// Build email to customer
$customer_subject = "Order Received - Order ID: $order_id";
$customer_html = build_customer_email_full_service($name, $order_id, $email, $phone, $street, $city, $state, $zip, $company, $template, $quantity, $notes);

// Build email to business
$business_subject = "New Full Service Order - $order_id - $company";
$business_html = build_business_email_full_service($name, $email, $phone, $street, $city, $state, $zip, $company, $template, $quantity, $notes, $order_id);

// Send emails via SendGrid
$customer_email_result = send_sendgrid_email(
    $sendgrid_api_key,
    'info@axletargets.com',
    $email,
    $customer_subject,
    $customer_html,
    []
);

if (!$customer_email_result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Email could not be sent']);
    exit;
}

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
    'message' => 'Order received',
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
function build_customer_email_full_service($name, $order_id, $email, $phone, $street, $city, $state, $zip, $company, $template, $quantity, $notes) {
    return "
    <html>
    <body style='font-family: Arial, sans-serif; color: #333;'>
        <h2>Order Confirmation</h2>
        <p>Hi $name,</p>
        <p>Thank you for your order! We've received your full service request.</p>
        
        <h3>Order Details</h3>
        <p><strong>Order ID:</strong> $order_id</p>
        <p><strong>Email:</strong> $email</p>
        <p><strong>Phone:</strong> $phone</p>
        <p><strong>Company:</strong> $company</p>
        <p><strong>Address:</strong> $street, $city, $state $zip</p>
        <p><strong>Template:</strong> $template</p>
        <p><strong>Quantity:</strong> $quantity</p>
        " . (!empty($notes) ? "<p><strong>Notes:</strong> $notes</p>" : "") . "
        
        <p>We will review your request and contact you shortly with next steps.</p>
        <p>Thank you for choosing Axle Targets!</p>
        
        <hr>
        <p style='font-size: 12px; color: #666;'>Axle Targets | Full Service Custom Design & Printing</p>
    </body>
    </html>
    ";
}

/**
 * Build business notification email HTML
 */
function build_business_email_full_service($name, $email, $phone, $street, $city, $state, $zip, $company, $template, $quantity, $notes, $order_id) {
    return "
    <html>
    <body style='font-family: Arial, sans-serif; color: #333;'>
        <h2>New Full Service Order - $order_id</h2>
        
        <h3>Customer Information</h3>
        <p><strong>Name:</strong> $name</p>
        <p><strong>Email:</strong> $email</p>
        <p><strong>Phone:</strong> $phone</p>
        <p><strong>Company:</strong> $company</p>
        <p><strong>Address:</strong> $street, $city, $state $zip</p>
        
        <h3>Order Details</h3>
        <p><strong>Order ID:</strong> $order_id</p>
        <p><strong>Template:</strong> $template</p>
        <p><strong>Quantity:</strong> $quantity</p>
        " . (!empty($notes) ? "<p><strong>Notes:</strong> $notes</p>" : "") . "
        
        <p>Files attached above.</p>
        
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
            'disposition' => 'attachment',
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
