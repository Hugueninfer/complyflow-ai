<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RegistrationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_requests_that_pass_validation_create_one_owner_and_return_safe_422_for_the_loser(): void
    {
        $this->seed();
        $workers = [];
        $inputs = [];
        try {
            foreach (['race@example.invalid', ' RACE@EXAMPLE.INVALID '] as $email) {
                $input = new InputStream;
                $worker = new Process([PHP_BINARY, base_path('tests/Support/register-worker.php'), $email], base_path(), timeout: 15);
                $worker->setInput($input)->start();
                $workers[] = $worker;
                $inputs[] = $input;
            }
            foreach ($workers as $worker) {
                $deadline = microtime(true) + 8;
                while (! str_contains($worker->getOutput(), 'ready') && $worker->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ready', $worker->getOutput(), $worker->getErrorOutput());
            }
            // Both requests already passed validation. Release their inserts in order.
            $results = [];
            foreach ($workers as $index => $worker) {
                $inputs[$index]->write("go\n");
                $inputs[$index]->close();
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame([201, 422], array_column($results, 'status'));
            $this->assertArrayHasKey('email', $results[1]['body']['errors']);
            $this->assertStringNotContainsString('SQLSTATE', json_encode($results[1]));
            $this->assertStringNotContainsString('exception', json_encode($results[1]));
            $this->assertDatabaseHas('users', ['email' => 'race@example.invalid']);
            $this->assertSame(1, User::whereRaw('lower(email) = ?', ['race@example.invalid'])->count());
            $this->assertSame(1, Organization::where('name', 'Race tenant')->count());
        } finally {
            foreach ($inputs as $input) {
                $input->close();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
