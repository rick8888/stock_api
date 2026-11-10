<?php

namespace App\Console\Commands;

use App\Services\AlertEngine;
use Illuminate\Console\Command;

class RunOpsAlerts extends Command
{
    protected $signature = 'ops:alerts';

    protected $description = 'Génère les alertes stock bas, commandes en retard et livraisons non démarrées';

    public function handle(AlertEngine $engine): int
    {
        $result = $engine->run();
        $this->info("Alertes créées : {$result['created']} · ignorées (déjà présentes) : {$result['skipped']}");

        return self::SUCCESS;
    }
}
