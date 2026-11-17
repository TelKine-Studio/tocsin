<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * Production runs `artisan optimize`: cached routes are matched by the first
 * route that fits, while uncached ones keep the last one registered for a
 * method. Two routes on the same URI and method behave differently in each
 * case (a Route::redirect() answering every method swallowed POST forms).
 */
class RouteCacheTest extends TestCase
{
    public function test_no_two_routes_share_a_uri_and_a_method(): void
    {
        $seen = [];
        $duplicates = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' '.($route->getDomain() ?? '').'/'.ltrim($route->uri(), '/');
                if (isset($seen[$key])) {
                    $duplicates[] = $key;
                }
                $seen[$key] = true;
            }
        }

        $this->assertSame([], $duplicates, 'Routes registered twice for the same method and URI.');
    }

    public function test_cached_routes_send_forms_to_their_controllers(): void
    {
        $router = app('router');
        $routes = $router->getRoutes();
        foreach ($routes as $route) {
            /** @var Route $route */
            $route->prepareForSerialization();
        }
        $compiled = $routes->compile();
        $cached = (new CompiledRouteCollection($compiled['compiled'], $compiled['attributes']))
            ->setRouter($router)
            ->setContainer(app());

        foreach ([
            ['POST', '/admin/invitations', 'admin.invitations.store'],
            ['GET', '/admin/invitations', 'admin.invitations.index'],
            ['POST', '/', 'ntfy.json'],
        ] as [$method, $uri, $name]) {
            $this->assertSame($name, $cached->match(Request::create($uri, $method))->getName(), "$method $uri");
        }
    }
}
