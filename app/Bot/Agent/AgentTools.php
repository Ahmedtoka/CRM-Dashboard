<?php

namespace App\Bot\Agent;

use App\Bot\Catalog\ProductCards;
use App\Bot\CatalogSearch;
use App\Bot\Flow\Orders\OrderLookup;
use App\Bot\Flow\Orders\OrderStatusText;
use App\Bot\Flows\BranchFinder;
use App\Bot\Grounding\ShippingFeeAnswer;
use App\Models\BotFlow;
use App\Models\Conversation;
use App\Models\Product;

/**
 * The store agent's tools (owner, 2026-09-22): everything it may know about products, orders,
 * branches and shipping comes from here, read from the synced data — never from its own memory.
 * A tool returns the text the model reads; side effects (cards to show, a flow to start, a
 * handover) are noted on the AgentOutcome and carried out by AgentRunner after the reply.
 */
class AgentTools
{
    public const FLOWS = [
        'return_exchange' => 'return or exchange an item she received',
        'order_tracking' => 'follow up an order step by step',
        'complaint' => 'record a complaint',
        'cancel_edit' => 'cancel or change an order she placed',
        'branches' => 'find a branch by area',
    ];

    public function __construct(
        private readonly ProductCards $cards,
        private readonly CatalogSearch $catalog,
        private readonly OrderLookup $orders,
        private readonly OrderStatusText $statusText,
        private readonly BranchFinder $branches,
        private readonly ShippingFeeAnswer $shipping,
    ) {}

