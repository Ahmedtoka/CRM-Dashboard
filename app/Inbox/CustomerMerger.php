<?php

namespace App\Inbox;

use App\Analytics\ActivityLogger;
use App\Enums\ActorType;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerIdentity;
use App\Models\Order;
use App\Models\User;
use App\Shopify\Customers\CustomerOrderFlags;
use App\Shopify\Customers\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves everything owned by `$from` into `$into`, then deletes `$from`. Used by the manual
 * merge endpoint (actor user) and automatic Shopify linking (actor system).
 */
final class CustomerMerger
{
    private const FILL_BLANK = ['phone', 'email', 'city', 'address', 'avatar_url', 'notes'];

    private const SHOPIFY_FIELDS = ['shopify_customer_id', 'tags', 'accepts_marketing', 'shopify_orders_count', 'shopify_total_spent', 'shopify_updated_at'];

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly CustomerOrderFlags $flags,
    ) {}

    public function merge(Customer $into, Customer $from, ?User $by): Customer
    {
        if ((int) $into->getKey() === (int) $from->getKey()) {
            throw new InvalidArgumentException('A customer cannot be merged into itself.');
        }

        DB::transaction(function () use ($into, $from) {
            CustomerIdentity::where('customer_id', $from->id)->update(['customer_id' => $into->id]);
            Conversation::where('customer_id', $from->id)->update(['customer_id' => $into->id]);
            Order::where('customer_id', $from->id)->update(['customer_id' => $into->id]);
            Comment::where('customer_id', $from->id)->update(['customer_id' => $into->id]);
            CustomerAddress::where('customer_id', $from->id)->update(['customer_id' => $into->id]);

            foreach (self::FILL_BLANK as $field) {
                if (blank($into->{$field}) && filled($from->{$field})) {
                    $into->{$field} = $from->{$field};
                }
            }

            // Adopt the Shopify link only when the survivor has none (the id is unique, so the
            // other row must be gone before the survivor is saved).
            $shopify = blank($into->shopify_customer_id) && filled($from->shopify_customer_id)
                ? $from->only(self::SHOPIFY_FIELDS)
                : [];

            $into->orders_count = (int) $into->orders_count + (int) $from->orders_count;
            $into->total_spent = round((float) $into->total_spent + (float) $from->total_spent, 2);

            if ($from->last_contact_at !== null && ($into->last_contact_at === null || $from->last_contact_at->gt($into->last_contact_at))) {
                $into->last_contact_at = $from->last_contact_at;
            }

            $from->delete();
            $into->forceFill($shopify);
            $into->normalized_phone = PhoneNormalizer::toE164($into->phone);
            $into->save();
        });

        $this->logger->log($by !== null ? ActorType::User : ActorType::System, $by, ActivityLogger::CUSTOMER_MERGED, $into, null, [
            'other_id' => $from->id,
            'other_name' => $from->name,
        ]);

        $this->flags->recompute($into);

        return $into;
    }
}
