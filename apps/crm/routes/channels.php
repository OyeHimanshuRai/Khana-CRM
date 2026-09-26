<?php

use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Who may listen to what
|--------------------------------------------------------------------------
|
| Every channel here is private, and every one is scoped to a branch. That
| is the same rule the rest of the system follows through CurrentShop, and it
| has to be stated again here because a WebSocket subscription does not go
| through the route middleware that normally enforces it.
|
| The failure mode if this were wrong is the worst kind: one restaurant's
| tickets, order values and guest names arriving live on another's screen,
| silently, with nothing in a log to show it happened.
|
| So the check is deliberately the strictest available - `canAccess`, the
| same predicate the model scope uses - rather than "is this person signed
| in".
*/

/**
 * Everything happening on one branch's floor and pass.
 *
 * One channel rather than three, because the three screens that listen -
 * the kitchen display, the live-orders board and the floor plan - all care
 * about the same underlying events and a subscription each would be three
 * sockets doing one socket's work.
 */
Broadcast::channel('shop.{shopId}', function (User $user, int $shopId) {
    /*
     | Not `$user->shops->contains($shopId)`. CurrentShop::canAccess is what
     | the model scope asks, and the two must never be able to disagree -
     | a channel that allowed a shop the scope would refuse is a leak, and a
     | channel that refused one the scope allows is a screen that mysteriously
     | never updates.
     */
    return CurrentShop::canAccess($shopId);
});

/*
| There is deliberately no guest channel.
|
| The obvious next one is "tell the diner their food is on its way without
| them refreshing", and it is tempting because the session token would serve
| as its own key. It is left out because the guest journey has no
| authenticated user, so a private channel for it would mean opening the
| broadcasting auth route to unauthenticated callers - and that route then
| guards every other channel on this page.
|
| The guest's order screen polls, which for somebody glancing at a phone
| between courses is indistinguishable from instant. The kitchen wall screen
| is where a poll interval is actually felt, and that is what is wired up.
*/
