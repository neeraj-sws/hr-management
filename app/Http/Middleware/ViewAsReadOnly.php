<?php

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * "View as employee" is view only: pages, filters, tabs, paging and opening forms work, but nothing is saved
 * in the employee's name (no check-in, leave, status, upload, approval …). Return / log out always work.
 */
class ViewAsReadOnly
{
    /** Livewire actions that only change what is shown. */
    private const VIEW_ACTIONS = ['gotoPage', 'nextPage', 'previousPage', 'setPage', 'resetPage', 'sortBy', 'resetFilters', 'view', 'closeView', 'showTab', 'stepDay', 'loadMore', 'setType', 'create', 'edit', 'closeModal', 'cancelReject', 'closeCapture'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! ImpersonationService::active() || $request->isMethodSafe()) return $next($request);
        if ($request->routeIs('logout', 'people.view-as.stop')) return $next($request);

        if ($request->routeIs('*livewire.update')) {
            foreach ((array) $request->input('components', []) as $component) {
                foreach ((array) ($component['calls'] ?? []) as $call) {
                    $method = (string) ($call['method'] ?? '');
                    if (Str::startsWith($method, ['$', 'open', 'close', 'show']) || in_array($method, self::VIEW_ACTIONS, true)) continue;

                    return $this->deny($request);
                }
            }

            return $next($request); // only filters / fields changed on screen
        }

        return $this->deny($request);
    }

    private function deny(Request $request): Response
    {
        $message = 'View only: nothing can be saved while you are viewing as an employee.';
        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) abort(403, $message);

        return back()->with('toast', ['message' => $message, 'type' => 'error']);
    }
}
