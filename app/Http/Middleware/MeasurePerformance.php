<?php
namespace App\Http\Middleware;
use App\Support\PerformanceMeter;
use Closure;
use Illuminate\Http\Request;

final class MeasurePerformance
{
    public function __construct(private readonly PerformanceMeter $meter) {}
    public function handle(Request $request, Closure $next)
    {
        $this->meter->begin($request->path());
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            return $response;
        } finally {
            $this->meter->finish($status);
        }
    }
}
