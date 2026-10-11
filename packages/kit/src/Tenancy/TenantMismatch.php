<?php

namespace Khana\Kit\Tenancy;

use RuntimeException;

/**
 * An event, or a copy of one, that puts an outlet under a business it does
 * not belong to. Never applied: it is either a bug or an attempt to write
 * into somebody else's books.
 */
final class TenantMismatch extends RuntimeException {}
