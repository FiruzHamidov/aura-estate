<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AccountDeletionController extends Controller
{
    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'status' => 'nullable|string|in:inactive',
            'reason' => 'nullable|string|max:255',
        ]);

        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($user, $validated) {
            $user->refresh();

            if ($user->deleted_at || $user->deletion_requested_at) {
                $this->revokeAccess($user);

                return;
            }

            $now = now();
            $photo = $user->photo;

            $user->forceFill([
                'status' => User::STATUS_INACTIVE,
                'deleted_at' => $now,
                'deletion_requested_at' => $now,
                'deletion_reason' => $validated['reason'] ?? 'user_requested_account_deletion',
                'deleted_by_user_id' => $user->id,
                'deletion_phone_hash' => User::accountDeletionPhoneHash((string) $user->phone),
                'name' => 'Deleted User',
                'email' => null,
                'phone' => 'deleted_'.$user->id,
                'photo' => null,
                'password' => Hash::make(Str::random(40)),
                'remember_token' => null,
                'telegram_id' => null,
                'telegram_username' => null,
                'telegram_photo_url' => null,
                'telegram_chat_id' => null,
                'telegram_linked_at' => null,
            ])->save();

            if ($photo && Storage::disk('public')->exists($photo)) {
                Storage::disk('public')->delete($photo);
            }

            $this->revokeAccess($user);
        });

        return response()->json([
            'message' => 'Account deletion completed',
        ]);
    }

    private function revokeAccess(User $user): void
    {
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }
}
