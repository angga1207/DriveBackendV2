<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Data;
use App\Models\User;
use App\Traits\JsonReturner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

class AdminInsightsController extends Controller
{
    use JsonReturner;

    private function authorizeAdmin()
    {
        if (!auth()->user()?->hasAdminAccess()) {
            return $this->unauthorizedResponse('Akses ditolak', 403);
        }

        return null;
    }

    public function analytics()
    {
        if ($denied = $this->authorizeAdmin()) {
            return $denied;
        }

        $activeData = Data::query()->whereNull('temp_path')->whereNull('deleted_at');
        $fileData = (clone $activeData)->where('type', 'file');
        $folderData = (clone $activeData)->where('type', 'folder');

        $users = User::query()->where('status', 'active')->whereIn('access', ['true', 'false']);
        $userTotals = (clone $users)->selectRaw("COUNT(*) as total")
            ->selectRaw("COUNT(CASE WHEN access = 'true' THEN 1 END) as active")
            ->first();

        // Capacity is relevant only for users who have actually consumed storage.
        // MyDrive already excludes temporary and soft-deleted data.
        $usedStorageCapacity = (clone $users)
            ->whereHas('MyDrive', function ($query) {
                $query->where('type', 'file')->where('size', '>', 0);
            })
            ->sum('drive_capacity');

        $topUsers = (clone $users)
            ->addSelect(['used_storage' => Data::selectRaw('COALESCE(SUM(size), 0)')
                ->whereColumn('user_id', 'users.id')
                ->where('type', 'file')
                ->whereNull('temp_path')
                ->whereNull('deleted_at')
            ])
            ->withCount([
                'MyDrive as files_count' => fn ($query) => $query->where('type', 'file')->whereNull('deleted_at'),
                'MyDrive as folders_count' => fn ($query) => $query->where('type', 'folder')->whereNull('deleted_at'),
            ])
            ->orderByDesc('used_storage')
            ->limit(8)
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->fullname,
                'username' => $user->username,
                'used_storage' => (int) $user->used_storage,
                'capacity' => (int) $user->drive_capacity,
                'files' => (int) $user->files_count,
                'folders' => (int) $user->folders_count,
            ]);

        $extensionBreakdown = (clone $fileData)
            ->selectRaw("COALESCE(NULLIF(LOWER(extension), ''), 'lainnya') as extension")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(size), 0) as size')
            ->groupByRaw("COALESCE(NULLIF(LOWER(extension), ''), 'lainnya')")
            ->orderByDesc('size')
            ->limit(8)
            ->get()
            ->map(fn ($item) => [
                'extension' => $item->extension,
                'total' => (int) $item->total,
                'size' => (int) $item->size,
            ]);

        $activityRows = Activity::query()
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->get(['created_at']);
        $activityByDate = $activityRows->groupBy(fn ($activity) => $activity->created_at->format('Y-m-d'));
        $activityTrend = collect(range(29, 0))->map(function ($daysAgo) use ($activityByDate) {
            $date = now()->subDays($daysAgo)->format('Y-m-d');
            return ['date' => $date, 'total' => $activityByDate->get($date, collect())->count()];
        });

        $usedStorage = (int) (clone $fileData)->sum('size');

        return $this->successResponse([
            'summary' => [
                'users' => (int) ($userTotals->total ?? 0),
                'active_users' => (int) ($userTotals->active ?? 0),
                'files' => (int) (clone $fileData)->count(),
                'folders' => (int) (clone $folderData)->count(),
                'shared_items' => (int) (clone $activeData)->where('shared', 'public')->count(),
                'used_storage' => $usedStorage,
                'total_capacity' => (int) $usedStorageCapacity,
                'activities_30_days' => $activityRows->count(),
            ],
            'top_users' => $topUsers,
            'extensions' => $extensionBreakdown,
            'activity_trend' => $activityTrend,
            'generated_at' => Carbon::now()->toIso8601String(),
        ], 'Analisa data', 200);
    }

    public function activities(Request $request)
    {
        if ($denied = $this->authorizeAdmin()) {
            return $denied;
        }

        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'event' => 'nullable|string|max:100',
            'user_id' => 'nullable|integer|exists:users,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $query = Activity::query()->with('causer:id,fullname,username,email,photo');
        $search = trim($validated['search'] ?? '');

        $query->when($search !== '', function ($query) use ($search) {
            $query->where(function ($query) use ($search) {
                $query->where('description', 'like', "%{$search}%")
                    ->orWhereHasMorph('causer', [User::class], function ($userQuery) use ($search) {
                        $userQuery->where('fullname', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        });
        $query->when($validated['event'] ?? null, fn ($query, $event) =>
            $query->where('properties->event', $event)
        );
        $query->when($validated['user_id'] ?? null, fn ($query, $userId) => $query->where('causer_id', $userId));
        $query->when($validated['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date));
        $query->when($validated['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date));

        $activities = $query->latest()->paginate($validated['per_page'] ?? 20)->withQueryString();
        $items = $activities->getCollection()->map(function ($activity) {
            $properties = $activity->properties?->toArray() ?? [];
            return [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $properties['event'] ?? null,
                'ip_address' => $properties['ip'] ?? null,
                'agent' => $properties['agent'] ?? null,
                'created_at' => $activity->created_at?->toIso8601String(),
                'user' => $activity->causer ? [
                    'id' => $activity->causer->id,
                    'name' => $activity->causer->fullname,
                    'username' => $activity->causer->username,
                    'email' => $activity->causer->email,
                    'photo' => $activity->causer->photo ? asset($activity->causer->photo) : null,
                ] : null,
            ];
        });

        return $this->successResponse([
            'data' => $items,
            'current_page' => $activities->currentPage(),
            'last_page' => $activities->lastPage(),
            'per_page' => $activities->perPage(),
            'total' => $activities->total(),
        ], 'Activity users', 200);
    }
}
