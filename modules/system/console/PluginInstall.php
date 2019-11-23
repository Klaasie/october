<?php namespace System\Console;

use Illuminate\Console\Command;
use System\Classes\Contracts\PluginManagerContract;
use System\Classes\Contracts\UpdateManagerContract;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Console command to install a new plugin.
 *
 * This adds a new plugin by requesting it from the October marketplace.
 *
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class PluginInstall extends Command
{

    /**
     * The console command name.
     * @var string
     */
    protected $name = 'plugin:install';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Install a plugin from the October marketplace.';

    /**
     * Execute the console command.
     * @return void
     */
    public function handle()
    {
        $pluginName = $this->argument('name');

        /** @var UpdateManagerContract $updateManager */
        $updateManager = resolve(UpdateManagerContract::class);
        $manager = $updateManager->setNotesOutput($this->output);

        $pluginDetails = $manager->requestPluginDetails($pluginName);

        $code = array_get($pluginDetails, 'code');
        $hash = array_get($pluginDetails, 'hash');

        $this->output->writeln(sprintf('<info>Downloading plugin: %s</info>', $code));
        $manager->downloadPlugin($code, $hash, true);

        $this->output->writeln(sprintf('<info>Unpacking plugin: %s</info>', $code));
        $manager->extractPlugin($code, $hash);

        /*
         * Migrate plugin
         */
        $this->output->writeln(sprintf('<info>Migrating plugin...</info>', $code));
        /** @var PluginManagerContract $pluginManager */
        $pluginManager = resolve(PluginManagerContract::class);
        $pluginManager->loadPlugins();
        $manager->updatePlugin($code);
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
