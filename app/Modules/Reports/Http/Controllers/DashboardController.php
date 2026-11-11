<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Models\User;
use App\Modules\Reports\Services\DashboardCards;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @tags Dashboard
 */
class DashboardController extends Controller
{
    /**
     * Figures for my home page. Each card appears only when it applies to me (my records or my permissions).
     * Card: `key`, `label`, `value`, `format` number|money|percent|text, `tone` neutral|success|warning|danger, `hint`, `link` (a screen name).
     */
    public function show(Request $request, DashboardCards $cards): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::data(['cards' => $cards->for($user)]);
    }
}
