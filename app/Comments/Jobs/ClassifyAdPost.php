<?php

namespace App\Comments\Jobs;

use App\Channels\Adapters\MetaGraphClient;
use App\Channels\Ads\AdLookup;
use App\Enums\Platform;
use App\Events\CommentUpdated;
use App\Models\Comment;
use App\Models\Post;
use App\Support\SafeBroadcast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Is this post an ad, and which one (owner, 2026-09-25)? The Facebook `feed` webhook does not
 * say, so a new post is looked at once: an unpublished ("dark") page post is an ad by
 * definition, and the Marketing API tells which ad, ad set and campaign use the post (AdLookup)
 * — for Instagram too, where the webhook named the ad but not its campaign. The post's
 * comments are re-broadcast so the «من إعلان» chip appears without a reload.
 */
class ClassifyAdPost implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [60];

    public int $timeout = 60;

    public function __construct(public readonly int $postId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(MetaGraphClient $graph, AdLookup $ads): void
    {
        $post = Post::with('channelAccount')->find($this->postId);

        if ($post === null || $post->channelAccount === null || $post->ad_checked_at !== null) {
            return;
        }

        $changes = ['ad_checked_at' => now()];

        if ($post->platform === Platform::Facebook) {
            try {
                $response = $graph->get($post->channelAccount, $post->external_id, ['fields' => 'is_published,permalink_url,message']);

                if ($response->successful()) {
                    if ($response->json('is_published') === false) {
                        $changes['is_ad'] = true;
                    }

                    if ($post->permalink === null && is_string($response->json('permalink_url'))) {
                        $changes['permalink'] = $response->json('permalink_url');
                    }

                    if ($post->caption === null && is_string($response->json('message'))) {
                        $changes['caption'] = mb_substr($response->json('message'), 0, 2000);
                    }
                } else {
                    Log::info('classify_ad_post.post_refused', ['post_id' => $post->id, 'status' => $response->status(), 'error' => $response->json('error.message')]);
                }
            } catch (Throwable $e) {
                Log::info('classify_ad_post.post_failed', ['post_id' => $post->id, 'error' => $e->getMessage()]);
            }
        }

        $ad = $ads->adForPost($post->channelAccount, $post->external_id);

        if ($ad !== null) {
            $changes += [
                'is_ad' => true,
                'ad_id' => $post->ad_id ?? $ad['ad_id'],
                'ad_name' => $ad['ad_name'],
                'ad_adset_name' => $ad['adset_name'],
                'ad_campaign_name' => $ad['campaign_name'],
            ];
        }

        $wasAd = (bool) $post->is_ad;
        $post->forceFill($changes)->save();

        if (! $wasAd && ($changes['is_ad'] ?? false)) {
            Comment::query()->where('post_id', $post->id)->latest('id')->limit(20)->get()->each(function (Comment $c) use ($post) {
                $c->setRelation('post', $post);
                SafeBroadcast::send(new CommentUpdated($c));
            });
        }
    }
}
