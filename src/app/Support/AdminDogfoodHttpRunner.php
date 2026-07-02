<?php

namespace App\Support;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class AdminDogfoodHttpRunner
{
    /** @var array<string, string> */
    private array $cookies = [];

    private ?string $csrfToken = null;

    public function __construct(
        private readonly HttpKernel $kernel,
        private readonly string $email,
        private readonly string $password,
    ) {}

    public function login(): AdminDogfoodHttpResult
    {
        $this->request('GET', '/admin/login');
        $login = $this->submitForm('POST', '/admin/login', [
            'email' => $this->email,
            'password' => $this->password,
        ]);

        $dashboard = $this->request('GET', '/admin');
        if ($dashboard->finalStatus() !== 200 || ! str_contains($dashboard->body, '<title>Admin LHIE STORE</title>')) {
            throw new RuntimeException('Dogfood admin login did not produce an authenticated admin dashboard session.');
        }

        return $login;
    }

    public function submitForm(string $method, string $uri, array $fields = []): AdminDogfoodHttpResult
    {
        $method = strtoupper($method);

        if ($method !== 'GET') {
            $fields['_token'] = $this->csrfToken ?? throw new RuntimeException('Admin dogfood runner has no CSRF token. Call login() before submitting forms.');
        }

        return $this->request($method, $uri, $fields, followRedirects: true);
    }

    private function request(string $method, string $uri, array $fields = [], bool $followRedirects = false): AdminDogfoodHttpResult
    {
        $statuses = [];
        $headers = [];
        $body = '';

        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $request = Request::create($uri, $method, $fields, $this->cookies, [], [
                'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'HTTP_USER_AGENT' => 'LhieStore dogfood admin runner',
            ]);

            $response = $this->kernel->handle($request);
            $this->rememberSessionState($request, $response);
            $this->kernel->terminate($request, $response);

            $statuses[] = $response->getStatusCode();
            $headers = $response->headers->all();
            $body = (string) $response->getContent();

            $location = $response->headers->get('Location');
            if (! $followRedirects || ! $response->isRedirection() || ! is_string($location) || $location === '') {
                break;
            }

            $uri = $this->localRedirectUri($location);
            $method = 'GET';
            $fields = [];
        }

        return new AdminDogfoodHttpResult($statuses, $headers, $body);
    }

    private function rememberSessionState(Request $request, Response $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);

                continue;
            }

            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        if ($request->hasSession()) {
            $this->csrfToken = $request->session()->token();
        }
    }

    private function localRedirectUri(string $location): string
    {
        $parts = parse_url($location);

        if ($parts === false) {
            return $location;
        }

        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }
}
