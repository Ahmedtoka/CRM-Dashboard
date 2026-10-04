<?php

namespace App\Ads\Captions;

use App\Models\AdMaterial;
use App\Models\AdMaterialCaption;
use App\Models\AdMaterialFile;
use App\Support\Emoji;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared by the real and fake generators: clean, clamp and replace the stored captions. */
trait StoresCaptions
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<AdMaterialCaption>
     */
    private function store(AdMaterial $m, AdMaterialFile $f, array $items, ?string $model, int $in, int $out): array
    {
        return DB::transaction(function () use ($m, $f, $items, $model, $in, $out) {
            AdMaterialCaption::query()->where('ad_material_id', $m->id)->where('ad_material_file_id', $f->id)->delete();

            $rows = [];
            foreach (array_values($items) as $i => $item) {
                $angle = in_array($item['angle'] ?? null, GeneratesCaptions::ANGLES, true) ? $item['angle'] : GeneratesCaptions::ANGLES[$i];
                $cta = in_array($item['cta'] ?? null, GeneratesCaptions::CTAS, true) ? $item['cta'] : 'SHOP_NOW';
                $rows[] = AdMaterialCaption::query()->create([
                    'ad_material_id' => $m->id,
                    'ad_material_file_id' => $f->id,
                    'position' => $i + 1,
                    'angle' => $angle,
                    'headline' => Str::limit(self::clean($item['headline'] ?? ''), GeneratesCaptions::MAX_HEADLINE, ''),
                    'primary_text' => Str::limit(self::clean($item['primary_text'] ?? ''), GeneratesCaptions::MAX_TEXT, ''),
                    'cta' => $cta,
                    'model' => $model,
                    // The one call's usage is stored on every row (it is per call, not per caption).
                    'input_tokens' => $in,
                    'output_tokens' => $out,
                ]);
            }

            return $rows;
        });
    }

    private static function clean(mixed $v): string
    {
        return trim(Emoji::strip((string) $v));
    }
}
