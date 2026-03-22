<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NiaLinkService;
use Illuminate\Http\Request;

class NiaCodeController extends Controller
{
    protected $niaLinkService;

    /**
     * Dependency Injection: Laravel automatically provides the NiaLinkService here.
     */
    public function __construct(NiaLinkService $niaLinkService)
    {
        $this->niaLinkService = $niaLinkService;
    }

    /**
     * Handles the 'Generate Code' button click in the mobile app.
     */
    public function store(Request $request)
    {
        // 1. Get the authenticated user ID from the Sanctum token
        $userId = $request->user()->id;

        // 2. Delegate the generation logic to the service
        $result = $this->niaLinkService->generateCode($userId);

        // 3. Return the 6-digit code to the user's screen
        return response()->json([
            'status' => 'success',
            'data' => $result // Contains 'code' and 'expires_in'
        ]);
    }
}
