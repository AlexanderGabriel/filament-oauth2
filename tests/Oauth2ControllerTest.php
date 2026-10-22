<?php

use AlexanderGabriel\FilamentOauth2\Tests\Fixtures\Role;
use AlexanderGabriel\FilamentOauth2\Tests\Fixtures\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use League\OAuth2\Client\Provider\GenericProvider;

/**
 * Lets the provider talk to a fake Oauth2-Server answering the token and
 * userinfo requests. Returns the requests sent to it.
 */
function fakeOauth2Server(array $userinfo = [], array $tokenClaims = []): ArrayObject
{
    $userinfo += ['sub' => 'abc', 'email' => 'jane@example.com', 'email_verified' => true, 'name' => 'Jane Doe'];
    $encode = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
    $accessToken = $encode(['alg' => 'RS256']) . '.' . $encode($tokenClaims + ['sub' => 'abc']) . '.signature';

    $requests = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => $accessToken, 'token_type' => 'Bearer', 'expires_in' => 300])),
        new Response(200, ['Content-Type' => 'application/json'], json_encode($userinfo)),
    ]));
    $stack->push(Middleware::history($requests));

    app()->bind(GenericProvider::class, fn ($app, $params) => new GenericProvider($params['options'], ['httpClient' => new Client(['handler' => $stack])]));

    return $requests;
}

function rolesClaim(array $roles): array
{
    return ['resource_access' => ['filament' => ['roles' => $roles]]];
}

function callback(array $query = []): TestResponse
{
    return test()
        ->withSession(['filament-oauth2.state' => 'the-state', 'filament-oauth2.pkceCode' => 'the-verifier'])
        ->get('/admin/filament-oauth2/handleCallback?' . http_build_query($query + ['code' => 'the-code', 'state' => 'the-state']));
}

beforeEach(function () {
    User::$requiredRole = null;
});

it('redirects to the oauth2 server with state and pkce', function () {
    $response = $this->get('/admin/login');

    $response->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($response->headers->get('Location'))->toStartWith('https://idp.test/auth?')
        ->and($query['state'])->toBe(session('filament-oauth2.state'))
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', session('filament-oauth2.pkceCode'), true)), '+/', '-_'), '='));
});

it('logs in and creates the user', function () {
    $requests = fakeOauth2Server();

    callback()->assertRedirect();

    $user = User::firstWhere('email', 'jane@example.com');
    expect(auth()->id())->toBe($user->id)
        ->and($user->name)->toBe('Jane Doe');

    parse_str((string) $requests[0]['request']->getBody(), $tokenRequest);
    expect($tokenRequest['code'])->toBe('the-code')
        ->and($tokenRequest['code_verifier'])->toBe('the-verifier');
});

it('does not create a known password', function () {
    fakeOauth2Server();

    callback();

    expect(Hash::check('nonsense', User::firstWhere('email', 'jane@example.com')->password))->toBeFalse();
});

it('rejects a callback with a wrong state', function () {
    $requests = fakeOauth2Server();

    callback(['state' => 'other-state'])->assertForbidden();

    expect(auth()->check())->toBeFalse()
        ->and($requests)->toHaveCount(0);
});

it('rejects a callback without state in the session', function () {
    fakeOauth2Server();

    $this->get('/admin/filament-oauth2/handleCallback?code=the-code&state=')->assertForbidden();

    expect(auth()->check())->toBeFalse();
});

it('rejects a state only once', function () {
    fakeOauth2Server();

    callback()->assertRedirect();
    auth()->logout();

    $this->get('/admin/filament-oauth2/handleCallback?code=the-code&state=the-state')->assertForbidden();
});

it('handles errors of the oauth2 server', function () {
    fakeOauth2Server();

    callback(['error' => 'access_denied', 'code' => ''])->assertForbidden();
});

it('rejects unverified email addresses', function (mixed $emailVerified) {
    fakeOauth2Server(['email_verified' => $emailVerified]);

    callback()->assertForbidden();

    expect(User::count())->toBe(0);
})->with([false, 'false', 0]);

it('accepts verified email addresses sent as string', function () {
    fakeOauth2Server(['email_verified' => 'true']);

    callback()->assertRedirect();
});

it('sets the username on the first login', function () {
    fakeOauth2Server(tokenClaims: ['preferred_username' => 'jane']);

    callback()->assertRedirect();

    expect(User::firstWhere('email', 'jane@example.com')->username)->toBe('jane');
});

it('keeps the password of an existing user', function () {
    $user = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'password' => 'x']);
    $password = $user->password;
    fakeOauth2Server();

    callback()->assertRedirect();

    expect($user->fresh()->password)->toBe($password);
});

it('rejects users without an email address', function () {
    fakeOauth2Server(['email' => null]);

    callback()->assertForbidden();
});

it('maps the roles before checking the panel access', function () {
    config()->set('filament-oauth2.updateRoles', true);
    User::$requiredRole = 'admin';
    fakeOauth2Server(tokenClaims: rolesClaim(['admin']));

    callback()->assertRedirect();

    expect(auth()->user()->roles->pluck('name')->all())->toBe(['admin']);
});

