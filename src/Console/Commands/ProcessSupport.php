<?php

namespace KraftInk\Plugins\Support\Console\Commands;

use App\Kraft\System\OperationLock;
use Illuminate\Console\Command;
use KraftInk\Plugins\Support\Services\SupportWorkflow;

class ProcessSupport extends Command
{
    protected $signature = 'support:process {--limit=100}';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('support::admin.operations.process_description'));
    }

    public function handle(SupportWorkflow $workflow): int
    {
        return app(OperationLock::class)->shared(function () use ($workflow): int {
            $this->line((string) $workflow->run((int) $this->option('limit')));

            return self::SUCCESS;
        });
    }
}
