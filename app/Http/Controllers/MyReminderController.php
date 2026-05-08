<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserDailyReportReminderSetting;
use App\Support\Notifications\NotificationChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class MyReminderController extends Controller
{
    public function showDailyReport()
    {
        $user = $this->authUser();
        $hasAllowEditColumn = $this->hasAllowEditColumn();

        $defaults = [
            'enabled' => false,
            'remind_time' => '18:30',
            'timezone' => 'Asia/Dushanbe',
            'channels' => [NotificationChannel::IN_APP],
        ];

        if ($hasAllowEditColumn) {
            $defaults['allow_edit_submitted_daily_report'] = false;
        }

        $setting = UserDailyReportReminderSetting::query()->firstOrCreate(
            ['user_id' => $user->id],
            $defaults
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
            'allow_edit_submitted_daily_report' => 'nullable|boolean',
            'channels.*' => ['string', Rule::in([
                NotificationChannel::IN_APP,
                NotificationChannel::TELEGRAM,
                NotificationChannel::PUSH,
            ])],
        ]);

        $user = $this->authUser();
        $setting = UserDailyReportReminderSetting::query()->firstOrNew(['user_id' => $user->id]);
        $payload = [
            'enabled' => $validated['enabled'],
            'remind_time' => $validated['remind_time'],
            'timezone' => $validated['timezone'],
            'channels' => array_values($validated['channels'] ?? [NotificationChannel::IN_APP]),
        ];

        if ($this->hasAllowEditColumn()) {
            $payload['allow_edit_submitted_daily_report'] = array_key_exists('allow_edit_submitted_daily_report', $validated)
                ? (bool) $validated['allow_edit_submitted_daily_report']
                : (bool) $setting->allow_edit_submitted_daily_report;
        }

        $setting->fill($payload);
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
            'allow_edit_submitted_daily_report' => (bool) ($setting->allow_edit_submitted_daily_report ?? false),
        ];
    }

    private function hasAllowEditColumn(): bool
    {
        return Schema::hasTable('user_daily_report_reminder_settings')
            && Schema::hasColumn('user_daily_report_reminder_settings', 'allow_edit_submitted_daily_report');
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user, 401, 'Unauthenticated.');

        return $user;
    }
}
