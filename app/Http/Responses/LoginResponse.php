<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * After sign-in, go back to the page the person was sent away from only if
 * they may open it; otherwise go to their own start page (D-186). On a
 * shared till, an Owner whose session ran out on an admin page must not
 * leave the next cashier on a "Not allowed" page.
 */
class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    /** @param  Request  $request */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }

        $intended = $request->session()->pull('url.intended');
        $user = $request->user();

        if (is_string($intended) && $user instanceof User && $this->allowed($user, $intended, $request)) {
            return new RedirectResponse($intended);
        }

        return new RedirectResponse(url((string) config('fortify.home')));
    }

    private function allowed(User $user, string $url, Request $request): bool
    {
        if (parse_url($url, PHP_URL_HOST) !== null && parse_url($url, PHP_URL_HOST) !== $request->getHost()) {
            return false;
        }

        try {
            /** @var Route $route */
            $route = Router::getRoutes()->match(Request::create($url, 'GET'));
        } catch (\Throwable) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            [$name, $arguments] = array_pad(explode(':', $middleware, 2), 2, '');
            $needed = array_filter(explode('|', $arguments));
            if ($name === 'permission' && ! $user->hasAnyPermission($needed)) {
                return false;
            }
            if ($name === 'role' && ! $user->hasAnyRole($needed)) {
                return false;
            }
        }

        return true;
    }
}
