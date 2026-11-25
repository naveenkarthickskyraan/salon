<?php

namespace App\Docs;

use OpenApi\Attributes as OA;

#[OA\Info(title: 'Salon Partner API', version: '1.0.0')]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum',
    description: 'Paste only the Sanctum token'
)]
class PartnerDoc {}