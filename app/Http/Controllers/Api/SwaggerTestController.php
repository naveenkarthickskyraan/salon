<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use OpenApi\Attributes as OA;

class SwaggerTestController extends Controller
{
    #[OA\Get(
        path: '/api/swagger-test',
        operationId: 'swaggerTest',
        tags: ['Swagger Test'],
        summary: 'Test Swagger API',
        description: 'Check whether Swagger documentation is working',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Swagger is working successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'status',
                            type: 'boolean',
                            example: true
                        ),
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Swagger is working successfully'
                        )
                    ]
                )
            )
        ]
    )]
    public function index()
    {
        return response()->json([
            'status' => true,
            'message' => 'Swagger is working successfully'
        ]);
    }
}
