<?php

namespace App\Ads\Captions;

use App\Models\AdMaterial;
use App\Models\AdMaterialCaption;
use App\Models\AdMaterialFile;

interface GeneratesCaptions
{
    public const ANGLES = ['emotional', 'offer', 'quality'];

    public const CTAS = ['SHOP_NOW', 'LEARN_MORE', 'ORDER_NOW', 'SEND_MESSAGE'];

    public const MAX_HEADLINE = 40;

    public const MAX_TEXT = 400;

    /**
     * Writes three captions for the file (replacing the previous ones) and returns them in angle order.
     *
     * @return list<AdMaterialCaption>
     *
     * @throws CaptionException
     */
    public function generate(AdMaterial $m, AdMaterialFile $f): array;

    /** Frames the last generate() call sent to the model (null when the generator does not use frames). */
    public function framesUsed(): ?int;
}
