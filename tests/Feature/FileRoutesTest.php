<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * PRISM accepts no file uploads. Laravel's local disk "serve" option would add
 * GET and PUT /storage/{path} routes, and the PUT one writes a file.
 */
class FileRoutesTest extends TestCase
{
    public function test_no_disk_serves_or_accepts_files(): void
    {
        foreach (config('filesystems.disks') as $name => $disk) {
            $this->assertFalse((bool) ($disk['serve'] ?? false), "Disk [$name] must not set serve to true.");
        }
    }

    public function test_the_storage_upload_and_download_routes_do_not_exist(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringStartsNotWith('storage', $route->uri(), $route->uri().' must not be registered.');
        }

        $this->call('PUT', '/storage/../../.env?upload=1', [], [], [], [], 'x')->assertNotFound();
        $this->get('/storage/x.txt')->assertNotFound();
    }
}
