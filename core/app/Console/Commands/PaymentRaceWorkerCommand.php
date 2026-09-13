<?php

namespace App\Console\Commands;

use App\Support\Coerce;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Throwable;

/**
 * One contender in the callback race. Started as its own OS process by
 * `payment:prove-callback-race`, so the callbacks really do arrive through separate PHP processes,
 * separate PDO connections and separate MariaDB sessions — the only arrangement that can produce a
 * deadlock, since a single-process test only ever proves the code path.
 *
 * `--at` is the wave-3.5 trick: every worker busy-waits until the same instant and then fires, so
 * they contend instead of queueing behind each other's startup time.
 *
 * Prints exactly one line: the HTTP status code, or `ERR <message>`.
 */
final class PaymentRaceWorkerCommand extends Command
{
    protected $signature = 'payment:race-worker
        {--url= : the signed callback PATH (with query string) to handle}
        {--at= : unix timestamp (float) to fire at, so every worker starts together}';

    protected $description = 'Internal: one racing payment callback for payment:prove-callback-race';

    protected $hidden = true;

    public function handle(): int
    {
        $url = Coerce::str($this->option('url'));
        if ($url === '') {
            $this->line('ERR no url');

            return self::FAILURE;
        }

        $at = (float) Coerce::str($this->option('at'));
        if ($at > 0.0) {
            // Busy-wait rather than sleep: the last millisecond is the point of the exercise.
            while (microtime(true) < $at) {
                // spin
            }
        }

        try {
            /*
             * Through the HTTP KERNEL, in this process — not over the network.
             *
             * The first version used `Http::get()` against a booted host, and the host was
             * `php -S`: single-threaded. It served the callbacks one at a time (so no contention
             * was possible) and at sixteen workers it refused connections outright, which the
             * prover counted as hard failures. Running the request here gives each worker its own
             * connection and session with nothing serialising them — the arrangement wave 3.5's
             * `inventory:race-worker` uses for the same reason.
             */
            $kernel = app(HttpKernel::class);
            $response = $kernel->handle(Request::create($url, 'GET'));
            $this->line((string) $response->getStatusCode());
        } catch (Throwable $e) {
            $this->line('ERR '.$e::class);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
