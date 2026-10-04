<?php

namespace App\Ads\Captions;

use App\Bot\Flow\Concerns\CallsClaudeJson;
use App\Models\AdMaterial;
use App\Models\AdMaterialFile;
use App\Models\BotSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Three Egyptian-Arabic ad captions from a video's frames (Claude vision) plus the product data. Uses the same raw
 * Messages API call as the bot (structured outputs with a fallback); the frames go in as base64 image blocks.
 */
class CaptionGenerator implements GeneratesCaptions
{
    use CallsClaudeJson;
    use StoresCaptions;

    public const SYSTEM_PROMPT = <<<'TXT'
You are an Egyptian Arabic copywriter for Le Voile, a brand of modest women's fashion (abayas, dresses, hijab wear).
Write exactly three Facebook and Instagram ad captions for the video shown in the frames and the product data given.
Write in natural Egyptian Arabic, warm and clear, speaking to a woman. Never use emoji or emoticons.
The three captions use three different angles, in this order:
1. emotional: how she feels wearing it, the occasion, confidence.
2. offer: why order now, availability, delivery. Mention a price only if it is in the product data, and never invent a discount.
3. quality-fabric: the fabric, the cut, the finishing details you can see or that the product data states.
Each caption has: angle (emotional, offer or quality), headline (at most 40 characters), primary_text (at most 400 characters),
cta (one of SHOP_NOW, LEARN_MORE, ORDER_NOW, SEND_MESSAGE). Do not claim anything the frames and data do not support.
Answer with the JSON object only.
TXT;

    private ?int $framesUsed = null;

    public function __construct(private readonly FrameExtractor $frames) {}

    public function framesUsed(): ?int
    {
        return $this->framesUsed;
    }

    public function generate(AdMaterial $m, AdMaterialFile $f): array
    {
        $key = config('crm.anthropic.key');
        if (! filled($key)) {
            throw new CaptionException(__('ads.captions.no_key'));
        }

        $frames = $this->frames->frames($f, (int) config('crm.ads.captions.frames', 4));
        $this->framesUsed = count($frames);
        $content = [];
        foreach ($frames as $b64) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $b64]];
        }
        $content[] = ['type' => 'text', 'text' => $this->prompt($m, count($frames))];

        $model = $this->model();
        try {
            $result = $this->claudeJson(
                (string) $key, $model, (int) config('crm.ads.captions.timeout', 60), 1500, self::SYSTEM_PROMPT,
                [['role' => 'user', 'content' => $content]], $this->schema(),
            );
        } catch (RequestException|ConnectionException $e) {
            // Status and model only: never the headers (API key) or the body.
            Log::warning('ads captions: Anthropic call failed', ['status' => $e instanceof RequestException ? $e->response->status() : null, 'model' => $model]);
            throw new CaptionException(__('ads.captions.api_failed'), 0, $e);
        }

        $items = is_array($result['json']['captions'] ?? null) ? array_values(array_filter($result['json']['captions'], 'is_array')) : [];
        if (count($items) < 3) {
            throw new CaptionException(__('ads.captions.bad_answer'));
        }

        return $this->store($m, $f, array_slice($items, 0, 3), $model, $result['input_tokens'], $result['output_tokens']);
    }

    private function model(): string
    {
        $configured = config('crm.ads.captions.model');
        if (filled($configured)) {
            return (string) $configured;
        }
        $chosen = BotSetting::current()->ai_reply_model;

        return filled($chosen) ? (string) $chosen : (string) config('crm.anthropic.reply_model');
    }

    /** Product context as plain lines: title, price, compare-at, description without HTML, collections. */
    private function prompt(AdMaterial $m, int $frameCount): string
    {
        $m->loadMissing('product.variants', 'collections');
        $p = $m->product;
        $lines = ['Material: '.$m->title];

        if ($p !== null) {
            $lines[] = 'Product: '.$p->title;
            $price = $p->variants->pluck('price')->filter(fn ($v) => (float) $v > 0)->map(fn ($v) => (float) $v)->min();
            $compare = $p->variants->pluck('compare_at_price')->filter(fn ($v) => (float) $v > 0)->map(fn ($v) => (float) $v)->max();
            if ($price !== null) {
                $lines[] = 'Price: '.rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.').' EGP';
            }
            if ($compare !== null && $compare > (float) $price) {
                $lines[] = 'Compare-at price: '.rtrim(rtrim(number_format($compare, 2, '.', ''), '0'), '.').' EGP';
            }
            if (filled($p->product_type)) {
                $lines[] = 'Type: '.$p->product_type;
            }
        }
        $collections = $m->collections->pluck('name')->filter()->implode(', ');
        if ($collections !== '') {
            $lines[] = 'Collections: '.$collections;
        }
        if (filled($m->content_notes)) {
            $lines[] = 'Notes: '.mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $m->content_notes)) ?? ''), 0, 600);
        }
        $lines[] = $frameCount > 0
            ? "The {$frameCount} images above are frames from the video, in order."
            : 'No video frames are available: write from the product data only.';

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['captions' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'angle' => ['type' => 'string', 'enum' => self::ANGLES],
                    'headline' => ['type' => 'string'],
                    'primary_text' => ['type' => 'string'],
                    'cta' => ['type' => 'string', 'enum' => self::CTAS],
                ],
                'required' => ['angle', 'headline', 'primary_text', 'cta'],
                'additionalProperties' => false,
            ]]],
            'required' => ['captions'],
            'additionalProperties' => false,
        ];
    }
}
