<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\OtpVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class SalonOtpController extends Controller
{
    private const OTP_VALID_MINUTES = 30;  // long enough to finish the 3 screens
    private const RESEND_SECONDS    = 60;  // matches the resend timer in the design
    private const MAX_ATTEMPTS      = 5;

    #[OA\Post(
        path: '/api/partner/salon/send-otp',
        operationId: 'partnerSalonSendOtp',
        tags: ['Partner Salon Setup'],
        summary: 'Send OTP to salon email or mobile number',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'identifier'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['email', 'mobile'], example: 'mobile'),
                    new OA\Property(property: 'identifier', type: 'string', example: '9876543211'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'OTP sent'),
            new OA\Response(response: 422, description: 'Validation failed'),
            new OA\Response(response: 429, description: 'Please wait before requesting another OTP'),
        ]
    )]
    public function send(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type'       => 'required|in:email,mobile',
            'identifier' => [
                'required',
                function ($attr, $value, $fail) use ($request) {
                    if ($request->type === 'email') {
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $fail('Enter a valid email address.');
                        } elseif (\App\Models\Salon::where('email', $value)->exists()) {
                            $fail('This email is already used by another salon.');
                        }
                    } elseif ($request->type === 'mobile') {
                        if (!preg_match('/^[6-9]\d{9}$/', $value)) {
                            $fail('Enter a valid 10-digit mobile number.');
                        } elseif (\App\Models\Salon::where('mobile_number', $value)->exists()) {
                            $fail('This mobile number is already used by another salon.');
                        }
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $type       = $request->type;
        $identifier = $request->identifier;

        // Resend cooldown
        $last = OtpVerification::where('type', $type)->where('identifier', $identifier)->latest('id')->first();
        if ($last && $last->created_at->diffInSeconds(now()) < self::RESEND_SECONDS) {
            $wait = self::RESEND_SECONDS - (int) $last->created_at->diffInSeconds(now());
            return response()->json([
                'status'  => false,
                'message' => "Please wait {$wait} seconds before requesting a new OTP.",
                'resend_in' => $wait,
            ], 429);
        }

        $otp = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        OtpVerification::where('type', $type)->where('identifier', $identifier)->delete();
        OtpVerification::create([
            'type'       => $type,
            'identifier' => $identifier,
            'otp'        => Hash::make($otp),
            'attempts'   => 0,
            'expires_at' => now()->addMinutes(self::OTP_VALID_MINUTES),
        ]);

        try {
            if ($type === 'email') {
                Mail::raw("Your salon verification code is {$otp}. It is valid for " . self::OTP_VALID_MINUTES . " minutes.",
                    fn ($m) => $m->to($identifier)->subject('Salon verification code'));
            } else {
                // TODO: call your SMS provider here (MSG91 / Twilio / Fast2SMS ...)
                Log::info("Salon mobile OTP for {$identifier}: {$otp}");
            }
        } catch (\Throwable $e) {
            Log::error('Salon OTP send failed', ['type' => $type, 'error' => $e->getMessage()]);
            return response()->json([
                'status'  => false,
                'message' => 'Could not send OTP. Please try again.',
            ], 500);
        }

        $response = [
            'status'    => true,
            'message'   => 'OTP sent successfully.',
            'resend_in' => self::RESEND_SECONDS,
        ];

        // Local testing only
        if (app()->environment('local')) {
            $response['dev_otp'] = $otp;
        }

        return response()->json($response);
    }

    #[OA\Post(
        path: '/api/partner/salon/verify-otp',
        operationId: 'partnerSalonVerifyOtp',
        tags: ['Partner Salon Setup'],
        summary: 'Verify OTP for salon email or mobile number',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'identifier', 'otp'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['email', 'mobile'], example: 'mobile'),
                    new OA\Property(property: 'identifier', type: 'string', example: '9876543211'),
                    new OA\Property(property: 'otp', type: 'string', example: '1234'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'OTP verified'),
            new OA\Response(response: 422, description: 'Invalid or expired OTP'),
        ]
    )]
    public function verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type'       => 'required|in:email,mobile',
            'identifier' => 'required|string|max:150',
            'otp'        => 'required|digits:4',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $record = OtpVerification::where('type', $request->type)
            ->where('identifier', $request->identifier)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (!$record || $record->attempts >= self::MAX_ATTEMPTS) {
            return response()->json([
                'status'  => false,
                'message' => 'OTP expired or too many attempts. Please request a new OTP.',
            ], 422);
        }

        if (!Hash::check($request->otp, $record->otp)) {
            $record->increment('attempts');
            return response()->json([
                'status'  => false,
                'message' => 'Invalid OTP.',
            ], 422);
        }

        // Not deleted here: the final salon-setup API re-checks it and then removes it.
        return response()->json([
            'status'  => true,
            'message' => ucfirst($request->type) . ' verified successfully.',
        ]);
    }
}