<?php

namespace App\Legal\Http;

use App\Http\Controllers\Controller;
use App\Legal\Jobs\DeleteMetaUserData;
use App\Legal\SignedRequest;
use App\Models\DataDeletionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Meta Data Deletion Callback: Meta POSTs a `signed_request` when a person removes the
 * app from Facebook (Settings → Apps and Websites) and asks for their data to be deleted.
 * We record the request, queue the deletion and answer with the status URL and code
 * Meta shows to the person.
 */
class DataDeletionCallbackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = SignedRequest::parse($request->input('signed_request'), config('crm.meta.app_secret'));
        $userId = $payload['user_id'] ?? null;

        if ($payload === null || ! is_scalar($userId) || (string) $userId === '') {
            return response()->json(['error' => 'invalid signed_request'], 400);
        }

        $deletion = DataDeletionRequest::create([
            'confirmation_code' => DataDeletionRequest::newConfirmationCode(),
            'platform' => 'facebook',
            'external_user_id' => (string) $userId,
            'status' => DataDeletionRequest::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        DeleteMetaUserData::dispatch($deletion->id);

        return response()->json([
            'url' => rtrim((string) config('app.url'), '/').'/data-deletion?code='.$deletion->confirmation_code,
            'confirmation_code' => $deletion->confirmation_code,
        ]);
    }
}
