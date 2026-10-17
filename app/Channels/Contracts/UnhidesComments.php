<?php

namespace App\Channels\Contracts;

use App\Channels\Data\SendResult;
use App\Models\ChannelAccount;

/** An adapter that can show a hidden comment again (TikTok ad comments, E1; the fake adapter). */
interface UnhidesComments
{
    public function unhideComment(ChannelAccount $account, string $commentExternalId): SendResult;
}
