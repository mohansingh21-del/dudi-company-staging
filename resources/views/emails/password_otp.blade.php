<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Password Reset OTP</title>
</head>
<body>
    <p>Hi {{ $user->name ?? 'User' }},</p>
    <p>You requested a password reset for your Garage Mandi account. Use the OTP below to reset your password:</p>
    <h2>{{ $otp }}</h2>
    <p>This OTP will expire in 10 minutes.</p>
    <p>If you did not request this, please ignore this email.</p>
    <p>Thank you,<br>Garage Mandi Team</p>
</body>
</html>
