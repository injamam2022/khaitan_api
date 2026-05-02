<?php
header("Access-Control-Allow-Origin: https://www.khaitan.com");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$name  = trim($data['name'] ?? '');
$phone = trim($data['phone'] ?? '');
$email = trim($data['email'] ?? '');
$address = trim($data['address'] ?? '');
$country = trim($data['country'] ?? '');
$state = trim($data['state'] ?? '');
$city = trim($data['city'] ?? '');
$pin = trim($data['pin'] ?? '');
$message = trim($data['message'] ?? '');

if ($name === '' || $phone === '' || $email === '' || $address === '' || $country === '' || $state === '' || $city === '' || $pin === '' || $message === '') {
    echo json_encode(["success" => false, "message" => "All fields required"]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["success" => false, "message" => "Invalid email"]);
    exit;
}

$to = "customercare@khaitan.com"; // apna email
$subject = "New Enquiry Form Submission";

// HTML Mail Body
$message = '
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Contact Form</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f4f6f8; padding:20px;">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <td align="center">
        <table width="600" style="background:#ffffff; border-radius:8px; overflow:hidden;">
          <tr>
            <td style="background:#111827; color:#ffffff; padding:16px 24px;">
              <h2 style="margin:0;">New Contact Details</h2>
            </td>
          </tr>

          <tr>
            <td style="padding:24px;">
              <p style="font-size:14px; color:#374151;">You have received a new enquiry from your website.</p>

              <table width="100%" style="border-collapse:collapse;">
                <tr>
                  <td style="padding:10px; border:1px solid #e5e7eb;"><strong>Name</strong></td>
                  <td style="padding:10px; border:1px solid #e5e7eb;">'.$name.'</td>
                </tr>
                <tr>
                  <td style="padding:10px; border:1px solid #e5e7eb;"><strong>Phone</strong></td>
                  <td style="padding:10px; border:1px solid #e5e7eb;">'.$phone.'</td>
                </tr>
                <tr>
                  <td style="padding:10px; border:1px solid #e5e7eb;"><strong>Email</strong></td>
                  <td style="padding:10px; border:1px solid #e5e7eb;">'.$email.'</td>
                </tr>
                <tr>
                  <td style="padding:10px; border:1px solid #e5e7eb;"><strong>Address</strong></td>
                  <td style="padding:10px; border:1px solid #e5e7eb;">'.$address.'</td>
                </tr>
                <tr>
                  <td style="padding:10px; border:1px solid #e5e7eb;"><strong>Country</strong></td>
                  <td style="padding:10px; border:1px solid #e5e7eb;">'.$country.'</td>
                </tr>
                <tr>
                  <td style="padding:10px; border:1px solid #e5e7eb;"><strong>State</strong></td>
                  <td style="padding:10px; border:1px solid #e5e7eb;">'.$state.'</td>
                </tr>
                <tr>
                  <td style="padding:10px; border:1px solid #e5e7eb;"><strong>City</strong></td>
                  <td style="padding:10px; border:1px solid #e5e7eb;">'.$city.'</td>
                </tr>
                <tr>
                  <td style="padding:10px; border:1px solid #e5e7eb;"><strong>Pin Code</strong></td>
                  <td style="padding:10px; border:1px solid #e5e7eb;">'.$pin.'</td>
                </tr>
                <tr>
                  <td style="padding:10px; border:1px solid #e5e7eb;"><strong>Message</strong></td>
                  <td style="padding:10px; border:1px solid #e5e7eb;">'.$message.'</td>
                </tr>
              </table>

              <p style="margin-top:20px; font-size:13px; color:#6b7280;">
                This email was sent from your website contact form.
              </p>
            </td>
          </tr>

          <tr>
            <td style="background:#f9fafb; text-align:center; padding:12px; font-size:12px; color:#9ca3af;">
              © '.date("Y").' Khaitan
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
';

// Headers (HTML mail)
$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-type: text/html; charset=UTF-8\r\n";
$headers .= "From: Website <no-reply@www.khaitan.com>\r\n";
$headers .= "Reply-To: $email\r\n";

if (mail($to, $subject, $message, $headers)) {
    echo json_encode(["success" => true, "message" => "Mail sent successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "Mail sending failed"]);
}
