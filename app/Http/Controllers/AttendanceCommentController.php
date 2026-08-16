<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDailyComment;
use App\Models\AttendanceDailySummary;
use App\Models\User;
use App\Services\Attendance\AttendanceAccessService;
use App\Services\Attendance\AttendanceAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AttendanceCommentController extends Controller
{
    public function __construct(
        private readonly AttendanceAccessService $access,
        private readonly AttendanceAuditService $audit,
    ) {}

    public function upsert(Request $request, User $user, string $date)
    {
        $this->access->assertCanComment($request->user());
        $this->access->assertCanViewUser($request->user(), $user);
        $this->validateDate($date);
        $data = $request->validate([
            'comment' => ['required', 'string', 'min:1', 'max:2000'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $summary = AttendanceDailySummary::query()->where('user_id', $user->id)->whereDate('work_date', $date)->first();
        if ($summary === null || (int) $summary->late_minutes <= 0) {
            throw ValidationException::withMessages(['comment' => ['Комментарий можно добавить только к зафиксированному опозданию.']]);
        }

        $comment = DB::transaction(function () use ($request, $user, $date, $data) {
            $existing = AttendanceDailyComment::query()->where('user_id', $user->id)->whereDate('work_date', $date)->lockForUpdate()->first();
            $currentVersion = (int) ($existing?->version ?? 0);
            if ($currentVersion !== (int) $data['version']) {
                $this->conflict($existing);
            }
            $old = $existing?->only(['comment', 'version']) ?? [];
            $comment = $existing ?? new AttendanceDailyComment([
                'user_id' => $user->id,
                'work_date' => $date,
                'created_by' => $request->user()->id,
            ]);
            $comment->fill([
                'comment' => trim($data['comment']),
                'updated_by' => $request->user()->id,
                'version' => $currentVersion + 1,
            ])->save();
            $this->audit->record(
                $request->user(),
                $old === [] ? 'attendance_comment.created' : 'attendance_comment.updated',
                $comment,
                $old,
                $comment->only(['comment', 'version']),
                $request
            );

            return $comment->load('author:id,name');
        });

        return response()->json(['data' => $this->payload($comment)]);
    }

    public function destroy(Request $request, User $user, string $date)
    {
        $this->access->assertCanComment($request->user());
        $this->access->assertCanViewUser($request->user(), $user);
        $this->validateDate($date);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        DB::transaction(function () use ($request, $user, $date, $data) {
            $comment = AttendanceDailyComment::query()->where('user_id', $user->id)->whereDate('work_date', $date)->lockForUpdate()->firstOrFail();
            if ((int) $comment->version !== (int) $data['version']) {
                $this->conflict($comment);
            }
            $old = $comment->only(['comment', 'version']);
            $this->audit->record($request->user(), 'attendance_comment.deleted', $comment, $old, [], $request);
            $comment->delete();
        });

        return response()->json(['message' => 'Комментарий удалён.']);
    }

    private function validateDate(string $date): void
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            $parsed = false;
        }
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => ['Дата должна быть в формате YYYY-MM-DD.']]);
        }
    }

    private function conflict(?AttendanceDailyComment $comment): never
    {
        abort(response()->json([
            'code' => 'ATTENDANCE_COMMENT_VERSION_CONFLICT',
            'message' => 'Комментарий уже изменён другим пользователем.',
            'details' => ['current' => $comment ? $this->payload($comment->loadMissing('author:id,name')) : null],
            'trace_id' => request()->attributes->get('trace_id'),
        ], 409));
    }

    private function payload(AttendanceDailyComment $comment): array
    {
        return [
            'id' => $comment->id,
            'comment' => $comment->comment,
            'version' => $comment->version,
            'author' => $comment->author ? $comment->author->only(['id', 'name']) : null,
            'updated_at' => $comment->updated_at?->toISOString(),
        ];
    }
}
