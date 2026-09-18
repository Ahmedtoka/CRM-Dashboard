<?php

namespace App\Media;

use DomainException;

/**
 * An upload or an outbound send was rejected by MediaPolicy — unsupported
 * type, too large, or not sendable on the target platform. The message is
 * already Arabic and safe to show to the user as-is (see MediaPolicy
 * constants). Rendered as a 422 by bootstrap/app.php.
 */
class MediaRejected extends DomainException {}
