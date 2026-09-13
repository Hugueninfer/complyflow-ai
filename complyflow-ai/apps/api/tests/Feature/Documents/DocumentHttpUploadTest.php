<?php

namespace Tests\Feature\Documents;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentOrganization;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DocumentHttpUploadTest extends TestCase
{
    use DatabaseMigrations;

    public function test_real_multipart_upload_accepts_five_mib_and_returns_413_above_the_limit(): void
    {
        $this->seed();
        $organization = Organization::query()->create(['name' => 'HTTP upload', 'slug' => (string) Str::uuid()]);
        app(CurrentOrganization::class)->set($organization);
        $supplier = Supplier::query()->create(['name' => 'HTTP supplier']);
        $user = User::factory()->create(['password' => 'multipart-password']);
        $organization->users()->attach($user, ['role_id' => Role::query()->where('name', 'analyst')->firstOrFail()->id]);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = new Process([PHP_BINARY, '-S', $address, '-t', public_path(), public_path('index.php')], base_path(), [
            'APP_KEY' => config('app.key'), 'APP_DEBUG' => 'false', 'SESSION_DRIVER' => 'database',
        ]);

        try {
            $server->start();
            $deadline = microtime(true) + 5;
            do {
                $connection = @stream_socket_client('tcp://'.$address, timeout: 0.1);
                if ($connection !== false) {
                    fclose($connection);
                    break;
                }
                usleep(10000);
            } while ($server->isRunning() && microtime(true) < $deadline);

            $cookies = new CookieJar;
            $login = Http::acceptJson()->withOptions(['cookies' => $cookies])
                ->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
                ->post('http://'.$address.'/api/v1/login', ['email' => $user->email, 'password' => 'multipart-password']);
            $this->assertSame(200, $login->status(), $login->body());

            foreach ([5242880 => 201, 5242881 => 413, 7340032 => 413] as $size => $expectedStatus) {
                $file = UploadedFile::fake()->createWithContent('private.pdf', str_pad("%PDF-1.7\n%%EOF", $size, ' '));
                $stream = fopen($file->getRealPath(), 'rb');
                try {
                    $response = Http::acceptJson()->withOptions(['cookies' => $cookies])->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
                        ->attach('file', $stream, 'private.pdf')
                        ->post('http://'.$address.'/api/v1/suppliers/'.$supplier->public_id.'/documents');
                    $this->assertSame($expectedStatus, $response->status(), $response->body());
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }
            $this->assertDatabaseCount('documents', 1);
            $this->assertDatabaseCount('document_blobs', 1);
        } finally {
            $server->stop();
        }
    }
}
