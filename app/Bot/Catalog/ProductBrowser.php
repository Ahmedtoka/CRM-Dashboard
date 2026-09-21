<?php

namespace App\Bot\Catalog;

use App\Bot\Flows\FlowPrompter;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\Conversation;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Browsing the catalog with pictures (owner request 2026-09-22). Button payloads:
 *
 *   products:featured        — the newest in-stock products as a carousel, a button per product type
 *   products:type:<type>     — one product type
 *   product:<id>             — «التفاصيل والمقاسات» of one card
 *
 * Every sentence is a `script.products_*` knowledge entry, so the owner rewords it from the
 * dashboard. Prices and stock are read from the synced Shopify catalog only.
 */
class ProductBrowser
{
    public const TYPE_PAYLOAD = 'products:type:';

    public function __construct(
        private readonly ProductCards $cards,
        private readonly FlowPrompter $prompter,
        private readonly OutboundService $outbound,
    ) {}

    /** Handles `products:<rest>`; false when there is nothing to show (the caller falls back to its script). */
    public function browse(Conversation $c, string $rest): bool
    {
        $type = str_starts_with($rest, 'type:') ? trim(substr($rest, 5)) : null;
        $products = $this->cards->featured(type: $type !== '' ? $type : null);

        if ($products->isEmpty()) {
            return false;
        }

        $intro = $type
            ? str_replace('{type}', $type, $this->text('products_type_intro', 'موديلات {type} المتاحة 👇'))
            : $this->text('products_intro', 'دي أحدث الموديلات المتاحة عندنا 🌸 اضغطي «التفاصيل والمقاسات» على أي موديل يعجبك');

        $this->show($c, $intro, $products, $type === null ? $this->typeButtons() : []);

        return true;
    }

    /** Handles `product:<id>`. */
    public function detail(Conversation $c, string $id): bool
    {
        $product = ctype_digit($id) ? Product::query()->with('variants')->find((int) $id) : null;

        if ($product === null) {
            return false;
        }

        $this->send($c, $this->cards->detail($product), [FlowPrompter::MAIN_MENU_BUTTON]);

        return true;
    }

    /**
     * An intro line, then the carousel (its body holds the plain list for channels without cards).
     *
     * @param  Collection<int, Product>  $products
     * @param  list<array{title:string, payload:string}>  $buttons
     */
    public function show(Conversation $c, string $intro, Collection $products, array $buttons = [], int $delayMs = 0, bool $menuButton = true): void
    {
        if (trim($intro) !== '') {
            $this->send($c, $intro, [], null, $delayMs);
        }

        $this->send($c, $this->cards->fallbackText($products), $menuButton ? [...$buttons, FlowPrompter::MAIN_MENU_BUTTON] : $buttons, $this->cards->cards($products), $delayMs > 0 ? $delayMs + 600 : 0);
    }

    /** @return list<array{title:string, payload:string}> */
    private function typeButtons(): array
    {
        return array_map(fn (string $t) => ['title' => mb_substr($t, 0, 20), 'payload' => self::TYPE_PAYLOAD.$t], $this->cards->types());
    }

    private function text(string $key, string $default): string
    {
        return $this->prompter->script($key) ?? $default;
    }

    private function send(Conversation $c, string $text, array $buttons = [], ?array $cards = null, int $delayMs = 0): void
    {
        try {
            $this->outbound->sendBot($c, $text, $delayMs, true, $buttons, $cards);
        } catch (WindowClosedException) {
            Log::info('products.window_closed', ['conversation_id' => $c->id]);
        }
    }
}
