<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\OtpVerification;
use App\Models\Salon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class SalonSetupController extends Controller
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    #[OA\Post(
        path: '/api/partner/salon-setup',
        operationId: 'partnerSalonSetup',
        tags: ['Partner Salon Setup'],
        summary: 'Create salon (setup steps 1 to 3)',
        description: 'Saves basic details, working hours and location in one transaction. Email and mobile OTPs are verified before saving. One salon per partner.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: [
                        'business_name', 'business_cover_images[]', 'business_type', 'email', 'email_otp',
                        'owner_name', 'mobile_number', 'mobile_otp', 'working_hours',
                        'pincode', 'country', 'state', 'city', 'locality', 'address_line',
                        'latitude', 'longitude',
                    ],
                    properties: [
                        new OA\Property(property: 'business_name', type: 'string', example: 'Madona Beauty Salon'),
                        new OA\Property(
                            property: 'business_cover_images[]',
                            type: 'array',
                            description: '1 to 12 images (jpg, jpeg, png, webp, max 5 MB each). Swagger sends one file reliably; use Postman for several.',
                            items: new OA\Items(type: 'string', format: 'binary')
                        ),
                        new OA\Property(property: 'business_type', type: 'string', enum: ['unisex', 'men', 'women'], example: 'unisex'),
                        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'madona@gmail.com'),
                        new OA\Property(property: 'email_otp', type: 'string', example: '1403'),
                        new OA\Property(property: 'owner_name', type: 'string', example: 'Madonna'),
                        new OA\Property(property: 'mobile_number', type: 'string', example: '9876543211'),
                        new OA\Property(property: 'mobile_otp', type: 'string', example: '1234'),
                        new OA\Property(property: 'alternative_number', type: 'string', nullable: true, example: '9876543212'),
                        new OA\Property(property: 'alternative_number_otp', type: 'string', nullable: true, example: '5678'),
                        new OA\Property(
                            property: 'working_hours',
                            type: 'string',
                            description: 'Swagger: paste as one JSON string with exactly 7 days (time format H:i, 24-hour). The real app sends working_hours[0][day], working_hours[0][is_open], etc.',
                            example: '[{"day":"monday","is_open":1,"opening_time":"09:00","closing_time":"22:00"},{"day":"tuesday","is_open":1,"opening_time":"09:00","closing_time":"22:00"},{"day":"wednesday","is_open":1,"opening_time":"09:00","closing_time":"22:00"},{"day":"thursday","is_open":1,"opening_time":"09:00","closing_time":"22:00"},{"day":"friday","is_open":1,"opening_time":"09:00","closing_time":"22:00"},{"day":"saturday","is_open":0},{"day":"sunday","is_open":0}]'
                        ),
                        new OA\Property(property: 'pincode', type: 'string', example: '642103'),
                        new OA\Property(property: 'country', type: 'string', example: 'India'),
                        new OA\Property(property: 'state', type: 'string', example: 'Tamil Nadu'),
                        new OA\Property(property: 'city', type: 'string', example: 'Coimbatore'),
                        new OA\Property(property: 'locality', type: 'string', example: 'Ghandhipuram'),
                        new OA\Property(property: 'address_line', type: 'string', example: '1/115, 2nd Street, Sangaman Street'),
                        new OA\Property(property: 'landmark', type: 'string', nullable: true, example: 'Tidel Park Opposite'),
                        new OA\Property(property: 'latitude', type: 'number', format: 'double', example: 11.0168445),
                        new OA\Property(property: 'longitude', type: 'number', format: 'double', example: 76.9558321),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Salon created',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'Salon created successfully.'),
                    new OA\Property(property: 'data', type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(
                response: 409,
                description: 'Salon already exists for this partner',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'boolean', example: false),
                    new OA\Property(property: 'message', type: 'string', example: 'Salon already created for this partner.'),
                ])
            ),
            new OA\Response(
                response: 422,
                description: 'Validation or OTP verification failed',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'boolean', example: false),
                    new OA\Property(property: 'message', type: 'string', example: 'Validation failed.'),
                    new OA\Property(property: 'errors', type: 'object'),
                ])
            ),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function store(Request $request)
    {
        $partner = $request->user();

        // 1. Duplicate check
        if (Salon::where('partner_id', $partner->id)->exists()) {
            return response()->json([
                'status'  => false,
                'message' => 'Salon already created for this partner.',
            ], 409);
        }

        // 2. Normalize input (Swagger sends JSON strings / single file, real app sends arrays)
        $data = $request->all();

        // working_hours: accept array, JSON string, list of JSON strings, or a single object
        $rows = [];
        $collect = function ($item) use (&$collect, &$rows) {
            if (is_string($item)) {
                $decoded = json_decode($item, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return;
                }
                $item = $decoded;
            }
            if (!is_array($item)) {
                return;
            }
            if (isset($item['day'])) {
                $rows[] = $item;
                return;
            }
            foreach ($item as $sub) {
                $collect($sub);
            }
        };
        if (isset($data['working_hours'])) {
            $collect($data['working_hours']);
            $data['working_hours'] = $rows;
        }

        // cover images: always an array, even if only one file arrives
        $coverImages = Arr::wrap($request->file('business_cover_images'));
        $data['business_cover_images'] = $coverImages;

        // 3. Validation
        $validator = Validator::make($data, $this->rules(), $this->messages());
        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // 4. OTP verification (before any write)
        $otpErrors = [];
        if (!$this->otpIsValid('email', $request->email, $request->email_otp)) {
            $otpErrors['email_otp'] = ['Invalid or expired email OTP.'];
        }
        if (!$this->otpIsValid('mobile', $request->mobile_number, $request->mobile_otp)) {
            $otpErrors['mobile_otp'] = ['Invalid or expired mobile OTP.'];
        }
        if ($request->filled('alternative_number')
            && !$this->otpIsValid('mobile', $request->alternative_number, $request->alternative_number_otp)) {
            $otpErrors['alternative_number_otp'] = ['Invalid or expired alternative number OTP.'];
        }
        if ($otpErrors) {
            return response()->json([
                'status'  => false,
                'message' => 'OTP verification failed.',
                'errors'  => $otpErrors,
            ], 422);
        }

        // 5. Save everything in one transaction
        $storedPaths = [];

        try {
            $salon = DB::transaction(function () use ($request, $partner, $data, $coverImages, &$storedPaths) {
                $salon = Salon::create([
                    'partner_id'         => $partner->id,
                    'business_name'      => $request->business_name,
                    'business_type'      => $request->business_type,
                    'email'              => $request->email,
                    'email_verified_at'  => now(),
                    'owner_name'         => $request->owner_name,
                    'mobile_number'      => $request->mobile_number,
                    'mobile_verified_at' => now(),
                    'alternative_number' => $request->alternative_number,
                    'alternative_number_verified_at' => $request->filled('alternative_number') ? now() : null,
                    'pincode'            => $request->pincode,
                    'country'            => $request->country,
                    'state'              => $request->state,
                    'city'               => $request->city,
                    'locality'           => $request->locality,
                    'address_line'       => $request->address_line,
                    'landmark'           => $request->landmark,
                    'latitude'           => $request->latitude,
                    'longitude'          => $request->longitude,
                ]);

                // Cover images
                foreach (array_values($coverImages) as $i => $image) {
                    $path = $image->store("salons/{$partner->id}", 'public');
                    $storedPaths[] = $path;
                    $salon->coverImages()->create(['image_path' => $path, 'sort_order' => $i]);
                }

                // Working hours
                $hours = [];
                foreach ($data['working_hours'] as $row) {
                    $open = (int) $row['is_open'] === 1;
                    $hours[] = [
                        'day'          => strtolower($row['day']),
                        'is_open'      => $open,
                        'opening_time' => $open ? $row['opening_time'] : "",
                        'closing_time' => $open ? $row['closing_time'] : "",
                    ];
                }
                $salon->workingHours()->createMany($hours);

                // Consume used OTPs
                OtpVerification::where('type', 'email')->where('identifier', $request->email)->delete();
                $mobiles = array_filter([$request->mobile_number, $request->alternative_number]);
                OtpVerification::where('type', 'mobile')->whereIn('identifier', $mobiles)->delete();

                return $salon;
            });
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            // Unique constraint hit (double tap / race condition)
            if ($e instanceof QueryException && $e->getCode() === '23000') {
                return response()->json([
                    'status'  => false,
                    'message' => 'Salon already exists for this partner, or email/mobile is already in use.',
                ], 409);
            }

            Log::error('Salon setup failed', ['partner_id' => $partner->id, 'error' => $e->getMessage()]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while creating the salon.',
            ], 500);
        }

        $salon->load(['coverImages', 'workingHours']);

        return response()->json([
            'status'  => true,
            'message' => 'Salon created successfully.',
            'data'    => $this->formatSalon($salon),
        ], 201);
    }

    private function rules(): array
    {
        return [
            // Step 1
            'business_name'           => 'required|string|max:150',
            'business_cover_images'   => 'required|array|min:1|max:12',
            'business_cover_images.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
            'business_type'           => 'required|in:unisex,men,women',
            'email'                   => 'required|email:rfc|max:150|unique:salons,email',
            'email_otp'               => 'required|digits:4',
            'owner_name'              => 'required|string|max:100',
            'mobile_number'           => ['required', 'regex:/^[6-9]\d{9}$/', 'unique:salons,mobile_number'],
            'mobile_otp'              => 'required|digits:4',
            'alternative_number'      => ['nullable', 'regex:/^[6-9]\d{9}$/', 'different:mobile_number'],
            'alternative_number_otp'  => 'required_with:alternative_number|nullable|digits:4',

            // Step 2
            'working_hours'                => 'required|array|size:7',
            'working_hours.*.day'          => 'required|distinct|in:' . implode(',', self::DAYS),
            'working_hours.*.is_open'      => 'required|in:0,1',
            'working_hours.*.opening_time' => 'required_if:working_hours.*.is_open,1|nullable|date_format:H:i',
            'working_hours.*.closing_time' => 'required_if:working_hours.*.is_open,1|nullable|date_format:H:i|after:working_hours.*.opening_time',

            // Step 3
            'pincode'      => 'required|digits:6',
            'country'      => 'required|string|max:100',
            'state'        => 'required|string|max:100',
            'city'         => 'required|string|max:100',
            'locality'     => 'required|string|max:150',
            'address_line' => 'required|string|max:255',
            'landmark'     => 'nullable|string|max:255',
            'latitude'     => 'required|numeric|between:-90,90',
            'longitude'    => 'required|numeric|between:-180,180',
        ];
    }

    private function messages(): array
    {
        return [
            'working_hours.size'                       => 'Working hours must be provided for all 7 days.',
            'working_hours.*.day.required'             => 'Day is required for each working hour entry.',
            'working_hours.*.day.distinct'             => 'Each day can only appear once.',
            'working_hours.*.is_open.required'         => 'Open/closed status is required for each day.',
            'working_hours.*.opening_time.required_if' => 'Opening time is required for open days.',
            'working_hours.*.closing_time.required_if' => 'Closing time is required for open days.',
            'working_hours.*.closing_time.after'       => 'Closing time must be after opening time.',
            'business_cover_images.required'           => 'Please upload at least one cover image.',
            'business_cover_images.max'                => 'You can upload up to 12 cover images.',
            'business_cover_images.*.image'            => 'Each cover file must be an image.',
            'business_cover_images.*.max'              => 'Each cover image must not exceed 5 MB.',
            'mobile_number.regex'                      => 'Enter a valid 10-digit mobile number.',
            'alternative_number.regex'                 => 'Enter a valid 10-digit alternative number.',
            'alternative_number.different'             => 'Alternative number must differ from the mobile number.',
        ];
    }

    private function otpIsValid(string $type, string $identifier, ?string $otp): bool
    {
        if (!$otp) {
            return false;
        }

        $record = OtpVerification::where('type', $type)
            ->where('identifier', $identifier)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (!$record || $record->attempts >= 5) {
            return false;
        }

        if (!Hash::check($otp, $record->otp)) {
            $record->increment('attempts');
            return false;
        }

        return true;
    }

    private function formatSalon(Salon $salon): array
    {
        return [
            'id'                 => $salon->id,
            'partner_id'         => $salon->partner_id,
            'business_name'      => $salon->business_name,
            'business_type'      => $salon->business_type,
            'email'              => $salon->email,
            'owner_name'         => $salon->owner_name,
            'mobile_number'      => $salon->mobile_number,
            'alternative_number' => $salon->alternative_number,
            'cover_images'       => $salon->coverImages->map(fn ($i) => [
                'id'  => $i->id,
                'url' => Storage::disk('public')->url($i->image_path),
            ])->values(),
            'working_hours'      => $salon->workingHours->map(fn ($h) => [
                'day'          => $h->day,
                'is_open'      => (int) $h->is_open,
                'opening_time' => $h->opening_time ? substr($h->opening_time, 0, 5) : null,
                'closing_time' => $h->closing_time ? substr($h->closing_time, 0, 5) : null,
            ])->values(),
            'location' => [
                'pincode'      => $salon->pincode,
                'country'      => $salon->country,
                'state'        => $salon->state,
                'city'         => $salon->city,
                'locality'     => $salon->locality,
                'address_line' => $salon->address_line,
                'landmark'     => $salon->landmark,
                'latitude'     => $salon->latitude,
                'longitude'    => $salon->longitude,
            ],
            'created_at' => $salon->created_at->toDateTimeString(),
        ];
    }
}