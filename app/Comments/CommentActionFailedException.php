<?php

namespace App\Comments;

use RuntimeException;

/**
 * Thrown by CommentActions when a channel adapter call (reply/hide/private
 * reply) fails; carries the platform's error message. The comment's status
 * is left unchanged so the action can be retried.
 */
class CommentActionFailedException extends RuntimeException {}
