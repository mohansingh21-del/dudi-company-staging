<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Mail\PasswordOtpMail;
use App\Models\PasswordResetOtp;
use Illuminate\Support\Facades\Validator;

use App\Models\User;
use App\Models\Otp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\OtpMail;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['status' => 422, 'message' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ]);
    }

    public function sendOtp(Request $request)
    {
        $request->validate(['email' => 'required|email|max:150']);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json([
                'status' => 404,
                'message' => 'No account found with this email'
            ], 404);
        }

        if ((int) $user->is_active !== 1) {
            return response()->json([
                'status' => 403,
                'message' => 'Your account is deactivated. Please contact support.'
            ], 403);
        }
        $otp = random_int(100000, 999999); // 6-digit, cryptographically secure

        Otp::updateOrCreate(
            ['email' => $request->email],
            [
                'otp' => Hash::make($otp), // never store plain OTP
                'expires_at' => now()->addMinutes(10),
                'is_verified' => false,
                'attempts' => 0                 // reset attempts on resend
            ]
        );

        // Mail::to($request->email)->queue(new OtpMail($otp));


        return response()->json(['message' => 'OTP sent successfully to your email. {' . $otp . '}']);
    }
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Logged out successfully.'
        ], 200);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:150',
            'otp' => 'required|digits:6'
        ]);

        $otpRecord = Otp::where('email', $request->email)
            ->where('is_verified', false)
            ->where('expires_at', '>', now())
            ->first();

        // Check record exists, not max attempts, and OTP matches
        if (!$otpRecord) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        if ($otpRecord->attempts >= 5) {
            $otpRecord->delete();
            return response()->json(['message' => 'Too many attempts. Please request a new OTP.'], 429);
        }

        if (!Hash::check($request->otp, $otpRecord->otp)) {
            $otpRecord->increment('attempts');
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        // OTP is valid — mark verified and clean up
        $otpRecord->update(['is_verified' => true]);

        $user = User::firstOrCreate(
            ['email' => $request->email],
            [
                'password' => Hash::make(Str::random(16)),
                'is_active' => true
            ]
        );

        if (!$user->is_active) {
            return response()->json(['message' => 'Account is deactivated. Contact support.'], 403);
        }

        // Revoke old tokens, issue fresh one
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->only('id', 'email', 'is_active', 'created_at')
        ]);
    }
public function requestPasswordOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error.',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();
        $otp = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        PasswordResetOtp::updateOrCreate(
            ['email' => $user->email],
            [
                'token' => Hash::make($otp),
                'expires_at' => now()->addMinutes(10),
                'is_verified' => false,
            ]
        );

        try {
            Mail::to($user->email)->send(new PasswordOtpMail($user, $otp));
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Unable to send OTP email. Please check your mail configuration.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 200,
            'message' => 'OTP has been sent to your email. It expires in 10 minutes. ' . $otp, // Include OTP in response for testing purposes (remove in production)
        ], 200);
    }

    /**
     * Verify forgot password OTP.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyPasswordOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|string|min:4|max:4',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error.',
                'errors' => $validator->errors()
            ], 422);
        }

        $otpRecord = PasswordResetOtp::where('email', $request->email)->first();

        if (!$otpRecord || now()->greaterThan($otpRecord->expires_at)) {
            return response()->json([
                'status' => 400,
                'message' => 'OTP is invalid or has expired.'
            ], 400);
        }

        if (!Hash::check($request->otp, $otpRecord->token)) {
            return response()->json([
                'status' => 400,
                'message' => 'OTP is invalid or has expired.'
            ], 400);
        }

        // Mark as verified and extend expiry to allow password change
        $otpRecord->update([
            'is_verified' => true,
            'expires_at' => now()->addMinutes(15),
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'OTP verified successfully. You can now proceed to set a new password.'
        ], 200);
    }

    /**
     * Set a new password after verification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error.',
                'errors' => $validator->errors()
            ], 422);
        }

        $otpRecord = PasswordResetOtp::where('email', $request->email)
            ->where('is_verified', true)
            ->first();

        if (!$otpRecord || now()->greaterThan($otpRecord->expires_at)) {
            return response()->json([
                'status' => 400,
                'message' => 'Unauthorized password reset request. Please verify OTP first.'
            ], 400);
        }

        $user = User::where('email', $request->email)->first();
        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        // Clean up OTP record
        $otpRecord->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Password reset successfully.'
        ], 200);
    }

    public function resendOtp(Request $request)
    {
        return $this->sendOtp($request);
    }
}
