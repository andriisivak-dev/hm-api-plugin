<?php

declare(strict_types=1);

namespace CSP\API\Middleware;

use WP_REST_Request;

class MiddlewarePipeline
{
    private array $middlewares = [];

    public function pipe(callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function process(WP_REST_Request $request, callable $destination)
    {
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            function ($next, $middleware) {
                return function ($request) use ($middleware, $next) {
                    return $middleware($request, $next);
                };
            },
            $destination
        );

        return $pipeline($request);
    }
}