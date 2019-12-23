<?php namespace System\Console;

use Illuminate\Console\Command;
use System\Classes\Contracts\PluginManagerContract;
use System\Classes\Contracts\UpdateManagerContract;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Console command to refresh a plugin.
 *
 * This destroys all database tables for a specific plugin, then builds them up again.
 * It is a great way for developers to debug and develop new plugins.
 *
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class PluginRefresh extends Command
{

    /**
     * The console command name.
     * @var string
     */
    protected $name = 'plugin:refresh';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Removes and re-adds an existing plugin.';

    /**
     * Execute the console command.
     * @return void
     */
    public function handle()
    {
        /*
         * Lookup plugin
         */
        $pluginName = $this->argument('name');
        /** @var PluginManagerContract $pluginManager */
        $pluginManager = resolve(PluginManagerContract::class);
        $pluginName = $pluginManager->normalizeIdentifier($pluginName);
        if (!$pluginManager->exists($pluginName)) {
            throw new \InvalidArgumentException(sprintf('Plugin "%s" not found.', $pluginName));
        }

        /** @var UpdateManagerContract $updateManager */
        $updateManager = resolve(UpdateManagerContract::class);
        $manager = $updateManager->setNotesOutput($this->output);

        /*
         * Rollback plugin
         */
        $manager->rollbackPlugin($pluginName);

        /*
         * Update plugin
         */
        $this->output->writeln('<info>Reinstalling plugin...</info>');
        $manager->updatePlugin($pluginName);
    }

    /**
     * Get the console command arguments.
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of the plugin. Eg: AuthorName.PluginName'],
        ];
    }
}
