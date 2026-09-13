<?php

namespace App\Shopify\Customers;

use App\Inbox\CustomerMerger;
use App\Models\Customer;
use App\Models\CustomerMergeSuggestion;

/**
 * Spec §3.4: links a customer to other CRM customers sharing its Egyptian mobile number.
 * Only an unambiguous phone match merges; everything else (several candidates, a candidate
 * linked to another Shopify customer, email matches) becomes an open merge suggestion.
 */
final class CustomerLinker
{
    private const CANDIDATE_LIMIT = 50;

    public function __construct(private readonly CustomerMerger $merger) {}

    /**
     * Returns the surviving customer when a merge happened, otherwise null.
     */
    public function link(Customer $customer): ?Customer
    {
        $phone = $customer->normalized_phone;

        if (PhoneNormalizer::isEgyptianMobile($phone)) {
            $candidates = Customer::query()
                ->where('normalized_phone', $phone)
                ->whereKeyNot($customer->getKey())
                ->orderBy('id')
                ->limit(self::CANDIDATE_LIMIT)
                ->get();

            if ($candidates->count() === 1) {
                $candidate = $candidates->first();
                $linked = filled($customer->shopify_customer_id);
                $candidateLinked = filled($candidate->shopify_customer_id);

                // Exactly one side belongs to Shopify: the social record folds into it.
                if ($linked !== $candidateLinked) {
                    [$into, $from] = $linked ? [$customer, $candidate] : [$candidate, $customer];

                    return $this->merger->merge($into, $from, null);
                }
            }

            foreach ($candidates as $candidate) {
                $this->suggest($customer, $candidate, 'phone');
            }
        }

        if (filled($customer->email)) {
            Customer::query()
                ->where('email', $customer->email)
                ->whereKeyNot($customer->getKey())
                ->orderBy('id')
                ->limit(self::CANDIDATE_LIMIT)
                ->get()
                ->each(fn (Customer $candidate) => $this->suggest($customer, $candidate, 'email'));
        }

        return null;
    }

    /**
     * One suggestion per pair, in either direction and whatever its status (a dismissed pair
     * is not re-suggested).
     */
    private function suggest(Customer $customer, Customer $candidate, string $reason): void
    {
        $exists = CustomerMergeSuggestion::query()
            ->where(fn ($q) => $q->where('customer_id', $customer->id)->where('candidate_id', $candidate->id))
            ->orWhere(fn ($q) => $q->where('customer_id', $candidate->id)->where('candidate_id', $customer->id))
            ->exists();

        if (! $exists) {
            CustomerMergeSuggestion::create([
                'customer_id' => $customer->id,
                'candidate_id' => $candidate->id,
                'reason' => $reason,
                'status' => 'open',
            ]);
        }
    }
}
