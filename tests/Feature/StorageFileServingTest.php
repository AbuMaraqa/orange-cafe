<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * On a packaged desktop install there is no public/storage symlink to fall
 * back on, so this route is the only thing standing between the customer
 * menu and a page full of broken images.
 */
class StorageFileServingTest extends TestCase
{
    // Even a 404 from this route renders through HandleInertiaRequests,
    // which reads the restaurant settings out of the database.
    use RefreshDatabase;

    public function test_it_serves_a_file_from_the_public_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/latte.txt', 'bytes');

        $this->get('/storage/products/latte.txt')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=86400, public')
            ->assertStreamedContent('bytes');
    }

    public function test_it_serves_a_nested_upload_path(): void
    {
        Storage::fake('public');
        Storage::disk('public')->putFileAs('categories/hot', UploadedFile::fake()->create('x.bin', 1), 'x.bin');

        $this->get('/storage/categories/hot/x.bin')->assertOk();
    }

    public function test_a_missing_file_is_a_404_rather_than_an_error(): void
    {
        Storage::fake('public');

        $this->get('/storage/products/nope.png')->assertNotFound();
    }

    /**
     * The route pattern matches slashes so that nested upload paths work,
     * which means a traversal attempt arrives here as a real (already
     * URL-decoded) path rather than being filtered out by the router.
     */
    public function test_it_refuses_to_walk_out_of_the_public_disk(): void
    {
        Storage::fake('public');

        foreach (['../.env', 'products/../../.env', '..%2F.env'] as $attempt) {
            $this->get('/storage/'.$attempt)->assertNotFound();
        }
    }

    public function test_an_unchanged_file_is_answered_with_304(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logo.txt', 'bytes');

        $etag = $this->get('/storage/logo.txt')->headers->get('Etag');

        $this->assertNotNull($etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->get('/storage/logo.txt')
            ->assertStatus(304);
    }
}
