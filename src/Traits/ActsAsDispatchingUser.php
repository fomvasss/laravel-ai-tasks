<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Traits;

use Illuminate\Support\Facades\Auth;

/**
 * Carries the user who dispatched the task, and the app locale, to wherever the package runs
 * its code — the queue worker in the first place, where nobody is authenticated: tools calling
 * auth()->user(), policies and scopes then act as that user, as they did in the request.
 *
 * The user is looked up again by id on the guard that was in use at dispatch (it becomes the
 * default guard for the call, so a plain auth()->user() sees it); a user deleted in between
 * leaves nobody authenticated. Everything is restored afterwards, including when the call throws,
 * so the next job of the same worker never inherits the user.
 */
trait ActsAsDispatchingUser
{
    public function executionContext(): array
    {
        $guard = Auth::getDefaultDriver();
        $id    = Auth::guard($guard)->id();

        return [
            'guard' => $guard,
            'user_id' => $id === null ? null : (is_int($id) ? $id : (string) $id),
            'locale' => app()->getLocale(),
        ];
    }

    public static function withExecutionContext(array $context, \Closure $call): mixed
    {
        if (! array_key_exists('guard', $context)) {
            return $call();
        }

        $previousDefault = Auth::getDefaultDriver();
        $guard           = Auth::guard($context['guard']);
        // hasUser(), not user(): user() on a session guard would read the session of whatever
        // request this worker last handled
        $previousUser   = $guard->hasUser() ? $guard->user() : null;
        $previousLocale = app()->getLocale();

        try {
            Auth::shouldUse($context['guard']);

            // The same user already on the guard (a sync send() in the request) is kept as is,
            // not re-fetched: the instance may carry request state, e.g. Sanctum's current token
            $sameUser = $previousUser !== null && $context['user_id'] !== null
                && (string) $previousUser->getAuthIdentifier() === (string) $context['user_id'];

            if (! $sameUser) {
                $user = $context['user_id'] !== null
                    ? $guard->getProvider()->retrieveById($context['user_id'])
                    : null;

                $user ? $guard->setUser($user) : $guard->forgetUser();
            }

            if (isset($context['locale'])) {
                app()->setLocale($context['locale']);
            }

            return $call();
        } finally {
            $previousUser ? $guard->setUser($previousUser) : $guard->forgetUser();
            Auth::shouldUse($previousDefault);
            app()->setLocale($previousLocale);
        }
    }
}
