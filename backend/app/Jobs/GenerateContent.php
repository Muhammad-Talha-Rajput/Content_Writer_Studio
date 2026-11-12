<?php
namespace App\Jobs;
use App\Models\AgentRun;
use App\Services\GenerationWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class GenerateContent implements ShouldQueue
{
    use Queueable;
    public int $tries=1;
    public int $timeout=1860;
    public function __construct(public string $runId) {}
    public function handle(GenerationWorkflow $workflow): void
    {
        if ($run=AgentRun::find($this->runId)) $workflow->execute($run);
    }
    public function failed(?\Throwable $error): void { GenerationWorkflow::finish($this->runId,'FAILED','WORKER_STOPPED','The generation worker stopped. Continue this run to reuse completed stages; earlier saved versions are preserved.'); }
}
