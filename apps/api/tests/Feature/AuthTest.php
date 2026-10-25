<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Mockery;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github.client_id' => 'test-client-id',
            'services.github.client_secret' => 'test-client-secret',
            'services.github.redirect' => 'http://localhost:8000/api/v1/auth/github/callback',
        ]);
    }

    public function test_redirect_returns_a_github_authorize_url_with_state(): void
    {
        $response = $this->getJson('/api/v1/auth/github/redirect');

        $response->assertOk()->assertJsonStructure(['url', 'expires_in']);

        $url = $response->json('url');
        $this->assertStringContainsString('github.com/login/oauth/authorize', $url);
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('state=', $url);
    }

    public function test_callback_requires_a_valid_state(): void
    {
        $response = $this->getJson('/api/v1/auth/github/callback?code=abc123&state=forged');

        $response->assertStatus(422);
        $this->assertSame(0, User::count());
    }

    public function test_callback_requires_code_and_state(): void
    {
        $this->getJson('/api/v1/auth/github/callback')->assertStatus(422);
    }

    public function test_first_callback_creates_the_user_and_returns_a_token(): void
    {
        $state = $this->requestState();
        $this->fakeSocialiteUser();

        $response = $this->getJson('/api/v1/auth/github/callback?code=abc123&state='.$state);

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'username', 'level'], 'first_login']);

        $this->assertSame(1, User::count());
        $this->assertSame('octocat', User::first()->username);
        $this->assertSame(12345, User::first()->github_id);
        $this->assertNotEmpty($response->json('token'));

        // The token must be a real Sanctum token usable against /me.
        $this->withHeaders(['Authorization' => 'Bearer '.$response->json('token')])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.username', 'octocat');
    }

    public function test_second_callback_updates_instead_of_duplicating(): void
    {
        $this->fakeSocialiteUser();

        $first = $this->getJson('/api/v1/auth/github/callback?code=a&state='.$this->requestState());
        $second = $this->getJson('/api/v1/auth/github/callback?code=b&state='.$this->requestState());

        $first->assertOk();
        $second->assertOk()->assertJsonPath('first_login', false);

        $this->assertSame(1, User::count());
        $this->assertSame(2, DB::table('personal_access_tokens')->count());
    }

    public function test_state_is_single_use(): void
    {
        $state = $this->requestState();
        $this->fakeSocialiteUser();

        $this->getJson('/api/v1/auth/github/callback?code=a&state='.$state)->assertOk();

        // Replaying the same state must fail even though the code would work.
        $this->getJson('/api/v1/auth/github/callback?code=a&state='.$state)->assertStatus(422);
        $this->assertSame(1, User::count());
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_protected_routes_reject_anonymous_requests(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->patchJson('/api/v1/me', ['timezone' => 'UTC'])->assertUnauthorized();
        $this->deleteJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    /**
     * Ask the API for a real single-use state value and return it.
     */
    private function requestState(): string
    {
        $url = $this->getJson('/api/v1/auth/github/redirect')->json('url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    /**
     * Stub the Socialite GitHub provider so no HTTP request is made.
     */
    private function fakeSocialiteUser(): void
    {
        $githubUser = new \Laravel\Socialite\Two\User;
        $githubUser->map([
            'id' => 12345,
            'nickname' => 'octocat',
            'name' => 'The Octocat',
            'avatar' => 'https://avatars.githubusercontent.com/u/583231',
            'token' => 'gho_test_token',
            'refresh_token' => null,
        ]);

        $provider = Mockery::mock(GithubProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($githubUser);
        $provider->shouldReceive('redirect')->andReturn(
            redirect('https://github.com/login/oauth/authorize?client_id=test-client-id'),
        );

        Socialite::shouldReceive('driver')->with('github')->andReturn($provider);
    }
}
