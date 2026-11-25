<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    /* ------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    private function passwordRules(bool $confirmed = false): array
    {
        $rules = [
            'string', 'min:8',
            'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/',
        ];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }

    private function passwordMessages(): array
    {
        return [
            'password.min'   => 'Password must be at least 8 characters.',
            'password.regex' => 'Password must include one uppercase, lowercase, a number and a special character.',
        ];
    }

    private function sendOtp(Partner $partner): void
    {
        $code = (string) random_int(1000, 9999);

        $partner->update([
            'otp'            => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        // Development: code is always available in storage/logs/laravel.log
        \Log::info("Partner OTP for {$partner->email}: $code");

        if (app()->environment('local')) {
            return;
        }

        try {
            Mail::raw("Your Salon verification code is $code", function ($m) use ($partner) {
                $m->to($partner->email)->subject('Salon verification code');
            });
        } catch (\Throwable $e) {
            \Log::warning('Mail failed: ' . $e->getMessage());
        }
    }

    private function otpIsValid(?Partner $partner, string $value): bool
    {
        return $partner
            && $partner->otp
            && $partner->otp_expires_at
            && now()->lte($partner->otp_expires_at)
            && Hash::check($value, $partner->otp);
    }

    /* ------------------------------------------------------------------
     | Register (email / google / apple)
     * ------------------------------------------------------------------ */
