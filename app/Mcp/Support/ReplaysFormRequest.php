<?php

namespace App\Mcp\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;

class ReplaysFormRequest
{
    /**
     * Replay one of the web Form Requests outside the HTTP kernel so MCP
     * tools enforce exactly the same validation and authorization as
     * their web counterpart, without touching the controllers.
     *
     * There is no route-model-binding here: callers resolve models by
     * hand and pass them as `$routeParameters` so `$request->route('x')`
     * inside `rules()`/`authorize()` keeps working. `validateResolved()`
     * throws the usual ValidationException / AuthorizationException,
     * which the caller converts into an MCP error response.
     *
     * @template TRequest of FormRequest
     *
     * @param  class-string<TRequest>  $requestClass
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $routeParameters
     * @return TRequest
     */
    public function replay(
        string $requestClass,
        array $payload,
        Authenticatable $user,
        array $routeParameters = [],
    ): FormRequest {
        $request = $requestClass::create('/', 'POST', $payload);

        $request->setContainer(app());
        $request->setRedirector(app(Redirector::class));
        $request->setUserResolver(fn (): Authenticatable => $user);

        // A synthetic bound route carrying the hand-resolved models, so
        // in-rule lookups like `$this->route('project')` behave as they
        // do on the real route.
        $route = (new Route(['POST'], '/', []))->bind($request);

        foreach ($routeParameters as $name => $value) {
            $route->setParameter($name, $value);
        }

        $request->setRouteResolver(fn (): Route => $route);

        $request->validateResolved();

        return $request;
    }
}
