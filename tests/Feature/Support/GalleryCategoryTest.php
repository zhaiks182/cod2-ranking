<?php

namespace Tests\Feature\Support;

use App\Support\GalleryCategory;
use Tests\TestCase;

class GalleryCategoryTest extends TestCase
{
    public function test_label_resolves_a_valid_code(): void
    {
        $this->assertSame('Troll', GalleryCategory::label('troll'));
    }

    public function test_label_returns_null_for_an_unknown_code(): void
    {
        $this->assertNull(GalleryCategory::label('no_existe'));
    }

    public function test_label_returns_null_without_a_code(): void
    {
        $this->assertNull(GalleryCategory::label(null));
    }
}
