<?php

namespace App\Console\Commands\Agent;

use App\Models\Agent;
use Illuminate\Console\Command;

/**
 * `php artisan mymate:agent:create "Site A"` - enrol a remote agent and print its bearer
 * token. The token is shown ONCE (only its hash is stored); configure the agent with it.
 */
class CreateAgentCommand extends Command
{
    protected $signature = 'mymate:agent:create {name : A label for the agent, e.g. the site name}';

    protected $description = 'Enrol a remote agent (probe) and print its connection token.';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        if ($name === '') {
            $this->error('A name is required.');

            return self::FAILURE;
        }

        [$agent, $token] = Agent::enrol($name);

        $this->newLine();
        $this->info("Agent enrolled: {$agent->name} (#{$agent->id})");
        $this->newLine();
        $this->line('  <options=bold>Token</> (shown once - configure the agent with it):');
        $this->line("  {$token}");
        $this->newLine();
        $this->line('  On the agent host, set:');
        $this->line('    MYMATE_URL=https://<this-server>');
        $this->line("    MYMATE_AGENT_TOKEN={$token}");
        $this->newLine();

        return self::SUCCESS;
    }
}
