<?php

namespace Tests\Feature;

use App\Providers\NativeAppServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The link that lets the LAN server hand out menu photos by itself.
 *
 * Without it every uploaded image is answered by StorageFileController, which
 * is a full framework boot per photo — 46ms against 1.8ms measured, on a
 * server that answers one request at a time. A customer opening the menu on
 * their phone holds the kitchen screen behind their forty pictures.
 *
 * What is tested here is not the speed, it is the safety. The link is
 * recreated at every boot on a machine whose storage directory has been moved
 * somewhere else, so the code has to decide what to delete — and behind
 * `public/storage` sits the cafe's entire menu photography. Getting the
 * "leave it alone" branch wrong destroys a customer's data on startup, which
 * is why the real-directory case is here at all.
 */
class PublicStorageLinkTest extends TestCase
{
    private string $root;

    private string $public;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // A tree of its own, so a mistake in the code under test can only
        // ever cost a temporary directory.
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orange-link-'.bin2hex(random_bytes(6));
        $this->public = $this->root.DIRECTORY_SEPARATOR.'public';
        $this->storage = $this->root.DIRECTORY_SEPARATOR.'storage';

        mkdir($this->public, 0755, true);
        mkdir($this->storage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public', 0755, true);
        mkdir($this->storage.DIRECTORY_SEPARATOR.'logs', 0755, true);

        $this->app->usePublicPath($this->public);
        $this->app->useStoragePath($this->storage);

        // The method logs on every branch; none of it belongs in a test run.
        Log::spy();
    }

    protected function tearDown(): void
    {
        // rmdir first, so a junction left behind by a failed assertion is
        // unlinked rather than followed and emptied.
        @rmdir($this->public.DIRECTORY_SEPARATOR.'storage');
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    private function link(): string
    {
        return $this->public.DIRECTORY_SEPARATOR.'storage';
    }

    private function target(): string
    {
        return $this->storage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public';
    }

    private function runLinker(): void
    {
        $method = new ReflectionMethod(NativeAppServiceProvider::class, 'linkPublicStorage');
        $method->setAccessible(true);
        $method->invoke(new NativeAppServiceProvider);

        clearstatcache();
    }

    public function test_it_points_public_storage_at_the_storage_disk(): void
    {
        $this->runLinker();

        $this->assertSame(realpath($this->target()), realpath($this->link()));
    }

    public function test_a_file_on_the_disk_is_reachable_through_the_link(): void
    {
        file_put_contents($this->target().DIRECTORY_SEPARATOR.'burger.jpg', 'photo');

        $this->runLinker();

        // The whole point: this is the check `php -S`'s router script makes
        // before deciding to serve a file itself instead of waking Laravel.
        $this->assertFileExists($this->link().DIRECTORY_SEPARATOR.'burger.jpg');
    }

    public function test_it_replaces_a_link_left_over_from_the_build_machine(): void
    {
        // What ships if the packaging ever preserves the developer's own
        // link: a path that means nothing on this machine.
        $elsewhere = $this->root.DIRECTORY_SEPARATOR.'someone-elses-storage';
        mkdir($elsewhere, 0755, true);
        (new Filesystem)->link($elsewhere, $this->link());

        $this->runLinker();

        $this->assertSame(realpath($this->target()), realpath($this->link()));
    }

    public function test_it_refuses_to_delete_a_real_directory(): void
    {
        // A build that resolved the junction and copied its contents in.
        // Wrong, stale, and absolutely not something to remove on startup —
        // the app serves correctly around it through StorageFileController.
        mkdir($this->link(), 0755, true);
        file_put_contents($this->link().DIRECTORY_SEPARATOR.'someones-photo.jpg', 'irreplaceable');

        $this->runLinker();

        $this->assertFileExists($this->link().DIRECTORY_SEPARATOR.'someones-photo.jpg');
        $this->assertSame('irreplaceable', file_get_contents($this->link().DIRECTORY_SEPARATOR.'someones-photo.jpg'));
    }

    public function test_running_it_again_changes_nothing(): void
    {
        file_put_contents($this->target().DIRECTORY_SEPARATOR.'burger.jpg', 'photo');

        $this->runLinker();
        $this->runLinker();

        $this->assertSame(realpath($this->target()), realpath($this->link()));
        $this->assertSame('photo', file_get_contents($this->link().DIRECTORY_SEPARATOR.'burger.jpg'));
    }

    public function test_it_creates_the_storage_directory_when_nothing_has_been_uploaded_yet(): void
    {
        // mklink /J refuses a target that does not exist, and a fresh install
        // has had no photo uploaded to make one.
        rmdir($this->target());
        $this->assertDirectoryDoesNotExist($this->target());

        $this->runLinker();

        $this->assertSame(realpath($this->target()), realpath($this->link()));
    }
}
