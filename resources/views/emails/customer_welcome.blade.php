<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Customer Login Credentials</title>
</head>
<body>
    <p>Hi {{ $user->name ?? 'Customer' }},</p>
    <p>Your Customer account has been created for Garage Mandi.</p>
    <p>Use the credentials below for your first login:</p>
    <p><strong>Email:</strong> {{ $user->email }}</p>
    <p><strong>Temporary Password:</strong> {{ $temporaryPassword }}</p>
    <p>For security, please change your password after logging in.</p>
    <p>Thank you,<br>Garage Mandi Team</p>
</body>
</html>
