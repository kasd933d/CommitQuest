<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The user passed to actingAs(), so it can be re-applied after guards are flushed.
     */
    protected ?Authenticatable $actingAsUser = null;

    protected ?string $actingAsGuard = null;

    /**
     * Laravel's RequestGuard caches the user it resolves (see RequestGuard::user()).
     * In production the application instance lives for exactly one request, but a
     * test issues several requests against a single application instance, which
     * would let a user stay authenticated after logout or account deletion.
     *
     * We therefore flush the guards after every request so each one has to
     * re-authenticate from its own headers, while re-applying anything that was
     * set explicitly through actingAs().
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $auth = $this->app->make('auth');

        if ($this->actingAsUser !== null) {
            $auth->guard($this->actingAsGuard)->setUser($this->actingAsUser);
        }

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $auth->forgetGuards();
        }
    }

    public function actingAs(Authenticatable $user, $driver = null)
    {
        $this->actingAsUser = $user;
        $this->actingAsGuard = $driver;

        return parent::actingAs($user, $driver);
    }
}
