<?php

namespace App\Mail\Concerns;

/**
 * How a queued email behaves when the mail server is slow or down.
 *
 * Without limits a hung connection ran until the worker killed the job
 * ("has timed out") and the mail was simply lost. Now the connection itself
 * gives up after MAIL_TIMEOUT seconds (config/mail.php), the job is allowed a
 * little longer than that, and a failure is tried again twice, a short while
 * apart, before it is reported as failed.
 */
trait RetriesSafely
{
    /** Attempts in all, including the first. */
    public int $tries = 3;

    /** Seconds one attempt may take; longer than the SMTP timeout. */
    public int $timeout = 60;

    /** Seconds to wait before the second and the third attempt. */
    public array $backoff = [30, 120];
}
