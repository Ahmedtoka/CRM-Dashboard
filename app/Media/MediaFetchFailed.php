<?php

namespace App\Media;

use RuntimeException;

/**
 * An inbound attachment could not be downloaded (network error, expired
 * platform media id, fake driver with no real url, ...). Caught by
 * DownloadInboundMedia's queue retry/failure handling.
 */
class MediaFetchFailed extends RuntimeException {}
