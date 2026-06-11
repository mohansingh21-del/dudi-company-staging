<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>System Admin Login Credentials</title>
</head>
<body>
    <p>Hi {{ $user->name ?? 'System Admin' }},</p>
    <p>Your System Admin account has been created for Garage Mandi.</p>
    <p>Use the credentials below for your first login:</p>
    <p><strong>Email:</strong> {{ $user->email }}</p>
    <p><strong>Temporary Password:</strong> {{ $temporaryPassword }}</p>
    <p>For security, please change your password after logging in.</p>
    <p>Thank you,<br>Garage Mandi Team</p>
</body>
</html>
