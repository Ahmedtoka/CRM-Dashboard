<?php

namespace App\Ads\Platforms;

use App\Ads\Platforms\Data\AdDraft;
use App\Ads\Platforms\Data\CampaignNode;
use App\Ads\Platforms\Data\Identity;
use App\Ads\Platforms\Data\MediaRef;
use App\Ads\Platforms\Data\ObjectState;
use App\Models\AdAccount;
use App\Models\AdMaterialFile;

/** Write side of a platform: everything the publish and stop/run flows need. */
interface AdPlatformWriter
{
    /** @return list<CampaignNode> active + paused campaigns with their ad sets */
    public function liveCampaigns(AdAccount $a): array;

    /** @return list<Identity> */
    public function identities(AdAccount $a): array;

    public function uploadMedia(AdAccount $a, AdMaterialFile $file): MediaRef;

    public function mediaReady(AdAccount $a, MediaRef $ref): bool;

    /** @return string the platform ad id (created paused) */
    public function createPausedAd(AdAccount $a, AdDraft $draft): string;

    /**
     * @param  'campaign'|'adset'|'ad'  $level
     * @param  'active'|'paused'  $status
     */
    public function setStatus(AdAccount $a, string $level, string $externalId, string $status): void;

    /**
     * Live status and budgets of one object and its parents (one read).
     *
     * @param  'campaign'|'adset'|'ad'  $level
     *
     * @throws ReadUnsupported when the platform has no read yet
     * @throws PlatformUnreachable on a transport failure
     */
    public function readObject(AdAccount $a, string $level, string $externalId): ObjectState;
}
