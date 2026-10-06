<?php

namespace App\Maintenance;

use RuntimeException;

/** The database backup could not be taken or verified: nothing may be wiped. */
final class BackupFailed extends RuntimeException {}
