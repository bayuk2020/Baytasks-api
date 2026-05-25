<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\TelegramSetting;

use Illuminate\Http\Request;

class TelegramSettingController extends Controller
{
    // =========================
    // GET
    // =========================

    public function show()
    {
        $setting =
            TelegramSetting::first();

        return response()->json([
            'setting' =>
                $setting,
        ]);
    }

    // =========================
    // SAVE
    // =========================

    public function save(
        Request $request
    ) {

        $setting =
            TelegramSetting::updateOrCreate(

                [
                    'user_id' => 1,
                ],

                [
                    'chat_id' =>
                        $request->chat_id,

                    'enabled' =>
                        $request->enabled,

                    'daily_briefing' =>
                        $request->daily_briefing,
                ]
            );

        return response()->json([

            'success' => true,

            'setting' => $setting,
        ]);
    }
}