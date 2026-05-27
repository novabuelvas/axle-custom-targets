<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    respond(204, ['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Use POST for proof requests.']);
}

load_env_file();

try {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('The request body was not valid JSON.');
    }

    $serviceType = (string) ($payload['serviceType'] ?? '');
    if (!in_array($serviceType, ['full-service', 'diy'], true)) {
        throw new RuntimeException('Missing or invalid service type.');
    }

    $recipient = env_value('AXLE_PROOF_TO', env_value('AXLE_INFO_EMAIL', env_value('AXLE_TO_EMAIL', 'info@axletargets.com')));
    $fromEmail = env_value('AXLE_FROM_EMAIL', env_value('SENDGRID_FROM_EMAIL', 'info@axletargets.com'));
    if (strtolower($fromEmail) !== 'info@axletargets.com' && env_value('AXLE_ALLOW_CUSTOM_FROM', '0') !== '1') {
        $fromEmail = 'info@axletargets.com';
    }
    $fromName = env_value('AXLE_FROM_NAME', 'AXLE Targets');

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('AXLE recipient email is not configured.');
    }
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('AXLE sender email is not configured.');
    }

    $customerEmail = customer_email($payload);
    $internalReplyTo = $customerEmail !== '' ? $customerEmail : $recipient;
    $customerLabel = request_label($payload);
    $internalSubject = $serviceType === 'full-service'
        ? 'New AXLE Full Service Proof Request - ' . $customerLabel
        : 'New AXLE DIY Target Designer Request - ' . $customerLabel;
    $customerSubject = $serviceType === 'full-service'
        ? 'AXLE received your full service target proof request'
        : 'AXLE received your DIY target proof request';

    $attachments = collect_attachments($payload);
    $customerAttachments = customer_attachments($payload, $attachments);

    send_email(
        $recipient,
        $internalSubject,
        build_internal_html_email($payload, $attachments),
        build_internal_text_email($payload, $attachments),
        $attachments,
        $fromEmail,
        $fromName,
        $internalReplyTo
    );

    if (filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        send_email(
            $customerEmail,
            $customerSubject,
            build_customer_html_email($payload, $customerAttachments),
            build_customer_text_email($payload, $customerAttachments),
            $customerAttachments,
            $fromEmail,
            $fromName,
            $recipient
        );
    }

    respond(200, [
        'ok' => true,
        'success' => true,
        'message' => 'Proof request sent.',
        'internalAttachments' => count($attachments),
        'customerAttachments' => count($customerAttachments),
    ]);
} catch (Throwable $error) {
    error_log('AXLE proof request failed: ' . $error->getMessage());
    respond(500, [
        'ok' => false,
        'success' => false,
        'error' => $error->getMessage(),
    ]);
}

