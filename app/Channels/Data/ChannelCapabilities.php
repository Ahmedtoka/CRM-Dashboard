<?php

namespace App\Channels\Data;

final readonly class ChannelCapabilities
{
    public function __construct(
        public bool $privateReply,
        public bool $hideComment,
        public int $windowHours,
        public int $humanAgentHours,
        public bool $templatesOutsideWindow,
    ) {}
}
