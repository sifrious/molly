<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\Foundation\Console\TerminatingConsole;
use Symfony\Component\Process\Process;

/*
 * M03.3: Molly binds no port of its own. The web interface runs inside the application under
 * php artisan serve or the application's own web server, molly:worker runs queue:work, and
 * mcp:start talks over stdio. These tests pin that, and pin what the documented serve
 * commands do when the port is taken: a named error and exit 1 with --port, and the next
 * free port, printed, without it. Testbench's serve is Laravel's ServeCommand.
 */

/** Hold a loopback port the way another program would, and return the socket and its port. */
function occupyLoopbackPort(): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    if ($socket === false) {
        throw new RuntimeException('The test could not listen on a loopback port: '.$message);
    }

    return [$socket, (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1)];
}

/** Stop a serve process and the PHP server it started, which does not stop with its parent. */
function stopServe(Process $serve): void
{
    $pid = $serve->getPid();
    if ($pid !== null) {
        $children = new Process(['pgrep', '-P', (string) $pid]);
        $children->run();
        foreach (array_filter(explode("\n", trim($children->getOutput()))) as $child) {
            posix_kill((int) $child, SIGTERM);
        }
    }
    $serve->stop(5);
}

it('binds no socket anywhere in Molly', function (): void {
    $listeners = [];
    foreach (File::allFiles(dirname(__DIR__, 2).'/src') as $file) {
        if (preg_match('/\b(stream_socket_server|socket_bind|socket_listen|socket_create_listen)\s*\(|[\'"](serve|-S)[\'"]/', $file->getContents()) === 1) {
            $listeners[] = $file->getRelativePathname();
        }
    }

    expect($listeners)->toBe([]);
});

it('fails with a named error and exit 1 when the port given to serve --port is taken', function (): void {
    [$socket, $port] = occupyLoopbackPort();
    // Run serve in this process: Testbench's own command runner exits 0 after serve returns.
    // SIGALRM fails the test instead of letting a serve that never exits hang the suite.
    $async = pcntl_async_signals(true);
    pcntl_signal(SIGALRM, function (): void {
        throw new RuntimeException('php artisan serve --port kept running while the port was taken.');
    });
    pcntl_alarm(30);

    try {
        $exit = Artisan::call('serve', ['--host' => '127.0.0.1', '--port' => $port]);
        $output = Artisan::output();
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_async_signals($async);
        TerminatingConsole::flush();
        fclose($socket);
    }

    expect($exit)->toBe(1)
        ->and($output)->toContain('Failed to listen on 127.0.0.1:'.$port.' (reason: Address already in use)')
        ->and($output)->not->toContain('Server running on');
});

it('moves serve to the next free port and prints it when no port is given', function (): void {
    [$socket, $port] = occupyLoopbackPort();
    // A port in --host, like the default 8000, leaves --port empty, so serve may try the next port.
    $serve = testbenchProcess(['serve', '--host=127.0.0.1:'.$port, '--tries=5', '--no-reload'], timeout: 60);
    $serve->start();

    try {
        $address = null;
        $deadline = microtime(true) + 30;
        while ($address === null && $serve->isRunning() && microtime(true) < $deadline) {
            if (preg_match('~Server running on \[http://127\.0\.0\.1:(\d+)\]~', $serve->getOutput(), $match) === 1) {
                $address = (int) $match[1];
            }
            usleep(100_000);
        }
        $output = $serve->getOutput().$serve->getErrorOutput();

        expect($address)->not->toBeNull($output)
            ->and($address)->not->toBe($port)
            ->and($output)->toContain('Failed to listen on 127.0.0.1:'.$port);

        // The printed address is where the server listens.
        $client = @stream_socket_client('tcp://127.0.0.1:'.$address, $code, $message, 5);
        expect($client)->not->toBeFalse($message);
        fwrite($client, "GET / HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
        expect((string) fgets($client))->toStartWith('HTTP/');
        fclose($client);
    } finally {
        stopServe($serve);
        fclose($socket);
    }
});