    /** @return list<array<string, mixed>> Anthropic tool definitions */
    public function definitions(bool $insideFlow): array
    {
        $tools = [
            [
                'name' => 'search_products',
                'description' => 'Search the store catalog (synced from Shopify). Returns matching active products with price, colours, sizes and stock. The customer is then SHOWN these products as picture cards right after your message, so never list them yourself. Call with an empty query to get the newest in-stock products. This is the ONLY source for what the store sells, prices and availability.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Product words in the language of the catalog titles, e.g. "فستان ستان" or "abaya". No colours, sizes or filler words.'],
                    'product_type' => ['type' => 'string', 'description' => 'Optional exact product type from a previous result.'],
                ], 'required' => ['query']],
            ],
            [
                'name' => 'get_order_status',
                'description' => "Look up the customer's order by order number, or by the mobile number or email used on the order. Returns the current status line(s). Ask her for one of them first if she gave none.",
                'input_schema' => ['type' => 'object', 'properties' => [
                    'order_number' => ['type' => 'string'],
                    'phone' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                ]],
            ],
            [
                'name' => 'find_branches',
                'description' => 'Find store branches by the area, district, city or branch name the customer mentioned. Returns addresses, hours, phones and map links.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'place' => ['type' => 'string', 'description' => 'The area or branch name as she wrote it.'],
                ], 'required' => ['place']],
            ],
            [
                'name' => 'get_shipping_fee',
                'description' => "The shipping fee for an Egyptian governorate, from the store's live shipping rates.",
                'input_schema' => ['type' => 'object', 'properties' => [
                    'governorate' => ['type' => 'string', 'description' => 'Governorate or city name as she wrote it; empty when she did not say.'],
                ], 'required' => ['governorate']],
            ],
            [
                'name' => 'handover_to_human',
                'description' => 'Pass the conversation to a human agent. Use when she is angry, when the request needs a decision or an action only the team can take (refund status, payment problem, placing an order by chat, wholesale…), or when you cannot answer from KNOWLEDGE and the tools. Write your short message to her in the same turn.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'reason' => ['type' => 'string', 'description' => 'One short English slug, e.g. payment_issue, angry_customer, unknown_question.'],
                    'summary' => ['type' => 'string', 'description' => 'One or two Arabic sentences for the team: what she wants and what she already gave.'],
                ], 'required' => ['reason', 'summary']],
            ],
        ];

        $flows = array_filter(self::FLOWS, fn (string $key) => BotFlow::active($key) !== null, ARRAY_FILTER_USE_KEY);

        if (! $insideFlow && $flows !== []) {
            $tools[] = [
                'name' => 'start_flow',
                'description' => 'Start a guided step-by-step flow that collects what the team needs and records the request. Use it as soon as she clearly wants one of these: '
                    .implode('; ', array_map(fn ($k, $d) => "{$k} = {$d}", array_keys($flows), $flows))
                    .'. The flow asks its own first question, so do not ask it yourself.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'flow' => ['type' => 'string', 'enum' => array_keys($flows)],
                ], 'required' => ['flow']],
            ];
        }

        return $tools;
    }

    public function run(string $name, array $input, Conversation $c, AgentOutcome $outcome): string
    {
        $outcome->toolsUsed[] = $name;

        $result = match ($name) {
            'search_products' => $this->searchProducts($input, $outcome),
            'get_order_status' => $this->orderStatus($input, $c),
            'find_branches' => $this->findBranches($input, $outcome),
            'get_shipping_fee' => $this->shippingFee($input),
            'handover_to_human' => $this->handover($input, $outcome),
            'start_flow' => $this->startFlow($input, $outcome),
            default => 'Unknown tool.',
        };

        $outcome->evidence[] = $result;

        return $result;
    }

    private function searchProducts(array $input, AgentOutcome $outcome): string
    {
        $query = trim((string) ($input['query'] ?? ''));
        $type = trim((string) ($input['product_type'] ?? ''));

        $products = $query !== '' ? $this->cards->search($query) : collect();

        if ($products->isEmpty() && ($query === '' || $type !== '')) {
            $products = $this->cards->featured(type: $type !== '' ? $type : null);
        }

        if ($products->isEmpty()) {
            $types = $this->cards->types();

            return 'NO PRODUCTS FOUND for "'.$query.'". The store has no matching product in its catalog: say so honestly, never claim it is available.'
                .($types !== [] ? ' Product types the store does have: '.implode(', ', $types).'.' : '');
        }

        $outcome->products = $products;

        return "Products (she will see them as picture cards):\n".$products->map(
            fn (Product $p) => '- '.$this->catalog->productLine($p).' | '.$this->cards->url($p).($p->product_type ? ' | type: '.$p->product_type : '')
        )->implode("\n");
    }

    private function orderStatus(array $input, Conversation $c): string
    {
        $found = $this->orders->find($c, [
            'order_ref' => $input['order_number'] ?? null,
            'phone' => $input['phone'] ?? null,
            'email' => $input['email'] ?? null,
        ]);

        return match ($found['status']) {
            'missing_details' => 'Ask her for the order number, or the mobile number or email used on the order.',
            'not_found' => 'NO ORDER FOUND with these details. Ask her to check them; after a second miss hand over to a human.',
            default => implode("\n", array_map(
                fn ($s) => $this->statusText->line($s).($this->statusText->needsAgent($s) ? ' (needs a human: hand over)' : ''),
                $found['snapshots'],
            )),
        };
    }

    private function findBranches(array $input, AgentOutcome $outcome): string
    {
        $place = trim((string) ($input['place'] ?? ''));
        $area = $place !== '' ? $this->branches->match($place) : null;
        $branches = $area !== null ? $this->branches->branchesOf($area) : ($place !== '' ? $this->branches->matchBranches($place) : collect());

        if ($branches->isEmpty()) {
            $areas = collect($this->branches->areas())->pluck('label')->filter()->implode('، ');

            return 'NO BRANCH FOUND for "'.$place.'".'.($areas !== '' ? ' Areas with branches: '.$areas.'. Ask her which is nearest.' : '');
        }

        $outcome->branchCards = $this->branches->cards($branches);

        return "Branches (she will see them as cards with map and call buttons, so do not repeat the addresses):\n"
            .$branches->map(fn ($b) => $this->branches->branchText($b))->implode("\n\n");
    }

    private function shippingFee(array $input): string
    {
        $fee = $this->shipping->for((string) ($input['governorate'] ?? ''));

        return match (true) {
            $fee['fact'] !== null => $fee['fact'],
            $fee['asks_governorate'] => 'Ask her which governorate the order ships to; never guess a fee.',
            default => 'No shipping rate is available: tell her the fee is shown at checkout on the website.',
        };
    }

    private function handover(array $input, AgentOutcome $outcome): string
    {
        $outcome->handover = ['reason' => mb_substr((string) ($input['reason'] ?? 'agent_handover'), 0, 60), 'summary' => (string) ($input['summary'] ?? '')];

        return 'Done: a human agent takes over after your message, and she is told so automatically. Do not promise a time.';
    }

    private function startFlow(array $input, AgentOutcome $outcome): string
    {
        $flow = (string) ($input['flow'] ?? '');

        if (! array_key_exists($flow, self::FLOWS) || BotFlow::active($flow) === null) {
            return 'That flow is not available.';
        }

        $outcome->flow = $flow;

        return 'Done: the flow starts right after your message and asks its own first question. Reply with at most one short sentence, or nothing.';
    }
}