#[OA\Post(
    path: '/api/partner/register',
    operationId: 'partnerRegister',
    tags: ['Partner Auth'],
    summary: 'Partner sign up (0 = email, 1 = google, 2 = apple)',
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['register_type'],
        properties: [
            new OA\Property(property: 'register_type', type: 'integer', enum: [0, 1, 2], example: 0, description: '0 = email, 1 = google, 2 = apple'),
            new OA\Property(property: 'email', type: 'string', example: 'owner@salon.com', description: 'Only for type 0'),
            new OA\Property(property: 'password', type: 'string', example: 'Salon@1234', description: 'Only for type 0'),
            new OA\Property(property: 'social_id', type: 'string', description: 'Type 1: Google ID, type 2: Apple ID'),
            new OA\Property(property: 'token', type: 'string', description: 'Type 1: Google access_token, type 2: Apple identity_token'),
            new OA\Property(property: 'device_id', type: 'string'),
            new OA\Property(property: 'device_token', type: 'string'),
            new OA\Property(property: 'device_type', type: 'string', example: 'android'),
            new OA\Property(property: 'app_version', type: 'string', example: '1.0.0'),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'Email: code sent. Google/Apple: returns token'),
        new OA\Response(response: 401, description: 'Invalid social token / ID mismatch'),
        new OA\Response(response: 422, description: 'Validation error'),
    ]
)]
public function register(Request $request)
{
    $request->validate([
        'register_type' => 'required|integer|in:0,1,2',
        'email'         => 'required_if:register_type,0|nullable|email',
        'password'      => array_merge(['required_if:register_type,0', 'nullable'], $this->passwordRules()),
        'social_id'     => 'required_unless:register_type,0|nullable|string',
        'token'         => 'required_unless:register_type,0|nullable|string',
        'device_id'     => 'nullable|string',
        'device_token'  => 'nullable|string',
        'device_type'   => 'nullable|in:android,ios',
        'app_version'   => 'nullable|string|max:20',
    ], ['email.email' => 'Enter valid email address'] + $this->passwordMessages());

    $types = [0 => 'email', 1 => 'google', 2 => 'apple'];
    $type  = $types[(int) $request->register_type];

    $device = $request->only(['device_id', 'device_token', 'device_type', 'app_version']);

    // ---------- Google / Apple ----------
    if ($type !== 'email') {
        $info = $type === 'google'
            ? $this->verifyGoogle($request->token)
            : $this->verifyApple($request->token);

        if (!$info) {
            return response()->json(['status' => false, 'message' => 'Invalid social token'], 401);
        }

        if (!hash_equals((string) $info['sub'], (string) $request->social_id)) {
            return response()->json(['status' => false, 'message' => 'Social ID mismatch'], 401);
        }

        $partner = $this->findOrCreateSocialPartner($type, $info);

        if (!$partner) {
            return response()->json(['status' => false, 'message' => 'Email not available'], 422);
        }

        $isNew = $partner->wasRecentlyCreated;
        $partner->update($device);

        return response()->json([
            'status'      => true,
            'is_new_user' => $isNew,
            'token'       => $partner->createToken('partner')->plainTextToken,
        ]);
    }

    // ---------- Email ----------
    $partner = Partner::where('email', $request->email)->first();

    if ($partner && $partner->email_verified_at) {
        return response()->json(['status' => false, 'message' => 'The email has already been taken'], 422);
    }

    if ($partner) {
        $partner->update(['password' => $request->password] + $device);
    } else {
        $partner = Partner::create(['email' => $request->email, 'password' => $request->password] + $device);
    }

    $this->sendOtp($partner);

    return response()->json(['status' => true, 'message' => 'Verification code sent to email']);
}

    /* ------------------------------------------------------------------
     | Verify sign up OTP
     * ------------------------------------------------------------------ */

    #[OA\Post(
        path: '/api/partner/verify-otp',
        operationId: 'partnerVerifyOtp',
        tags: ['Partner Auth'],
        summary: 'Verify sign up code',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'otp'],
            properties: [
                new OA\Property(property: 'email', type: 'string', example: 'owner@salon.com'),
                new OA\Property(property: 'otp', type: 'string', example: '1234'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Verified, returns token'),
            new OA\Response(response: 422, description: 'Invalid or expired code'),
        ]
    )]
    public function verifyOtp(Request $request)
    {
        $request->validate(['email' => 'required|email', 'otp' => 'required|digits:4']);

        $partner = Partner::where('email', $request->email)->first();

        if (!$this->otpIsValid($partner, $request->otp)) {
            return response()->json(['status' => false, 'message' => 'Invalid or expired code'], 422);
        }

        $partner->update(['email_verified_at' => now(), 'otp' => null, 'otp_expires_at' => null]);

        return response()->json([
            'status'  => true,
            'message' => 'Email verified',
            'token'   => $partner->createToken('partner')->plainTextToken,
        ]);
    }

    #[OA\Post(
        path: '/api/partner/resend-otp',
        operationId: 'partnerResendOtp',
        tags: ['Partner Auth'],
        summary: 'Resend verification code',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [new OA\Property(property: 'email', type: 'string', example: 'owner@salon.com')]
        )),
        responses: [new OA\Response(response: 200, description: 'Code sent')]
    )]
    public function resendOtp(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $partner = Partner::where('email', $request->email)->first();
        if ($partner) {
            $this->sendOtp($partner);
        }

        return response()->json(['status' => true, 'message' => 'If the email exists, a code was sent']);
    }

    /* ------------------------------------------------------------------
     | Login (email / google / apple)
     * ------------------------------------------------------------------ */

    #[OA\Post(
    path: '/api/partner/login',
    operationId: 'partnerLogin',
    tags: ['Partner Auth'],
    summary: 'Login (0 = email, 1 = google, 2 = apple)',
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['login_type'],
        properties: [
            new OA\Property(property: 'login_type', type: 'integer', enum: [0, 1, 2], example: 0, description: '0 = email, 1 = google, 2 = apple'),
            new OA\Property(property: 'email', type: 'string', example: 'owner@salon.com', description: 'Only for type 0'),
            new OA\Property(property: 'password', type: 'string', example: 'Salon@1234', description: 'Only for type 0'),
            new OA\Property(property: 'social_id', type: 'string', description: 'Type 1: Google ID, type 2: Apple ID'),
            new OA\Property(property: 'token', type: 'string', description: 'Type 1: Google access_token, type 2: Apple identity_token'),
            new OA\Property(property: 'device_id', type: 'string'),
            new OA\Property(property: 'device_token', type: 'string'),
            new OA\Property(property: 'device_type', type: 'string', example: 'android'),
            new OA\Property(property: 'app_version', type: 'string', example: '1.0.0'),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'Returns token'),
        new OA\Response(response: 401, description: 'Invalid credentials / token / ID mismatch'),
        new OA\Response(response: 403, description: 'Email not verified'),
        new OA\Response(response: 422, description: 'Validation error'),
    ]
)]
public function login(Request $request)
{
    $request->validate([
        'login_type'   => 'required|integer|in:0,1,2',
        'email'        => 'required_if:login_type,0|nullable|email',
        'password'     => 'required_if:login_type,0|nullable|string',
        'social_id'    => 'required_unless:login_type,0|nullable|string',
        'token'        => 'required_unless:login_type,0|nullable|string',
        'device_id'    => 'nullable|string',
        'device_token' => 'nullable|string',
        'device_type'  => 'nullable|in:android,ios',
        'app_version'  => 'nullable|string|max:20',
    ]);

    $types = [0 => 'email', 1 => 'google', 2 => 'apple'];
    $type  = $types[(int) $request->login_type];

    if ($type === 'email') {
        $partner = Partner::where('email', $request->email)->first();

        if (!$partner || !Hash::check($request->password, $partner->password)) {
            return response()->json(['status' => false, 'message' => 'Invalid email or password'], 401);
        }

        if (!$partner->email_verified_at) {
            return response()->json(['status' => false, 'message' => 'Email not verified'], 403);
        }
    } else {
        $info = $type === 'google'
            ? $this->verifyGoogle($request->token)
            : $this->verifyApple($request->token);

        if (!$info) {
            return response()->json(['status' => false, 'message' => 'Invalid social token'], 401);
        }

        if (!hash_equals((string) $info['sub'], (string) $request->social_id)) {
            return response()->json(['status' => false, 'message' => 'Social ID mismatch'], 401);
        }

        $partner = $this->findOrCreateSocialPartner($type, $info);

        if (!$partner) {
            return response()->json(['status' => false, 'message' => 'Email not available'], 422);
        }
    }

    $partner->update($request->only(['device_id', 'device_token', 'device_type', 'app_version']));

    return response()->json([
        'status'  => true,
        'token'   => $partner->createToken('partner')->plainTextToken,
        'partner' => ['id' => $partner->id, 'email' => $partner->email],
    ]);
}

    /* ------------------------------------------------------------------
     | Forgot password (3 steps) + logout
     * ------------------------------------------------------------------ */

    #[OA\Post(
        path: '/api/partner/forgot-password',
        operationId: 'partnerForgotPassword',
        tags: ['Partner Auth'],
        summary: 'Send reset code to email',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [new OA\Property(property: 'email', type: 'string', example: 'owner@salon.com')]
        )),
        responses: [new OA\Response(response: 200, description: 'Code sent')]
    )]
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $partner = Partner::where('email', $request->email)->first();
        if ($partner) {
            $this->sendOtp($partner);
        }

        return response()->json(['status' => true, 'message' => 'If the email exists, a code was sent']);
    }

    #[OA\Post(
        path: '/api/partner/forgot-password/verify',
        operationId: 'partnerForgotVerify',
        tags: ['Partner Auth'],
        summary: 'Verify reset code',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'otp'],
            properties: [
                new OA\Property(property: 'email', type: 'string', example: 'owner@salon.com'),
                new OA\Property(property: 'otp', type: 'string', example: '1234'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Returns reset_token'),
            new OA\Response(response: 422, description: 'Invalid or expired code'),
        ]
    )]
    public function forgotVerify(Request $request)
    {
        $request->validate(['email' => 'required|email', 'otp' => 'required|digits:4']);

        $partner = Partner::where('email', $request->email)->first();

        if (!$this->otpIsValid($partner, $request->otp)) {
            return response()->json(['status' => false, 'message' => 'Invalid or expired code'], 422);
        }

        $resetToken = Str::random(60);

        $partner->update([
            'otp'            => Hash::make($resetToken),
            'otp_expires_at' => now()->addMinutes(15),
        ]);

        return response()->json(['status' => true, 'reset_token' => $resetToken]);
    }

    #[OA\Post(
        path: '/api/partner/reset-password',
        operationId: 'partnerResetPassword',
        tags: ['Partner Auth'],
        summary: 'Set new password',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'reset_token', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'email', type: 'string', example: 'owner@salon.com'),
                new OA\Property(property: 'reset_token', type: 'string', description: 'Returned by forgot-password/verify'),
                new OA\Property(property: 'password', type: 'string', example: 'Salon@1234'),
                new OA\Property(property: 'password_confirmation', type: 'string', example: 'Salon@1234'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Password changed'),
            new OA\Response(response: 422, description: 'Invalid token or validation error'),
        ]
    )]
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'       => 'required|email',
            'reset_token' => 'required|string',
            'password'    => array_merge(['required'], $this->passwordRules(true)),
        ], $this->passwordMessages());

        $partner = Partner::where('email', $request->email)->first();

        if (!$this->otpIsValid($partner, $request->reset_token)) {
            return response()->json(['status' => false, 'message' => 'Invalid or expired token'], 422);
        }

        $partner->update([
            'password'       => $request->password,
            'otp'            => null,
            'otp_expires_at' => null,
        ]);

        $partner->tokens()->delete();

        return response()->json(['status' => true, 'message' => 'Password changed']);
    }

    #[OA\Post(
    path: '/api/partner/logout',
    operationId: 'partnerLogout',
    tags: ['Partner Auth'],
    summary: 'Logout',
    security: [['sanctum' => []]],
    requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'device_id', type: 'string', description: 'Optional. Clears the push token if it matches this device'),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'Logged out'),
        new OA\Response(response: 401, description: 'Unauthenticated'),
    ]
)]
public function logout(Request $request)
{
    $request->validate(['device_id' => 'nullable|string']);

    $partner = $request->user();

    $partner->currentAccessToken()->delete();

    // Stop push notifications to this device (only if it is the stored one)
    if ($request->filled('device_id') && $partner->device_id === $request->device_id) {
        $partner->update(['device_id' => null, 'device_token' => null]);
    }

    return response()->json(['status' => true, 'message' => 'Logged out']);
}

    /* ------------------------------------------------------------------
     | Social helpers (not exposed as routes)
     * ------------------------------------------------------------------ */

    private function findOrCreateSocialPartner(string $provider, array $info): ?Partner
    {
        $column = $provider === 'google' ? 'google_id' : 'apple_id';

        // 1. Provider ID match
        $partner = Partner::where($column, $info['sub'])->first();

        // 2. Same email already registered: link it
        if (!$partner && !empty($info['email'])) {
            $partner = Partner::where('email', $info['email'])->first();
            $partner?->update([$column => $info['sub']]);
        }

        // 3. New partner
        if (!$partner) {
            if (empty($info['email'])) {
                return null;
            }

            $partner = Partner::create([
                'email'             => $info['email'],
                $column             => $info['sub'],
                'password'          => Str::random(32),
                'email_verified_at' => now(),
            ]);
        }

        if (!$partner->email_verified_at) {
            $partner->update(['email_verified_at' => now()]);
        }

        return $partner;
    }

    private function verifyGoogle(string $accessToken): ?array
    {
        $check = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'access_token' => $accessToken,
        ]);

        if (!$check->ok() || $check->json('aud') !== config('services.google.client_id')) {
            return null;
        }

        try {
            $social = Socialite::driver('google')->stateless()->userFromToken($accessToken);
        } catch (\Throwable $e) {
            return null;
        }

        return $social->getEmail()
            ? ['email' => $social->getEmail(), 'sub' => $social->getId()]
            : null;
    }

    private function verifyApple(string $identityToken): ?array
    {
        try {
            $keys = Cache::remember('apple_jwks', 3600, fn () =>
                Http::get('https://appleid.apple.com/auth/keys')->json()
            );

            $claims = (array) JWT::decode($identityToken, JWK::parseKeySet($keys, 'RS256'));

            if (($claims['iss'] ?? '') !== 'https://appleid.apple.com'
                || !in_array($claims['aud'] ?? '', config('services.apple.client_ids'), true)
                || empty($claims['sub'])) {
                return null;
            }

            return ['email' => $claims['email'] ?? null, 'sub' => $claims['sub']];
        } catch (\Throwable $e) {
            \Log::warning('Apple verify failed: ' . $e->getMessage());
            return null;
        }
    }
}