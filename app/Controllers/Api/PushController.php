<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\BaseController;
use App\Core\Queue;
use App\Core\Request;
use App\Core\Response;
use App\Jobs\SendWebPushJob;

/**
 * Web Push (VAPID) subscribe/send sample. The framework has no bundled push
 * service (see Jobs\SendWebPushJob) — this controller shows the shape of
 * the two endpoints an app typically exposes: accepting a browser's
 * PushSubscription and triggering a delivery through the queue. A real app
 * persists the subscription (endpoint + keys) against the user; wire that
 * storage in alongside your own PushSubscriptionModel.
 */
final class PushController extends BaseController
{
    public function subscribe(Request $request): never
    {
        $data = $this->validate($request->all(), [
            'endpoint' => 'required',
            'keys'     => 'required|array',
        ]);
        // App-specific: persist $data against the authenticated user here.
        Response::created(['subscribed' => true, 'endpoint' => $data['endpoint']]);
    }

    public function unsubscribe(Request $request): never
    {
        $data = $this->validate($request->all(), ['endpoint' => 'required']);
        // App-specific: remove the stored subscription matching $data['endpoint'].
        Response::ok(['unsubscribed' => true]);
    }

    /** Demo/test endpoint: queue a push to the current user via SendWebPushJob. */
    public function test(Request $request): never
    {
        $userId = $this->userId();
        if ($userId === null) {
            Response::unauthorized();
        }
        Queue::push(SendWebPushJob::class, [
            'user_id' => $userId,
            'data'    => ['title' => 'Test notification', 'body' => 'Push delivery is wired up.'],
        ]);
        Response::ok(['queued' => true]);
    }
}
