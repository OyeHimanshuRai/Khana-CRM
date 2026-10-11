<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * ERP as CRM sees it when it reads: an address, a token from Accounts, and
 * answers shaped the way contracts/api/erp.yaml says.
 *
 * Nothing here is ERP. Every answer is faked at the HTTP client, and a
 * request a test did not fake is refused rather than sent - the machine this
 * suite runs on may well have a real ERP listening, and a test that reached
 * it would pass or fail on somebody's development data.
 *
 * Use it beside TalksToErp, whose linkToAccounts() is what makes a shop a
 * Suite outlet in the first place.
 */
trait ReadsErp
{
    protected const ERP_URL = 'https://erp.example.test';

    protected const ACCOUNTS_URL = 'https://accounts.example.test';

    /**
     * Tell this install where ERP and Accounts are, the way its .env would,
     * and refuse any request that no fake answers.
     */
    protected function erpIsConfigured(): void
    {
        config([
            'khana.urls.erp' => self::ERP_URL,
            'khana.sso.accounts_url' => self::ACCOUNTS_URL,
            'khana.sso.machine_client_id' => 'crm-machine',
            'khana.sso.machine_client_secret' => 'machine-secret',
        ]);

        Http::preventStrayRequests();
    }

    /**
     * ERP answers these paths for this outlet. A path is what follows
     * /api/v1/outlets/{outlet}/, and an answer is the JSON body, or a
     * response built with Http::response() for anything but a plain 200.
     *
     * @param  array<string, mixed>  $answers
     */
    protected function erpAnswers(string $outlet, array $answers): void
    {
        $this->erpIsConfigured();

        $stubs = [self::ACCOUNTS_URL.'/oauth/token' => Http::response(['access_token' => 'machine-token', 'expires_in' => 3600])];

        foreach ($answers as $path => $answer) {
            $stubs[$this->erpEndpoint($outlet, $path).'*'] = is_array($answer) ? Http::response($answer) : $answer;
        }

        Http::fake($stubs);
    }

    /** Accounts issues a token, and then nothing is listening at ERP's address. */
    protected function erpIsDown(): void
    {
        $this->erpIsConfigured();

        Http::fake([
            self::ACCOUNTS_URL.'/oauth/token' => Http::response(['access_token' => 'machine-token', 'expires_in' => 3600]),
            self::ERP_URL.'/*' => fn () => throw new ConnectionException('Connection refused'),
        ]);
    }

    /**
     * For a test that says no call is made: ERP and Accounts are both there
     * and would answer anything, so a call that should not have happened is
     * recorded, and Http::assertNothingSent() means what it says.
     *
     * Deliberately not built on refusing the request instead. The client
     * treats a refused request as "ERP is down", and a test would then see
     * nothing sent for the wrong reason.
     */
    protected function erpWouldAnswerAnything(): void
    {
        $this->erpIsConfigured();

        Http::fake();
    }

    /** The full address of one of ERP's outlet endpoints. */
    protected function erpEndpoint(string $outlet, string $path): string
    {
        return self::ERP_URL.'/api/v1/outlets/'.$outlet.'/'.$path;
    }

    /** How many requests went to ERP itself - the token trip to Accounts is not one. */
    protected function assertErpAsked(int $times): void
    {
        $asked = Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::ERP_URL))->count();

        $this->assertSame($times, $asked, "ERP was asked {$asked} time(s), expected {$times}.");
    }
}
