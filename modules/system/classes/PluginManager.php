<?php namespace System\Classes;

use ApplicationException;
use DependencyTest\NotFound\Plugin;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Log\Writer;
use October\Rain\Filesystem\Filesystem;
use October\Rain\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Schema;
use System\Classes\Contracts\ComposerManagerContract;
use System\Classes\Contracts\PluginManagerContract;
use System\Classes\Contracts\UpdateManagerContract;
use Throwable;

/**
 * Plugin manager
 *
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class PluginManager implements PluginManagerContract
{
    /**
     * @var Application The application instance, since Plugins are an extension of a Service Provider
     */
    protected $app;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Translator
     */
    private $translator;

    /**
     * @var Repository
     */
    private $config;

    /**
     * @var Factory
     */
    private $view;

    /**
     * @var ConnectionInterface
     */
    private $db;

    /**
     * @var ComposerManagerContract
     */
    private $composerManager;

    /**
     * @var Writer
     */
    private $log;

    /**
     * @var array Container object used for storing plugin information objects.
     */
    protected $plugins;

    /**
     * @var array A map of plugins and their directory paths.
     */
    protected $pathMap = [];

    /**
     * @var bool Check if all plugins have had the register() method called.
     */
    protected $registered = false;

    /**
     * @var bool Check if all plugins have had the boot() method called.
     */
    protected $booted = false;

    /**
     * @var string Path to the disarm file.
     */
    protected $metaFile;

    /**
     * @var array Collection of disabled plugins
     */
    protected $disabledPlugins = [];

    /**
     * @var array Cache of registration method results.L
     */
    protected $registrationMethodCache = [];

    /**
     * @var boolean Prevent all plugins from registering or booting
     */
    public $noInit = false;

    /**
     * PluginManager constructor.
     *
     * @param Application $app
     * @param Filesystem $filesystem
     * @param Translator $translator
     * @param Repository $config
     * @param Factory $view
     * @param ConnectionInterface $db
     * @param ComposerManagerContract $composerManager
     * @param Writer $log
     * @throws FileNotFoundException
     */
    public function __construct(
        Application $app,
        Filesystem $filesystem,
        Translator $translator,
        Repository $config,
        Factory $view,
        ConnectionInterface $db,
        ComposerManagerContract $composerManager,
        Writer $log
    ) {
        $this->app = $app;
        $this->filesystem = $filesystem;
        $this->translator = $translator;
        $this->config = $config;
        $this->view = $view;
        $this->db = $db;
        $this->composerManager = $composerManager;
        $this->log = $log;

        $this->metaFile = storage_path('cms/disabled.json');

        $this->loadDisabled();
        $this->loadPlugins();
        if ($this->app->runningInBackend()) {
            $this->loadDependencies();
        }
    }

    /**
     * Return itself
     *
     * Kept this one to remain backwards compatible.
     *
     * @return self
     * @deprecated V1.0.xxx Instead of using this method,
     *                      rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): PluginManagerContract
    {
        return resolve(self::class);
    }

    /**
     * {@inheritDoc}
     */
    public function isNoInit(): bool
    {
        return $this->noInit;
    }

    /**
     * {@inheritDoc}
     */
    public function setNoInit(bool $noInit)
    {
        $this->noInit = $noInit;
    }

    /**
     * {@inheritDoc}
     */
    public function loadPlugins(): array
    {
        $this->plugins = [];

        /**
         * Locate all plugins and binds them to the container
         */
        foreach ($this->getPluginNamespaces() as $namespace => $path) {
            $this->loadPlugin($namespace, $path);
        }

        return $this->plugins;
    }

    /**
     * {@inheritDoc}
     */
    public function loadPlugin(string $namespace, string $path)
    {
        $className = $namespace.'\Plugin';
        $classPath = $path.'/Plugin.php';

        try {
            // Autoloader failed?
            if (!class_exists($className)) {
                include_once $classPath;
            }

            // Not a valid plugin!
            if (!class_exists($className)) {
                return;
            }

            $classObj = new $className($this->app);
        } catch (Throwable $e) {
            $this->log->error('Plugin ' . $className . ' could not be instantiated.', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return;
        }

        $classId = $this->getIdentifier($classObj);

        /*
         * Check for disabled plugins
         */
        if ($this->isDisabled($classId)) {
            $classObj->disabled = true;
        }

        $this->plugins[$classId] = $classObj;
        $this->pathMap[$classId] = $path;

        return $classObj;
    }

    /**
     * {@inheritDoc}
     */
    public function registerAll(bool $force = false)
    {
        if ($this->registered && !$force) {
            return;
        }

        foreach ($this->plugins as $pluginId => $plugin) {
            $this->registerPlugin($plugin, $pluginId);
        }

        $this->registered = true;
    }

    /**
     * {@inheritDoc}
     */
    public function unregisterAll()
    {
        $this->registered = false;
        $this->plugins = [];
    }

    /**
     * {@inheritDoc}
     */
    public function registerPlugin(PluginBase $plugin, string $pluginId = null)
    {
        if (!$pluginId) {
            $pluginId = $this->getIdentifier($plugin);
        }

        // @todo This should throw an exception.
        if (!$plugin) {
            return;
        }

        $pluginPath = $this->getPluginPath($plugin);
        $pluginNamespace = strtolower($pluginId);

        /*
         * Register language namespaces
         */
        $langPath = $pluginPath . '/lang';
        if ($this->filesystem->isDirectory($langPath)) {
            $this->translator->addNamespace($pluginNamespace, $langPath);
        }

        // @todo Exception or Notify?
        if ($plugin->disabled) {
            return;
        }

        /*
         * Register plugin class autoloaders
         */
        $autoloadPath = $pluginPath . '/vendor/autoload.php';
        if ($this->filesystem->isFile($autoloadPath)) {
            $this->composerManager->autoload($pluginPath . '/vendor');
        }

        if (!$this->isNoInit() || $plugin->elevated) {
            $plugin->register();
        }

        /*
         * Register configuration path
         */
        $configPath = $pluginPath . '/config';
        if ($this->filesystem->isDirectory($configPath)) {
            $this->config->package($pluginNamespace, $configPath, $pluginNamespace);
        }

        /*
         * Register views path
         */
        $viewsPath = $pluginPath . '/views';
        if ($this->filesystem->isDirectory($viewsPath)) {
            $this->view->addNamespace($pluginNamespace, $viewsPath);
        }

        /*
         * Add init, if available
         */
        $initFile = $pluginPath . '/init.php';
        if (!$this->isNoInit() && $this->filesystem->exists($initFile)) {
            require $initFile;
        }

        /*
         * Add routes, if available
         */
        $routesFile = $pluginPath . '/routes.php';
        if ($this->filesystem->exists($routesFile)) {
            require $routesFile;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function bootAll(bool $force = false)
    {
        if ($this->booted && !$force) {
            return;
        }

        foreach ($this->plugins as $plugin) {
            $this->bootPlugin($plugin);
        }

        $this->booted = true;
    }

    /**
     * {@inheritDoc}
     */
    public function bootPlugin(PluginBase $plugin)
    {
        if (!$plugin || $plugin->disabled) {
            return;
        }

        if ($plugin->elevated || !$this->isNoInit()) {
            $plugin->boot();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getPluginPath($id)
    {
        $classId = $this->getIdentifier($id);
        if (!isset($this->pathMap[$classId])) {
            return null;
        }

        return $this->filesystem->normalizePath($this->pathMap[$classId]);
    }

    /**
     * {@inheritDoc}
     */
    public function exists($id): bool
    {
        return !(!$this->findByIdentifier($id) || $this->isDisabled($id));
    }

    /**
     * {@inheritDoc}
     */
    public function getPlugins(): array
    {
        return array_diff_key($this->plugins, $this->disabledPlugins);
    }

    /**
     * {@inheritDoc}
     */
    public function findByNamespace($namespace)
    {
        if (!$this->hasPlugin($namespace)) {
            return null;
        }

        $classId = $this->getIdentifier($namespace);

        return $this->plugins[$classId];
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdentifier($identifier)
    {
        if (!isset($this->plugins[$identifier])) {
            $identifier = $this->normalizeIdentifier($identifier);
        }

        if (!isset($this->plugins[$identifier])) {
            return null;
        }

        return $this->plugins[$identifier];
    }

    /**
     * {@inheritDoc}
     */
    public function hasPlugin(string $namespace): bool
    {
        $classId = $this->getIdentifier($namespace);

        $normalized = $this->normalizeIdentifier($classId);

        return isset($this->plugins[$normalized]);
    }

    /**
     * {@inheritDoc}
     */
    public function getPluginNamespaces(): array
    {
        $classNames = [];

        foreach ($this->getVendorAndPluginNames() as $vendorName => $vendorList) {
            foreach ($vendorList as $pluginName => $pluginPath) {
                $namespace = '\\'.$vendorName.'\\'.$pluginName;
                $namespace = Str::normalizeClassName($namespace);
                $classNames[$namespace] = $pluginPath;
            }
        }

        return $classNames;
    }

    /**
     * {@inheritDoc}
     */
    public function getVendorAndPluginNames(): array
    {
        $plugins = [];

        $dirPath = plugins_path();
        if (!$this->filesystem->isDirectory($dirPath)) {
            return $plugins;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::FOLLOW_SYMLINKS)
        );
        $it->setMaxDepth(2);
        $it->rewind();

        while ($it->valid()) {
            if (($it->getDepth() > 1) && $it->isFile() && (strtolower($it->getFilename()) == "plugin.php")) {
                $filePath = dirname($it->getPathname());
                $pluginName = basename($filePath);
                $vendorName = basename(dirname($filePath));
                $plugins[$vendorName][$pluginName] = $filePath;
            }

            $it->next();
        }

        return $plugins;
    }

    /**
     * {@inheritDoc}
     */
    public function getIdentifier($namespace)
    {
        $namespace = Str::normalizeClassName($namespace);
        if (strpos($namespace, '\\') === null) {
            return $namespace;
        }

        $parts = explode('\\', $namespace);
        $slice = array_slice($parts, 1, 2);
        $namespace = implode('.', $slice);

        return $namespace;
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeIdentifier($identifier)
    {
        foreach ($this->plugins as $id => $object) {
            if (strtolower($id) === strtolower($identifier)) {
                return $id;
            }
        }

        return $identifier;
    }

    /**
     * {@inheritDoc}
     */
    public function getRegistrationMethodValues(string $methodName): array
    {
        if (isset($this->registrationMethodCache[$methodName])) {
            return $this->registrationMethodCache[$methodName];
        }

        $results = [];
        $plugins = $this->getPlugins();

        foreach ($plugins as $id => $plugin) {
            if (!method_exists($plugin, $methodName)) {
                continue;
            }

            $results[$id] = $plugin->{$methodName}();
        }

        return $this->registrationMethodCache[$methodName] = $results;
    }

    //
    // Disability
    //

    /**
     * {@inheritDoc}
     */
    public function clearDisabledCache()
    {
        $this->filesystem->delete($this->metaFile);
        $this->disabledPlugins = [];
    }

    /**
     * Loads all disables plugins from the meta file.
     *
     * @return void
     * @throws FileNotFoundException
     */
    protected function loadDisabled()
    {
        $path = $this->metaFile;

        if (($configDisabled = $this->config->get('cms.disablePlugins')) && is_array($configDisabled)) {
            foreach ($configDisabled as $disabled) {
                $this->disabledPlugins[$disabled] = true;
            }
        }

        if ($this->filesystem->exists($path)) {
            $disabled = json_decode($this->filesystem->get($path), true) ?: [];
            $this->disabledPlugins = array_merge($this->disabledPlugins, $disabled);
        }
        else {
            $this->populateDisabledPluginsFromDb();
            $this->writeDisabled();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isDisabled(string $id): bool
    {
        $code = $this->getIdentifier($id);

        if (array_key_exists($code, $this->disabledPlugins)) {
            return true;
        }

        return false;
    }

    /**
     * Write the disabled plugins to a meta file.
     *
     * @return void
     */
    protected function writeDisabled()
    {
        $this->filesystem->put($this->metaFile, json_encode($this->disabledPlugins));
    }

    /**
     * Populates information about disabled plugins from database
     *
     * @return void
     */
    protected function populateDisabledPluginsFromDb()
    {
        if (!$this->app->hasDatabase()) {
            return;
        }

        if (!Schema::hasTable('system_plugin_versions')) {
            return;
        }

        $disabled = $this->db->table('system_plugin_versions')->where('is_disabled', 1)->lists('code');

        foreach ($disabled as $code) {
            $this->disabledPlugins[$code] = true;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function disablePlugin($id, $isUser = false): bool
    {
        $code = $this->getIdentifier($id);
        if (array_key_exists($code, $this->disabledPlugins)) {
            return false;
        }

        $this->disabledPlugins[$code] = $isUser;
        $this->writeDisabled();

        if ($pluginObj = $this->findByIdentifier($code)) {
            $pluginObj->disabled = true;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function enablePlugin($id, $isUser = false): bool
    {
        $code = $this->getIdentifier($id);
        if (!array_key_exists($code, $this->disabledPlugins)) {
            return false;
        }

        // Prevent system from enabling plugins disabled by the user
        if (!$isUser && $this->disabledPlugins[$code] === true) {
            return false;
        }

        unset($this->disabledPlugins[$code]);
        $this->writeDisabled();

        if ($pluginObj = $this->findByIdentifier($code)) {
            $pluginObj->disabled = false;
        }

        return true;
    }

    //
    // Dependencies
    //

    /**
     * {@inheritDoc}
     */
    public function findMissingDependencies(): array
    {
        $missing = [];

        foreach ($this->plugins as $id => $plugin) {
            if (!$required = $this->getDependencies($plugin)) {
                continue;
            }

            foreach ($required as $require) {
                if ($this->hasPlugin($require)) {
                    continue;
                }

                // @todo true?
                if (!in_array($require, $missing)) {
                    $missing[] = $require;
                }
            }
        }

        return $missing;
    }

    /**
     * Cross checks all plugins and their dependencies, if not met plugins
     * are disabled and vice versa.
     * @return void
     */
    protected function loadDependencies()
    {
        foreach ($this->plugins as $id => $plugin) {
            if (!$required = $this->getDependencies($plugin)) {
                continue;
            }

            $disable = false;

            foreach ($required as $require) {
                if (!$pluginObj = $this->findByIdentifier($require)) {
                    $disable = true;
                }
                elseif ($pluginObj->disabled) {
                    $disable = true;
                }
            }

            if ($disable) {
                $this->disablePlugin($id);
            }
            else {
                $this->enablePlugin($id);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getDependencies($plugin)
    {
        $pluginObj = null;
        if (is_string($plugin)) {
            $pluginObj = $this->findByIdentifier($plugin);
            if (!$pluginObj instanceof PluginBase) {
                return false;
            }
        }

        if ($plugin instanceof PluginBase) {
            $pluginObj = $plugin;
        }

        if (!isset($pluginObj->require) || !$pluginObj->require) {
            return null;
        }

        return is_array($pluginObj->require) ? $pluginObj->require : [$pluginObj->require];
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function sortByDependencies($plugins = null): array
    {
        if (!is_array($plugins)) {
            $plugins = $this->getPlugins();
        }

        $result = [];
        $checklist = $plugins;

        $loopCount = 0;
        while (count($checklist)) {
            if (++$loopCount > 999) {
                throw new ApplicationException('Too much recursion');
            }

            foreach ($checklist as $code => $plugin) {
                /*
                 * Get dependencies and remove any aliens
                 */
                $depends = $this->getDependencies($plugin) ?: [];
                $depends = array_filter($depends, function ($pluginCode) use ($plugins) {
                    return isset($plugins[$pluginCode]);
                });

                /*
                 * No dependencies
                 */
                if (!$depends) {
                    $result[] = $code;
                    unset($checklist[$code]);
                    continue;
                }

                /*
                 * Find dependencies that have not been checked
                 */
                $depends = array_diff($depends, $result);
                if (count($depends) > 0) {
                    continue;
                }

                /*
                 * All dependencies are checked
                 */
                $result[] = $code;
                unset($checklist[$code]);
            }
        }
        return $result;
    }

    //
    // Management
    //

    /**
     * {@inheritDoc}
     */
    public function deletePlugin($id)
    {
        /*
         * Rollback plugin
         */
        // Instantiating separately here since the UpdateManager and PluginManager can't depend on each other.
        // We should probably look into the opportunity to separate logic to avoid this case.
        /** @var UpdateManagerContract $updateManager */
        $updateManager = resolve(UpdateManagerContract::class);
        $updateManager->rollbackPlugin($id);

        /*
         * Delete from file system
         */
        if ($pluginPath = $this->getPluginPath($id)) {
            $this->filesystem->deleteDirectory($pluginPath);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function refreshPlugin($id)
    {
        // Instantiating separately here since the UpdateManager and PluginManager can't depend on each other.
        // We should probably look into the opportunity to separate logic to avoid this case.
        /** @var UpdateManagerContract $updateManager */
        $updateManager = resolve(UpdateManagerContract::class);
        $updateManager->rollbackPlugin($id);
        $updateManager->updatePlugin($id);
    }
}
