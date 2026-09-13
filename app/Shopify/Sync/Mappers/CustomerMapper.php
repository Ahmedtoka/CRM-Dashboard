<?php

namespace App\Shopify\Sync\Mappers;

use App\Events\CustomerUpdated;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Shopify\Customers\CustomerLinker;
use App\Shopify\Customers\PhoneNormalizer;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CustomerMapper
{
    public function __construct(private readonly CustomerLinker $linker) {}

    /**
     * Upserts the customer and its addresses, then links it to matching CRM customers.
     * Payloads embedded in orders lack counts/addresses; absent keys never reset stored values.
     */
    public function upsert(array $customer): MapResult
    {
        $c = Payload::isGraphql($customer) ? $this->fromGraphql($customer) : $customer;
        $shopifyId = Payload::id($c['id'] ?? null) ?? throw new InvalidArgumentException('Shopify customer payload has no id.');

        [$result, $model] = DB::transaction(function () use ($c, $shopifyId) {
            $model = Customer::where('shopify_customer_id', $shopifyId)->lockForUpdate()->first();

            if ($model !== null && StaleGuard::isStale($model->shopify_updated_at, $c['updated_at'] ?? null)) {
                return [MapResult::Skipped, $model];
            }

            $created = $model === null;
            $model ??= new Customer(['shopify_customer_id' => $shopifyId]);

            $addresses = array_key_exists('addresses', $c) ? Payload::list($c['addresses']) : null;
            $default = $c['default_address'] ?? collect($addresses ?? [])->firstWhere('default', true);
            $fullName = trim(((string) ($c['first_name'] ?? '')).' '.((string) ($c['last_name'] ?? '')));

            $model->fill([
                'name' => $fullName !== '' ? $fullName : (Payload::string($default['name'] ?? null) ?? $model->name ?? Payload::string($c['email'] ?? null)),
                'phone' => Payload::string($c['phone'] ?? null) ?? $model->phone ?? Payload::string($default['phone'] ?? null),
                'email' => Payload::string($c['email'] ?? null) ?? $model->email,
                'city' => Payload::string($default['city'] ?? null) ?? Payload::string($default['province'] ?? null) ?? $model->city,
                'address' => $default !== null ? $this->addressLine($default) ?? $model->address : $model->address,
                'shopify_updated_at' => Payload::time($c['updated_at'] ?? null) ?? $model->shopify_updated_at,
            ]);

            if (array_key_exists('tags', $c)) {
                $model->tags = Payload::tags($c['tags']);
            }
            if (array_key_exists('orders_count', $c)) {
                $model->shopify_orders_count = (int) $c['orders_count'];
            }
            if (array_key_exists('total_spent', $c)) {
                $model->shopify_total_spent = Payload::money($c['total_spent']);
            }
            if (array_key_exists('email_marketing_consent', $c) || array_key_exists('accepts_marketing', $c)) {
                $model->accepts_marketing = isset($c['email_marketing_consent']['state'])
                    ? strtolower((string) $c['email_marketing_consent']['state']) === 'subscribed'
                    : (bool) ($c['accepts_marketing'] ?? false);
            }

            // Set explicitly: model events may be muted (bulk import, Event::fake).
            $model->normalized_phone = PhoneNormalizer::toE164($model->phone);
            $model->save();

            if ($addresses !== null) {
                $this->syncAddresses($model, $addresses, Payload::id($default['id'] ?? null));
            }

            return [$created ? MapResult::Created : MapResult::Updated, $model];
        });

        if ($result !== MapResult::Skipped) {
            $survivor = $this->linker->link($model) ?? $model;
            SafeBroadcast::send(new CustomerUpdated($survivor));
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     */
    private function syncAddresses(Customer $customer, array $addresses, ?string $defaultId): void
    {
        $kept = [];

        foreach ($addresses as $a) {
            $addressId = Payload::id($a['id'] ?? null);

            if ($addressId === null) {
                continue;
            }

            $name = Payload::string($a['name'] ?? null)
                ?? (trim(((string) ($a['first_name'] ?? '')).' '.((string) ($a['last_name'] ?? ''))) ?: null);

            CustomerAddress::updateOrCreate(
                ['customer_id' => $customer->id, 'shopify_address_id' => $addressId],
                [
                    'name' => $name,
                    'phone' => Payload::string($a['phone'] ?? null),
                    'address1' => Payload::string($a['address1'] ?? null),
                    'address2' => Payload::string($a['address2'] ?? null),
                    'city' => Payload::string($a['city'] ?? null),
                    'province' => Payload::string($a['province'] ?? null),
                    'province_code' => Payload::string($a['province_code'] ?? null),
                    'zip' => Payload::string($a['zip'] ?? null),
                    'country_code' => Payload::string($a['country_code'] ?? null),
                    'is_default' => (bool) ($a['default'] ?? false) || ($defaultId !== null && $defaultId === $addressId),
                ],
            );
            $kept[] = $addressId;
        }

        CustomerAddress::where('customer_id', $customer->id)
            ->whereNotNull('shopify_address_id')
            ->whereNotIn('shopify_address_id', $kept)
            ->delete();
    }

    private function addressLine(array $address): ?string
    {
        $line = implode('، ', array_filter([
            Payload::string($address['address1'] ?? null),
            Payload::string($address['address2'] ?? null),
        ]));

        return $line !== '' ? $line : null;
    }

    private function fromGraphql(array $node): array
    {
        $address = fn (array $a) => [
            'id' => $a['id'] ?? null,
            'name' => $a['name'] ?? null,
            'first_name' => $a['firstName'] ?? null,
            'last_name' => $a['lastName'] ?? null,
            'phone' => $a['phone'] ?? null,
            'address1' => $a['address1'] ?? null,
            'address2' => $a['address2'] ?? null,
            'city' => $a['city'] ?? null,
            'province' => $a['province'] ?? null,
            'province_code' => $a['provinceCode'] ?? null,
            'zip' => $a['zip'] ?? null,
            'country_code' => $a['countryCodeV2'] ?? $a['countryCode'] ?? null,
        ];

        $defaultId = Payload::id($node['defaultAddress']['id'] ?? null);

        $c = [
            'id' => $node['id'],
            'first_name' => $node['firstName'] ?? null,
            'last_name' => $node['lastName'] ?? null,
            'email' => $node['email'] ?? $node['defaultEmailAddress']['emailAddress'] ?? null,
            'phone' => $node['phone'] ?? $node['defaultPhoneNumber']['phoneNumber'] ?? null,
            'updated_at' => $node['updatedAt'] ?? null,
            'default_address' => isset($node['defaultAddress']) ? $address($node['defaultAddress']) : null,
        ];

        if (array_key_exists('tags', $node)) {
            $c['tags'] = $node['tags'];
        }
        if (array_key_exists('numberOfOrders', $node)) {
            $c['orders_count'] = (int) $node['numberOfOrders'];
        }
        if (array_key_exists('amountSpent', $node)) {
            $c['total_spent'] = $node['amountSpent'];
        }
        if (isset($node['emailMarketingConsent']['marketingState'])) {
            $c['email_marketing_consent'] = ['state' => $node['emailMarketingConsent']['marketingState']];
        }
        if (array_key_exists('addresses', $node)) {
            $c['addresses'] = array_map(function (array $a) use ($address, $defaultId) {
                $mapped = $address($a);
                $mapped['default'] = $defaultId !== null && Payload::id($a['id'] ?? null) === $defaultId;

                return $mapped;
            }, Payload::list($node['addresses']));
        }

        return $c;
    }
}