it('denies access as soon as the role is removed', function () {
    config()->set('filament-oauth2.updateRoles', true);
    User::$requiredRole = 'admin';
    $user = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'password' => 'x']);
    $user->roles()->attach(Role::create(['name' => 'admin']));
    fakeOauth2Server(tokenClaims: rolesClaim([]));

    callback()->assertForbidden();

    expect(auth()->check())->toBeFalse()
        ->and($user->roles()->count())->toBe(0);
});

it('only maps allowed roles', function () {
    config()->set('filament-oauth2.updateRoles', true);
    config()->set('filament-oauth2.allowedRoles', ['editor']);
    fakeOauth2Server(tokenClaims: rolesClaim(['editor', 'super_admin']));

    callback()->assertRedirect();

    expect(Role::pluck('name')->all())->toBe(['editor']);
});

it('refuses to disable the ssl verification in production', function () {
    config()->set('filament-oauth2.verifySsl', false);
    app()['env'] = 'production';

    $this->withoutExceptionHandling()->get('/admin/login');
})->throws(RuntimeException::class, 'OAUTH2_VERIFY_SSL=false is not allowed in production.');

it('allows to disable the ssl verification outside of production', function (string $env) {
    config()->set('filament-oauth2.verifySsl', 'false');
    app()['env'] = $env;

    $this->get('/admin/login')->assertRedirect();
})->with(['local', 'testing']);

it('logs out by post and redirects to the oauth2 server', function () {
    $this->actingAs(User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'password' => 'x']));

    $this->get('/admin/filament-oauth2/handleLogout')->assertMethodNotAllowed();

    $this->post('/admin/filament-oauth2/handleLogout')
        ->assertRedirect('https://idp.test/logout?client_id=filament&post_logout_redirect_uri=' . urlencode('https://app.test/bye'));

    expect(auth()->check())->toBeFalse();
});

it('logs out without redirect if no url after logout is configured', function () {
    config()->set('filament-oauth2.urlAfterlogout', null);

    $this->post('/admin/filament-oauth2/handleLogout')
        ->assertRedirect('https://idp.test/logout?client_id=filament');
});

it('makes the url after logout absolute', function () {
    config()->set('filament-oauth2.urlAfterlogout', '/');

    $this->post('/admin/filament-oauth2/handleLogout')
        ->assertRedirect('https://idp.test/logout?client_id=filament&post_logout_redirect_uri=' . urlencode(url('/')));
});

it('posts the logout of the user menu to the oauth2 logout', function () {
    $this->actingAs(User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'password' => 'x']));
    filament()->setCurrentPanel('admin');

    $logout = filament()->getCurrentPanel()->getUserMenuItems()['logout'];

    expect($logout->getUrl())->toBe(url('/admin/filament-oauth2/handleLogout'))
        ->and($logout->shouldPostToUrl())->toBeTrue();
});

it('uses the routes and settings of each panel', function () {
    $response = $this->get('/other/login');

    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($response->headers->get('Location'))->toStartWith('https://other-idp.test/auth?')
        ->and($query['client_id'])->toBe('other-client')
        ->and($query['redirect_uri'])->toBe(url('/other/filament-oauth2/handleCallback'));
});

it('logs in to the panel the callback belongs to', function () {
    $requests = fakeOauth2Server();

    $this->withSession(['filament-oauth2.state' => 'the-state', 'filament-oauth2.pkceCode' => 'the-verifier'])
        ->get('/other/filament-oauth2/handleCallback?code=the-code&state=the-state')
        ->assertRedirect(url('/other'));

    parse_str((string) $requests[0]['request']->getBody(), $tokenRequest);
    expect($tokenRequest['client_id'])->toBe('other-client')
        ->and($tokenRequest['redirect_uri'])->toBe(url('/other/filament-oauth2/handleCallback'));
});

it('creates the roles with the configured role model', function () {
    config()->set('filament-oauth2.updateRoles', true);
    Role::create(['name' => 'editor']);
    fakeOauth2Server(tokenClaims: rolesClaim(['editor', 'viewer']));

    callback()->assertRedirect();

    expect(Role::orderBy('name')->pluck('name')->all())->toBe(['editor', 'viewer'])
        ->and(auth()->user()->roles()->orderBy('name')->pluck('name')->all())->toBe(['editor', 'viewer']);
});

it('leaves the roles untouched if the token has no roles claim', function () {
    config()->set('filament-oauth2.updateRoles', true);
    $user = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'password' => 'x']);
    $user->roles()->attach(Role::create(['name' => 'local']));
    fakeOauth2Server();

    callback()->assertRedirect();

    expect($user->roles()->pluck('name')->all())->toBe(['local']);
});

it('updates the name of an existing user', function () {
    User::create(['name' => 'Old Name', 'email' => 'jane@example.com', 'password' => 'x']);
    fakeOauth2Server();

    callback()->assertRedirect();

    expect(User::firstWhere('email', 'jane@example.com')->name)->toBe('Jane Doe');
});
