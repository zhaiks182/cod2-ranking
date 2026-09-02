<?php

namespace Tests\Feature\Support;

use App\Models\GalleryItem;
use App\Models\SiteUser;
use App\Support\GalleryQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_bytes_equals_the_full_quota_with_no_uploads(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->assertSame(GalleryQuota::limitBytes(), GalleryQuota::remainingBytes($siteUser));
    }

    public function test_remaining_bytes_subtracts_existing_uploads(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);
        GalleryItem::create([
            'site_user_id' => $siteUser->id, 'title' => 'x', 'type' => 'image',
            'file_path' => 'gallery/1/a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024 * 1024,
        ]);

        $this->assertSame(GalleryQuota::limitBytes() - 1024 * 1024, GalleryQuota::remainingBytes($siteUser));
    }

    public function test_fits_rejects_a_file_larger_than_the_remaining_quota(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->assertFalse(GalleryQuota::fits($siteUser, GalleryQuota::limitBytes() + 1));
        $this->assertTrue(GalleryQuota::fits($siteUser, GalleryQuota::limitBytes()));
    }

    public function test_limit_bytes_uses_the_configured_setting(): void
    {
        \App\Models\Setting::current()->update(['gallery_quota_mb' => 50]);

        $this->assertSame(50 * 1024 * 1024, GalleryQuota::limitBytes());
    }

    public function test_limit_bytes_defaults_to_100mb_when_unset(): void
    {
        $this->assertNull(\App\Models\Setting::current()->gallery_quota_mb);
        $this->assertSame(100 * 1024 * 1024, GalleryQuota::limitBytes());
    }
}
