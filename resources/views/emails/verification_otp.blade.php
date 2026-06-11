<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Verify your Garage Mandi account</title>
</head>
<body>
    <p>Hi {{ $user->name ?? 'User' }},</p>
    <p>Thank you for registering with Garage Mandi. Use the OTP below to verify your email address:</p>
    <h2>{{ $otp }}</h2>
    <p>This OTP will expire in 10 minutes.</p>
    <p>If you did not register for an account, please ignore this email.</p>
    <p>Thank you,<br>Garage Mandi Team</p>
</body>
</html>
