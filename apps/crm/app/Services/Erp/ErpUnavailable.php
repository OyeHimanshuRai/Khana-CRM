<?php

namespace App\Services\Erp;

use RuntimeException;

/**
 * ERP could not be read just now.
 *
 * One exception for every way that can happen - this install has no address
 * for ERP, Accounts would not issue a token, the connection failed, ERP took
 * too long, or it answered with an error - because every screen that catches
 * it does the same thing: says "ERP is not reachable right now" in the one
 * panel that needed it, and draws the rest of the page.
 *
 * The message says which of those it was. It is for the log, not for the
 * person at the till, who can do nothing about any of them.
 */
final class ErpUnavailable extends RuntimeException {}
