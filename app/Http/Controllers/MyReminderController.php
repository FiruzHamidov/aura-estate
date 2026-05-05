<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserDailyReportReminderSetting;
use App\Support\Notifications\NotificationChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class MyReminderController extends Controller
{
    public function showDailyReport()
    {
        $user = $this->authUser();
        $setting = UserDailyReportReminderSetting::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'enabled' => false,
                'remind_time' => '18:30',
                'timezone' => 'Asia/Dushanbe',
                'channels' => [NotificationChannel::IN_APP],
            ]
        );

        return response()->json($this->payload($setting));
    }

    public function updateDailyReport(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'remind_time' => 'required|date_format:H:i',
            'timezone' => 'required|timezone',
            'channels' => 'nullable|array|min:1',
            'channels.*' => ['string', Rule::in([
                NotificationChannel::IN_APP,
                NotificationChannel::TELEGRAM,
                NotificationChannel::PUSH,
            ])],
        ]);

        $user = $this->authUser();
        $setting = UserDailyReportReminderSetting::query()->firstOrNew(['user_id' => $user->id]);
        $setting->fill([
            'enabled' => $validated['enabled'],
            'remind_time' => $validated['remind_time'],
            'timezone' => $validated['timezone'],
            'channels' => array_values($validated['channels'] ?? [NotificationChannel::IN_APP]),
        ]);
        $setting->save();

        return response()->json($this->payload($setting));
    }

    private function payload(UserDailyReportReminderSetting $setting): array
    {
        return [
            'enabled' => (bool) $setting->enabled,
            'remind_time' => (string) $setting->remind_time,
            'timezone' => (string) $setting->timezone,
            'channels' => array_values($setting->channels ?? [NotificationChannel::IN_APP]),
        ];
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user, 401, 'Unauthenticated.');

        return $user;
    }
}
