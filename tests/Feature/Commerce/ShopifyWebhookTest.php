<?php

use App\Enums\OrderStatus;
use App\Models\Order;
it('verifies hmac and marks order paid', function () {
    config(['crm.drivers.commerce'=>'live','crm.shopify.webhook_secret'=>'s3']);
    $order = Order::factory()->create(['shopify_order_id'=>'9001','status'=>OrderStatus::AwaitingPayment]);
    $body = json_encode(['id'=>9001,'financial_status'=>'paid','fulfillment_status'=>null,'cancelled_at'=>null,'name'=>'#1001']);
    $hmac = base64_encode(hash_hmac('sha256', $body, 's3', true));
    $this->call('POST', '/webhooks/shopify/orders-paid', [], [], [], ['HTTP_X-Shopify-Hmac-Sha256'=>$hmac,'CONTENT_TYPE'=>'application/json'], $body)->assertOk();
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    $this->call('POST', '/webhooks/shopify/orders-paid', [], [], [], ['HTTP_X-Shopify-Hmac-Sha256'=>'bad','CONTENT_TYPE'=>'application/json'], $body)->assertUnauthorized();
});
