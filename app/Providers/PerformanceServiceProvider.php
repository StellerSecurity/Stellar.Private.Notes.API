<?php
namespace App\Providers;
use App\Support\PerformanceMeter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

final class PerformanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PerformanceMeter::class, fn () => new PerformanceMeter(static function (array $data): void {
            // Dedicated private rotating log; never send note data to third-party telemetry.
            $file = storage_path('logs/notes-performance-' . date('Y-m-d') . '.log');
            clearstatcache(true, $file);
            if (is_file($file) && filesize($file) >= 32 * 1024 * 1024) return;
            Log::build(['driver'=>'daily', 'path'=>storage_path('logs/notes-performance.log'), 'days'=>7,
                'level'=>'info', 'permission'=>0600, 'locking'=>true])->info('notes_performance', ['service'=>'core'] + $data);
        }));
    }

    public function boot(): void
    {
        Event::listen(QueryExecuted::class, function (QueryExecuted $event): void {
            $this->app->make(PerformanceMeter::class)->query((float)$event->time, $event->sql);
        });
        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            $stats = $event->response->handlerStats();
            $this->app->make(PerformanceMeter::class)->upstream(1000 * (float)($stats['total_time'] ?? 0), $event->response->status());
        });
        Event::listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            // Failed connections do not reliably expose transfer timing. Status zero is explicit.
            $this->app->make(PerformanceMeter::class)->upstream(0, 0);
        });
    }
}
