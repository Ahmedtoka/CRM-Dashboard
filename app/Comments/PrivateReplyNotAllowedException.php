<?php

namespace App\Comments;

use RuntimeException;

/**
 * Thrown by CommentActions::privateReply() when a private reply cannot be
 * sent: already sent once, the 7-day window (config crm.private_reply_days)
 * has passed, or the platform adapter reports capabilities()->privateReply
 * === false (spec §5.5 step 4, e.g. TikTok).
 */
class PrivateReplyNotAllowedException extends RuntimeException {}
