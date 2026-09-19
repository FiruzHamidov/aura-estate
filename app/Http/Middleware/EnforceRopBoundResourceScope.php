<?php

namespace App\Http\Middleware;

use App\Models\{Booking, Client, ClientNeed, CrmTask, DailyReport, Deal, Lead, Property, PropertyDuplicateCandidate, PropertyModerationCase, PropertyPromotion};
use App\Support\RopGroupAccess;
use Closure;
use Illuminate\Http\Request;

/** A direct-ID guard before validation/replay; SQL scopes and locked write policies still apply. */
final class EnforceRopBoundResourceScope
{
    public function handle(Request $request, Closure $next)
    {
        $actor = $request->user() ?? $request->user('sanctum');
        $access = app(RopGroupAccess::class);
        if (! $access->applies($actor)) return $next($request);

        $action = $request->route()?->getActionName();
        // These explicitly public endpoints retain their existing public projection.
        if (in_array($action, ['App\\Http\\Controllers\\PropertyController@trackView', 'App\\Http\\Controllers\\PropertyController@similar', 'App\\Http\\Controllers\\ReelController@propertyIndex'], true)) return $next($request);
        foreach ($request->route()?->parameters() ?? [] as $record) {
            if ($record instanceof Property || $record instanceof Client || $record instanceof Lead
                || $record instanceof Deal || $record instanceof Booking || $record instanceof CrmTask || $record instanceof DailyReport) {
                $access->ensureVisible($actor, $record);
            } elseif ($record instanceof ClientNeed) {
                $access->ensureVisible($actor, Client::findOrFail($record->client_id));
            } elseif ($record instanceof PropertyModerationCase || $record instanceof PropertyPromotion) {
                $access->ensureVisible($actor, Property::findOrFail($record->property_id));
            } elseif ($record instanceof PropertyDuplicateCandidate) {
                $access->ensureVisible($actor, Property::findOrFail($record->moderationCase()->value('property_id')));
                $access->ensureVisible($actor, Property::findOrFail($record->candidate_property_id));
            }
        }

        return $next($request);
    }
}
