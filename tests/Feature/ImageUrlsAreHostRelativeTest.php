<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploaded images must be linked host-relative.
 *
 * An absolute URL bakes one hostname into every image link. The app shipped
 * with APP_URL=http://localhost:8000, so photos loaded perfectly on the
 * machine running the server and were broken on every tablet, phone and
 * kitchen screen in the building — "localhost" on a waiter's tablet is the
 * tablet. Pointing it at a LAN IP instead only moves the failure to the next
 * time the router hands out a different lease.
 */
class ImageUrlsAreHostRelativeTest extends TestCase
{
    public function test_the_public_disk_serves_relative_urls(): void
    {
        $url = Storage::disk('public')->url('products/burger.jpg');

        $this->assertSame('/storage/products/burger.jpg', $url);
    }

    public function test_no_image_url_carries_a_hostname(): void
    {
        foreach (['products/a.jpg', 'categories/b.png', 'logos/c.svg'] as $path) {
            $url = Storage::disk('public')->url($path);

            $this->assertStringStartsWith('/', $url);
            $this->assertStringNotContainsString('://', $url, "[$url] must not be absolute");
            $this->assertStringNotContainsString('localhost', $url);
        }
    }

    public function test_a_product_image_url_is_relative(): void
    {
        $product = new Product(['image_path' => 'products/juice.jpg']);
        $product->image_path = 'products/juice.jpg';

        $this->assertSame('/storage/products/juice.jpg', $product->image_url);
    }

    public function test_a_product_without_an_image_has_no_url(): void
    {
        $this->assertNull((new Product)->image_url);
    }
}
