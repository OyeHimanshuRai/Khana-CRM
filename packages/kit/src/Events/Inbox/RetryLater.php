<?php

namespace Khana\Kit\Events\Inbox;

use RuntimeException;

/**
 * Not wrong, just early: the event stays pending, its stream stops for this
 * run, and the next run tries again.
 */
final class RetryLater extends RuntimeException {}
