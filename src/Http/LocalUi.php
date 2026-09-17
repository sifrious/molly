<?php

namespace Sifrious\Molly\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalUi
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->authorize($request);

        return $next($request);
    }

    public function authorize(Request $request): void
    {
        abort_unless(config('molly.ui.enabled') === true && app()->environment('local', 'testing'), 404);
        abort_unless(in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true), 403);
        abort_unless(in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true), 403);
    }
}
