<?php

namespace OctopusLLM\Gateway\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class OctopusRecover extends BaseCommand
{
    protected $group = 'Octopus';
    protected $name = 'octopus:recover';
    protected $description = 'Ping all inactive keys that have passed cooldown and reactivate them if healthy.';

    public function run(array $params)
    {
        // User harus pass instance OctopusLLM via Services
        $octopus = \Config\Services::octopus();
        $report = $octopus->runRecovery();

        CLI::write('Recovery complete.', 'green');
        CLI::write('Total pinged : ' . $report->total);
        CLI::write('Recovered    : ' . count($report->recovered));
        CLI::write('Still failed : ' . count($report->failed));

        if (!empty($report->recovered)) {
            CLI::write('Recovered keys:', 'green');
            foreach ($report->recovered as $item) {
                CLI::write('  - ' . $item['provider'] . ' #' . $item['keyIndex']);
            }
        }

        if (!empty($report->failed)) {
            CLI::write('Still inactive:', 'yellow');
            foreach ($report->failed as $item) {
                CLI::write('  - ' . $item['provider'] . ' #' . $item['keyIndex']);
            }
        }
    }
}
