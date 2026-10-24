<?php

namespace App\Http\Controllers\Web\Settings;

use App\Confirmations\EgyptPhone;
use App\Confirmations\OrderConfirmations;
use App\Confirmations\Readiness\WhatsAppReadiness;
use App\Confirmations\WhatsApp\WhatsAppConfirmationSender;
use App\Enums\OrderSource;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The WhatsApp order-confirmation readiness card (round 2026-10-24, Settings → القنوات and → تأكيد الأوردرات):
 * GET the checklist; POST «ابعتي تيست لرقمي» sends the confirm template of the latest store order to a number the
 * admin types — never to a customer's number — through the sender's rowless test path (no order_confirmations row).
 */
class WhatsAppReadinessController extends Controller
{
    public function show(WhatsAppReadiness $readiness): JsonResponse
    {
        return response()->json(['data' => $readiness->check()]);
    }

    public function test(Request $request, WhatsAppConfirmationSender $sender, OrderConfirmations $confirmations): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $phone = EgyptPhone::toE164($data['phone']);

        if ($phone === null) {
            return $this->refuse('invalid_phone');
        }

        $waId = ltrim($phone, '+');

        if (Customer::query()->where('normalized_phone', $phone)->exists()
            || CustomerIdentity::query()->where('platform', Platform::WhatsApp)->where('external_id', $waId)->exists()) {
            return $this->refuse('customer_number');
        }

        if (! OrderConfirmations::accountUsable($confirmations->whatsAppAccount())) {
            return $this->refuse('no_whatsapp_account');
        }

        $order = Order::query()->where('source', OrderSource::Store)->whereNotNull('shopify_order_id')->latest('id')->first()
            ?? Order::query()->latest('id')->first();

        if ($order === null) {
            return $this->refuse('no_order');
        }

        $error = $sender->sendTest($order, $phone);

        Log::info('order_confirmation.readiness_test', ['user' => $request->user()->id, 'order' => $order->id, 'ok' => $error === null, 'error' => $error]);

        if ($error !== null) {
            return response()->json(['ok' => false, 'error' => $error, 'order' => $order->shopify_order_name ?? $order->order_number], 422);
        }

        return response()->json(['ok' => true, 'order' => $order->shopify_order_name ?? $order->order_number, 'phone' => $phone]);
    }

    private function refuse(string $code): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $code], 422);
    }
}
