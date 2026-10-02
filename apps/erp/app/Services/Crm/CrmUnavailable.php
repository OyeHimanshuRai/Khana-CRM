<?php

namespace App\Services\Crm;

use RuntimeException;

/**
 * ERP asked CRM something and did not get an answer it could use: no address
 * for CRM is set up, Accounts would not issue the token, CRM was down or slow,
 * or what came back was an error or not what the contract promises.
 *
 * One exception for all of them, because whoever asked does the same thing
 * in each case - carry on without, and ask again later. The message is a
 * plain sentence saying which it was.
 */
final class CrmUnavailable extends RuntimeException {}