function respond(int $status, array $body): void
{
    http_response_code($status);
    if ($status !== 204) {
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function load_env_file(): void
{
    $candidates = [
        __DIR__ . '/../.env',
        __DIR__ . '/../../.env',
    ];

    if (!empty($_SERVER['HOME'])) {
        $candidates[] = rtrim((string) $_SERVER['HOME'], '/') . '/.openclaw/.env';
    }
    $candidates[] = '/root/.openclaw/.env';

    foreach ($candidates as $path) {
        if (!is_readable($path)) {
            continue;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            continue;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}

function env_value(string $key, string $fallback = ''): string
{
    $value = getenv($key);
    return $value === false || $value === '' ? $fallback : (string) $value;
}

function customer_email(array $payload): string
{
    $source = $payload['serviceType'] === 'full-service'
        ? ($payload['fullService'] ?? [])
        : ($payload['shipping'] ?? []);

    $email = is_array($source) ? (string) ($source['email'] ?? '') : '';
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function request_label(array $payload): string
{
    $source = $payload['serviceType'] === 'full-service'
        ? ($payload['fullService'] ?? [])
        : ($payload['shipping'] ?? []);

    if (!is_array($source)) {
        return 'Website Lead';
    }

    foreach (['business', 'fullName', 'email'] as $key) {
        $value = trim((string) ($source[$key] ?? ''));
        if ($value !== '') {
            return clean_inline($value);
        }
    }

    return 'Website Lead';
}

function collect_attachments(array $payload): array
{
    $attachments = [];
    $serviceType = (string) ($payload['serviceType'] ?? '');

    if ($serviceType === 'full-service') {
        foreach (($payload['uploads'] ?? []) as $index => $upload) {
            if (is_array($upload)) {
                $attachment = attachment_from_data_url($upload, 'full-service-upload-' . ($index + 1));
                if ($attachment) {
                    $attachments[] = $attachment;
                }
            }
        }
        return $attachments;
    }

    if (!empty($payload['targetPreviewPng'])) {
        $preview = [
            'name' => 'axle-target-proof.png',
            'type' => 'image/png',
            'dataUrl' => (string) $payload['targetPreviewPng'],
        ];
        $attachment = attachment_from_data_url($preview, 'axle-target-proof.png');
        if ($attachment) {
            $attachments[] = $attachment;
        }
    }

    foreach (($payload['uploadedLogos'] ?? []) as $index => $upload) {
        if (is_array($upload)) {
            $attachment = attachment_from_data_url($upload, 'diy-logo-' . ($index + 1));
            if ($attachment) {
                $attachments[] = $attachment;
            }
        }
    }

    if (!empty($payload['designJSON'])) {
        $json = json_encode($payload['designJSON'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $attachments[] = [
                'filename' => 'axle-fabric-design.json',
                'type' => 'application/json',
                'content' => base64_encode($json),
                'bytes' => strlen($json),
            ];
        }
    }

    return $attachments;
}

function customer_attachments(array $payload, array $attachments): array
{
    if (($payload['serviceType'] ?? '') !== 'diy') {
        return [];
    }

    return array_values(array_filter($attachments, static function (array $attachment): bool {
        return ($attachment['filename'] ?? '') === 'axle-target-proof.png';
    }));
}

function attachment_from_data_url(array $file, string $fallbackName): ?array
{
    $dataUrl = (string) ($file['dataUrl'] ?? '');
    if ($dataUrl === '') {
        return null;
    }

    if (!preg_match('/^data:([^;,]+)?(;base64)?,(.*)$/s', $dataUrl, $matches)) {
        throw new RuntimeException('One uploaded file was not a valid data URL.');
    }

    $mime = $matches[1] !== '' ? $matches[1] : (string) ($file['type'] ?? 'application/octet-stream');
    $encoded = $matches[3] ?? '';
    $binary = ($matches[2] ?? '') === ';base64'
        ? base64_decode($encoded, true)
        : rawurldecode($encoded);

    if ($binary === false || $binary === '') {
        throw new RuntimeException('One uploaded file could not be decoded.');
    }

    if (strlen($binary) > 16 * 1024 * 1024) {
        throw new RuntimeException('One attachment is too large to email.');
    }

    $filename = sanitize_filename((string) ($file['name'] ?? $fallbackName), $mime, $fallbackName);

    return [
        'filename' => $filename,
        'type' => $mime ?: 'application/octet-stream',
        'content' => base64_encode($binary),
        'bytes' => strlen($binary),
    ];
}

function sanitize_filename(string $name, string $mime, string $fallback): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    $name = trim((string) $name, '.-');

    if ($name === '') {
        $name = $fallback;
    }

    if (strpos($name, '.') === false) {
        $extensions = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/svg+xml' => 'svg',
            'application/json' => 'json',
        ];
        $name .= '.' . ($extensions[$mime] ?? 'bin');
    }

    return substr($name, 0, 140);
}

function build_internal_html_email(array $payload, array $attachments): string
{
    $serviceType = (string) ($payload['serviceType'] ?? '');
    $serviceLabel = $serviceType === 'full-service' ? 'Full Service Target Design' : 'DIY Online Target Designer';
    $source = $serviceType === 'full-service' ? ($payload['fullService'] ?? []) : ($payload['shipping'] ?? []);
    $billing = $serviceType === 'diy' ? ($payload['billing'] ?? []) : [];

    $customerRows = rows([
        'Full Name' => value_from($source, 'fullName'),
        'Business / Range' => value_from($source, 'business'),
        'Email' => value_from($source, 'email'),
        'Phone' => value_from($source, 'phone'),
    ]);

    $shippingRows = rows([
        'Street' => value_from($source, 'street'),
        'City' => value_from($source, 'city'),
        'State' => value_from($source, 'state'),
        'ZIP' => value_from($source, 'zip'),
        'Country' => value_from($source, 'country', 'USA'),
    ]);

    $orderRows = $serviceType === 'full-service'
        ? rows([
            'Service Type' => $serviceLabel,
            'Target Template' => value_from($source, 'template'),
            'Quantity' => value_from($source, 'quantity'),
            'Notes' => value_from($source, 'notes'),
            'Submitted At' => (string) ($payload['submittedAt'] ?? ''),
            'Source Page' => (string) ($payload['sourcePath'] ?? ''),
        ])
        : rows([
            'Service Type' => $serviceLabel,
            'Target Template' => template_label((string) ($payload['template'] ?? '')),
            'Quantity' => (string) ($payload['quantity'] ?? ''),
            'Instructions' => (string) ($payload['instructions'] ?? ''),
            'Submitted At' => (string) ($payload['submittedAt'] ?? ''),
            'Source Page' => (string) ($payload['sourcePath'] ?? ''),
        ]);

    $billingRows = $serviceType === 'diy'
        ? rows([
            'Billing' => !empty($source['sameBilling']) ? 'Same as shipping' : '',
            'Street' => value_from($billing, 'billingStreet'),
            'City' => value_from($billing, 'billingCity'),
            'State' => value_from($billing, 'billingState'),
            'ZIP' => value_from($billing, 'billingZip'),
            'Country' => value_from($billing, 'billingCountry'),
        ])
        : '';

    $attachmentRows = '';
    foreach ($attachments as $attachment) {
        $attachmentRows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eee;">'
            . e($attachment['filename'])
            . '</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;color:#666;">'
            . e(format_bytes((int) $attachment['bytes']))
            . '</td></tr>';
    }
    if ($attachmentRows === '') {
        $attachmentRows = '<tr><td style="padding:8px 0;color:#666;">No attachments were included.</td><td></td></tr>';
    }

    return '<!doctype html><html><body style="margin:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#111;">'
        . '<div style="max-width:760px;margin:0 auto;background:#fff;padding:32px;">'
        . '<div style="border-bottom:4px solid #ff5a1f;padding-bottom:18px;margin-bottom:24px;">'
        . '<div style="font-size:13px;font-weight:700;letter-spacing:.14em;color:#ed1c24;text-transform:uppercase;">AXLE Targets</div>'
        . '<h1 style="margin:8px 0 0;font-size:28px;line-height:1.15;">' . e($serviceLabel) . '</h1>'
        . '</div>'
        . section_html('Customer', $customerRows)
        . section_html('Shipping', $shippingRows)
        . ($billingRows !== '' ? section_html('Billing', $billingRows) : '')
        . section_html('Request Details', $orderRows)
        . '<h2 style="font-size:18px;margin:26px 0 8px;">Attachments</h2>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">' . $attachmentRows . '</table>'
        . '<p style="margin-top:28px;color:#666;font-size:12px;">Sent from the AXLE target proof request form.</p>'
        . '</div></body></html>';
}

function build_internal_text_email(array $payload, array $attachments): string
{
    $serviceType = (string) ($payload['serviceType'] ?? '');
    $serviceLabel = $serviceType === 'full-service' ? 'Full Service Target Design' : 'DIY Online Target Designer';
    $source = $serviceType === 'full-service' ? ($payload['fullService'] ?? []) : ($payload['shipping'] ?? []);
    $billing = $serviceType === 'diy' ? ($payload['billing'] ?? []) : [];

    $lines = [
        'AXLE Targets - ' . $serviceLabel,
        '',
        'Customer',
        'Full Name: ' . value_from($source, 'fullName'),
        'Business / Range: ' . value_from($source, 'business'),
        'Email: ' . value_from($source, 'email'),
        'Phone: ' . value_from($source, 'phone'),
        '',
        'Shipping',
        'Street: ' . value_from($source, 'street'),
        'City: ' . value_from($source, 'city'),
        'State: ' . value_from($source, 'state'),
        'ZIP: ' . value_from($source, 'zip'),
        'Country: ' . value_from($source, 'country', 'USA'),
        '',
        'Request Details',
        'Service Type: ' . $serviceLabel,
        'Target Template: ' . ($serviceType === 'full-service' ? value_from($source, 'template') : template_label((string) ($payload['template'] ?? ''))),
        'Quantity: ' . ($serviceType === 'full-service' ? value_from($source, 'quantity') : (string) ($payload['quantity'] ?? '')),
        'Notes / Instructions: ' . ($serviceType === 'full-service' ? value_from($source, 'notes') : (string) ($payload['instructions'] ?? '')),
        'Submitted At: ' . (string) ($payload['submittedAt'] ?? ''),
        'Source Page: ' . (string) ($payload['sourcePath'] ?? ''),
    ];

    if ($serviceType === 'diy') {
        $lines = array_merge($lines, [
            '',
            'Billing',
            'Billing: ' . (!empty($source['sameBilling']) ? 'Same as shipping' : ''),
            'Street: ' . value_from($billing, 'billingStreet'),
            'City: ' . value_from($billing, 'billingCity'),
            'State: ' . value_from($billing, 'billingState'),
            'ZIP: ' . value_from($billing, 'billingZip'),
            'Country: ' . value_from($billing, 'billingCountry'),
        ]);
    }

    $lines[] = '';
    $lines[] = 'Attachments';
    if (!$attachments) {
        $lines[] = 'No attachments were included.';
    }
    foreach ($attachments as $attachment) {
        $lines[] = '- ' . $attachment['filename'] . ' (' . format_bytes((int) $attachment['bytes']) . ')';
    }

    return implode("\n", $lines);
}

function build_customer_html_email(array $payload, array $attachments): string
{
    $serviceType = (string) ($payload['serviceType'] ?? '');
    $serviceLabel = $serviceType === 'full-service' ? 'Full Service Target Design' : 'DIY Online Target Designer';
    $source = $serviceType === 'full-service' ? ($payload['fullService'] ?? []) : ($payload['shipping'] ?? []);
    $billing = $serviceType === 'diy' ? ($payload['billing'] ?? []) : [];

    $customerRows = rows([
        'Full Name' => value_from($source, 'fullName'),
        'Business / Range' => value_from($source, 'business'),
        'Email' => value_from($source, 'email'),
        'Phone' => value_from($source, 'phone'),
    ]);

    $shippingRows = rows([
        'Street' => value_from($source, 'street'),
        'City' => value_from($source, 'city'),
        'State' => value_from($source, 'state'),
        'ZIP' => value_from($source, 'zip'),
        'Country' => value_from($source, 'country', 'USA'),
    ]);

    $orderRows = $serviceType === 'full-service'
        ? rows([
            'Service Type' => $serviceLabel,
            'Target Template' => value_from($source, 'template'),
            'Quantity' => value_from($source, 'quantity'),
            'Notes' => value_from($source, 'notes'),
        ])
        : rows([
            'Service Type' => $serviceLabel,
            'Target Template' => template_label((string) ($payload['template'] ?? '')),
            'Quantity' => (string) ($payload['quantity'] ?? ''),
            'Instructions' => (string) ($payload['instructions'] ?? ''),
        ]);

    $billingRows = $serviceType === 'diy'
        ? rows([
            'Billing' => !empty($source['sameBilling']) ? 'Same as shipping' : '',
            'Street' => value_from($billing, 'billingStreet'),
            'City' => value_from($billing, 'billingCity'),
            'State' => value_from($billing, 'billingState'),
            'ZIP' => value_from($billing, 'billingZip'),
            'Country' => value_from($billing, 'billingCountry'),
        ])
        : '';

    $attachmentRows = '';
    foreach ($attachments as $attachment) {
        $attachmentRows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eee;">'
            . e($attachment['filename'])
            . '</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;color:#666;">'
            . e(format_bytes((int) $attachment['bytes']))
            . '</td></tr>';
    }

    $intro = $serviceType === 'full-service'
        ? 'We received your full-service target proof request. Our team will review your details and uploaded files, then follow up with your proof.'
        : 'We received your DIY target proof request. Your online mockup is attached for your records and our production team.';

    return '<!doctype html><html><body style="margin:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#111;">'
        . '<div style="max-width:760px;margin:0 auto;background:#fff;padding:32px;">'
        . '<div style="border-bottom:4px solid #ff5a1f;padding-bottom:18px;margin-bottom:24px;">'
        . '<div style="font-size:13px;font-weight:700;letter-spacing:.14em;color:#ed1c24;text-transform:uppercase;">AXLE Targets</div>'
        . '<h1 style="margin:8px 0 0;font-size:28px;line-height:1.15;">We received your proof request</h1>'
        . '</div>'
        . '<p style="font-size:16px;line-height:1.55;color:#333;">' . e($intro) . '</p>'
        . '<p style="font-size:16px;line-height:1.55;color:#333;">No credit card has been charged. If anything looks wrong, reply to this email and we will help.</p>'
        . section_html('Your Order', $orderRows)
        . section_html('Customer', $customerRows)
        . section_html('Shipping', $shippingRows)
        . ($billingRows !== '' ? section_html('Billing', $billingRows) : '')
        . ($attachmentRows !== '' ? '<h2 style="font-size:18px;margin:26px 0 8px;">Attached Mockup</h2><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">' . $attachmentRows . '</table>' : '')
        . '<p style="margin-top:28px;color:#666;font-size:12px;">AXLE Targets | info@axletargets.com</p>'
        . '</div></body></html>';
}

function build_customer_text_email(array $payload, array $attachments): string
{
    $serviceType = (string) ($payload['serviceType'] ?? '');
    $serviceLabel = $serviceType === 'full-service' ? 'Full Service Target Design' : 'DIY Online Target Designer';
    $source = $serviceType === 'full-service' ? ($payload['fullService'] ?? []) : ($payload['shipping'] ?? []);
    $billing = $serviceType === 'diy' ? ($payload['billing'] ?? []) : [];

    $lines = [
        'AXLE Targets - We received your proof request',
        '',
        $serviceType === 'full-service'
            ? 'We received your full-service target proof request. Our team will review your details and uploaded files, then follow up with your proof.'
            : 'We received your DIY target proof request. Your online mockup is attached for your records and our production team.',
        '',
        'No credit card has been charged. If anything looks wrong, reply to this email and we will help.',
        '',
        'Your Order',
        'Service Type: ' . $serviceLabel,
        'Target Template: ' . ($serviceType === 'full-service' ? value_from($source, 'template') : template_label((string) ($payload['template'] ?? ''))),
        'Quantity: ' . ($serviceType === 'full-service' ? value_from($source, 'quantity') : (string) ($payload['quantity'] ?? '')),
        'Notes / Instructions: ' . ($serviceType === 'full-service' ? value_from($source, 'notes') : (string) ($payload['instructions'] ?? '')),
        '',
        'Customer',
        'Full Name: ' . value_from($source, 'fullName'),
        'Business / Range: ' . value_from($source, 'business'),
        'Email: ' . value_from($source, 'email'),
        'Phone: ' . value_from($source, 'phone'),
        '',
        'Shipping',
        'Street: ' . value_from($source, 'street'),
        'City: ' . value_from($source, 'city'),
        'State: ' . value_from($source, 'state'),
        'ZIP: ' . value_from($source, 'zip'),
        'Country: ' . value_from($source, 'country', 'USA'),
    ];

    if ($serviceType === 'diy') {
        $lines = array_merge($lines, [
            '',
            'Billing',
            'Billing: ' . (!empty($source['sameBilling']) ? 'Same as shipping' : ''),
            'Street: ' . value_from($billing, 'billingStreet'),
            'City: ' . value_from($billing, 'billingCity'),
            'State: ' . value_from($billing, 'billingState'),
            'ZIP: ' . value_from($billing, 'billingZip'),
            'Country: ' . value_from($billing, 'billingCountry'),
        ]);
    }

    if ($attachments) {
        $lines[] = '';
        $lines[] = 'Attached Mockup';
        foreach ($attachments as $attachment) {
            $lines[] = '- ' . $attachment['filename'] . ' (' . format_bytes((int) $attachment['bytes']) . ')';
        }
    }

    $lines[] = '';
    $lines[] = 'AXLE Targets | info@axletargets.com';

    return implode("\n", $lines);
}

function rows(array $items): string
{
    $html = '';
    foreach ($items as $label => $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $html .= '<tr><th align="left" style="width:180px;padding:8px 12px 8px 0;border-bottom:1px solid #eee;color:#555;font-size:13px;">'
            . e((string) $label)
            . '</th><td style="padding:8px 0;border-bottom:1px solid #eee;">'
            . nl2br(e($value))
            . '</td></tr>';
    }
    return $html ?: '<tr><td style="padding:8px 0;color:#666;">No data supplied.</td></tr>';
}

function section_html(string $title, string $rows): string
{
    return '<h2 style="font-size:18px;margin:26px 0 8px;">' . e($title) . '</h2>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">'
        . $rows
        . '</table>';
}

function value_from($source, string $key, string $fallback = ''): string
{
    if (!is_array($source)) {
        return $fallback;
    }
    $value = trim((string) ($source[$key] ?? ''));
    return $value === '' ? $fallback : $value;
}

function template_label(string $key): string
{
    $labels = [
        'b27' => 'B-27 Silhouette',
        'b21' => 'B-21 Silhouette',
        'ipsc' => 'IPSC / USPSA',
        'blank' => 'Blank Canvas',
    ];
    return $labels[$key] ?? $key;
}

function send_email(
    string $to,
    string $subject,
    string $html,
    string $text,
    array $attachments,
    string $fromEmail,
    string $fromName,
    string $replyTo
): void {
    $sendgridKey = env_value('SENDGRID_API_KEY');
    if ($sendgridKey !== '') {
        send_via_sendgrid($sendgridKey, $to, $subject, $html, $text, $attachments, $fromEmail, $fromName, $replyTo);
        return;
    }

    if (env_value('AXLE_ALLOW_PHP_MAIL_FALLBACK', '0') !== '1') {
        throw new RuntimeException('SENDGRID_API_KEY is not configured.');
    }

    send_via_mail($to, $subject, $html, $text, $attachments, $fromEmail, $fromName, $replyTo);
}

function send_via_sendgrid(
    string $apiKey,
    string $to,
    string $subject,
    string $html,
    string $text,
    array $attachments,
    string $fromEmail,
    string $fromName,
    string $replyTo
): void {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('SendGrid is configured, but PHP cURL is unavailable.');
    }

    $mail = [
        'personalizations' => [[
            'to' => [['email' => $to]],
            'subject' => $subject,
        ]],
        'from' => [
            'email' => $fromEmail,
            'name' => $fromName,
        ],
        'reply_to' => [
            'email' => $replyTo,
        ],
        'content' => [
            ['type' => 'text/plain', 'value' => $text],
            ['type' => 'text/html', 'value' => $html],
        ],
    ];

    if ($attachments) {
        $mail['attachments'] = array_map(static function (array $attachment): array {
            return [
                'content' => $attachment['content'],
                'type' => $attachment['type'],
                'filename' => $attachment['filename'],
                'disposition' => 'attachment',
            ];
        }, $attachments);
    }

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($mail, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('SendGrid email failed with HTTP ' . $code . ($error ? ': ' . $error : '') . ($response ? ' - ' . $response : ''));
    }
}

function send_via_mail(
    string $to,
    string $subject,
    string $html,
    string $text,
    array $attachments,
    string $fromEmail,
    string $fromName,
    string $replyTo
): void {
    $eol = "\r\n";
    $mixedBoundary = 'axle_mixed_' . bin2hex(random_bytes(8));
    $altBoundary = 'axle_alt_' . bin2hex(random_bytes(8));

    $headers = [
        'MIME-Version: 1.0',
        'From: ' . header_mailbox($fromName, $fromEmail),
        'Reply-To: ' . clean_header($replyTo),
        'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"',
    ];

    $body = '--' . $mixedBoundary . $eol;
    $body .= 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"' . $eol . $eol;
    $body .= '--' . $altBoundary . $eol;
    $body .= 'Content-Type: text/plain; charset=UTF-8' . $eol;
    $body .= 'Content-Transfer-Encoding: 8bit' . $eol . $eol;
    $body .= $text . $eol . $eol;
    $body .= '--' . $altBoundary . $eol;
    $body .= 'Content-Type: text/html; charset=UTF-8' . $eol;
    $body .= 'Content-Transfer-Encoding: 8bit' . $eol . $eol;
    $body .= $html . $eol . $eol;
    $body .= '--' . $altBoundary . '--' . $eol;

    foreach ($attachments as $attachment) {
        $body .= '--' . $mixedBoundary . $eol;
        $body .= 'Content-Type: ' . clean_header($attachment['type']) . '; name="' . clean_header($attachment['filename']) . '"' . $eol;
        $body .= 'Content-Transfer-Encoding: base64' . $eol;
        $body .= 'Content-Disposition: attachment; filename="' . clean_header($attachment['filename']) . '"' . $eol . $eol;
        $body .= chunk_split($attachment['content']) . $eol;
    }

    $body .= '--' . $mixedBoundary . '--' . $eol;

    $sent = mail($to, clean_header($subject), $body, implode($eol, $headers));
    if (!$sent) {
        throw new RuntimeException('PHP mail() failed to send the proof request.');
    }
}

function header_mailbox(string $name, string $email): string
{
    return '"' . addcslashes(clean_header($name), '"\\') . '" <' . clean_header($email) . '>';
}

function clean_header(string $value): string
{
    return str_replace(["\r", "\n"], '', $value);
}

function clean_inline(string $value): string
{
    return preg_replace('/\s+/', ' ', clean_header($value));
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
