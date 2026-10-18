<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

// The test APK link: hidden and 404 until a build is uploaded, then a download.
class PartnerAppDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function apk(): string
    {
        return storage_path('app/downloads/restro-one-partner.apk');
    }

    protected function tearDown(): void
    {
        File::delete($this->apk());

        parent::tearDown();
    }

    public function test_no_build_means_no_link_and_a_404(): void
    {
        File::delete($this->apk());

        $this->get('/')->assertOk()->assertDontSee('Staff app');
        $this->get('/download/partner-app')->assertNotFound();
    }

    public function test_an_uploaded_build_is_linked_and_downloads(): void
    {
        File::ensureDirectoryExists(dirname($this->apk()));
        File::put($this->apk(), 'apk');

        $this->get('/')->assertOk()->assertSee(route('partner-app.download'));
        $this->get('/download/partner-app')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.android.package-archive');
    }
}
