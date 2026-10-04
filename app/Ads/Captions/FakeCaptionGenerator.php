<?php

namespace App\Ads\Captions;

use App\Models\AdMaterial;
use App\Models\AdMaterialFile;

/** Deterministic offline captions (CRM_AI_DRIVER other than claude): local, CI and demo. */
class FakeCaptionGenerator implements GeneratesCaptions
{
    use StoresCaptions;

    public function generate(AdMaterial $m, AdMaterialFile $f): array
    {
        $title = trim((string) ($m->product?->title ?? $m->title));

        return $this->store($m, $f, [
            ['angle' => 'emotional', 'headline' => "احساس مختلف مع {$title}", 'primary_text' => "اختاري اللي يخليكي واثقة في كل خروجة. {$title} بتفرق معاكي من اول يوم.", 'cta' => 'SHOP_NOW'],
            ['angle' => 'offer', 'headline' => "{$title} متاحة دلوقتي", 'primary_text' => "الكمية محدودة. اطلبي {$title} دلوقتي والتوصيل لحد باب البيت.", 'cta' => 'ORDER_NOW'],
            ['angle' => 'quality', 'headline' => 'خامة ناعمة وتفصيل متقن', 'primary_text' => "خامة مريحة وتفاصيل شغل ايد في {$title}. تشوفيها بنفسك وتحكمي.", 'cta' => 'LEARN_MORE'],
        ], 'fake', 0, 0);
    }

    public function framesUsed(): ?int
    {
        return null;
    }
}
