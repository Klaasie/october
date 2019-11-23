<?php

namespace System\Classes\Contracts;

use System\Classes\PluginBase;

/**
 * Interface PluginManagerContract
 *
 * @package System\Classes\Contracts
 */
interface PluginManagerContract
{
    /**
     * @return self
     * @deprecated V1.0.xxx Instead of using this method,
     *                      rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): self;

    /**
     * @return bool
     */
    public function isNoInit(): bool;

    /**
     * @param bool $noInit
     * @return void
     */
    public function setNoInit(bool $noInit);

    /**
     * Finds all available plugins and loads them in to the $plugins array.
     *
     * @return array
     */
    public function loadPlugins(): array;

    /**
     * Loads a single plugin in to the manager.
     *
     * @param $namespace
     * @param $path
     * @return PluginBase|void
     * @todo Strict type!
     */
    public function loadPlugin(string $namespace, string $path);

    /**
     * Runs the register() method on all plugins. Can only be called once.
     *
     * @param bool $force
     * @return void
     */
    public function registerAll(bool $force = false);

    /**
     * Unregisters all plugins: the negative of registerAll().
     *
     * @return void
     * @todo This method is never used, perhaps remove it?
     */
    public function unregisterAll();

    /**
     * Registers a single plugin object.
     *
     * @param PluginBase $plugin
     * @param string $pluginId
     * @return void
     * @todo This method is only used by the class itself, maybe make it private?
     */
    public function registerPlugin(PluginBase $plugin, string $pluginId = null);

    /**
     * Runs the boot() method on all plugins. Can only be called once.
     *
     * @param bool $force
     * @return void
     */
    public function bootAll(bool $force = false);

    /**
     * Registers a single plugin object.
     *
     * @param PluginBase $plugin
     * @return void
     */
    public function bootPlugin(PluginBase $plugin);

    /**
     * Returns the directory path to a plugin
     *
     * @param string|PluginBase $id
     * @return string|null
     * @todo Strict type!
     */
    public function getPluginPath($id);

    /**
     * Check if a plugin exists and is enabled.
     *
     * @param string|PluginBase $id Plugin identifier, eg: Namespace.PluginName
     * @return boolean
     * @todo Strict type!
     */
    public function exists($id): bool;

    /**
     * Returns an array with all registered plugins
     * The index is the plugin namespace, the value is the plugin information object.
     *
     * @return array
     */
    public function getPlugins(): array;

    /**
     * Returns a plugin registration class based on its namespace (Author\Plugin).
     *
     * @param string|null $namespace
     * @return null|PluginBase
     * @todo This is never used? Maybe remove?
     */
    public function findByNamespace($namespace);

    /**
     * Returns a plugin registration class based on its identifier (Author.Plugin).
     *
     * @param string|null $identifier
     * @return null|PluginBase
     * @todo Strict type!
     */
    public function findByIdentifier($identifier);

    /**
     * Checks to see if a plugin has been registered.
     *
     * @param string $namespace
     * @return bool
     */
    public function hasPlugin(string $namespace): bool;

    /**
     * Returns a flat array of vendor plugin namespaces and their paths
     *
     * @return array
     */
    public function getPluginNamespaces(): array;

    /**
     * Returns a 2 dimensional array of vendors and their plugins.
     *
     * @return array
     */
    public function getVendorAndPluginNames(): array;

    /**
     * Resolves a plugin identifier from a plugin class name or object.
     *
     * @param string|PluginBase Plugin class name or object
     * @return string|null Identifier in format of Vendor.Plugin
     * @todo Strict type!
     */
    public function getIdentifier($namespace);

    /**
     * Takes a human plugin code (acme.blog) and makes it authentic (Acme.Blog)
     *
     * @param  string|null $identifier
     * @return string|null
     * @todo Strict type!
     */
    public function normalizeIdentifier($identifier);

    /**
     * Spins over every plugin object and collects the results of a method call.
     *
     * @param  string $methodName
     * @return array
     */
    public function getRegistrationMethodValues(string $methodName): array;

    /**
     * @return void
     */
    public function clearDisabledCache();

    /**
     * Determines if a plugin is disabled by looking at the meta information or the application configuration.
     * @param string $id
     * @return bool
     */
    public function isDisabled(string $id): bool;

    /**
     * Disables a single plugin in the system.
     *
     * @param string $id Plugin code/namespace
     * @param bool $isUser Set to true if disabled by the user
     * @return bool
     * @todo Strict type!
     */
    public function disablePlugin($id, $isUser = false): bool;

    /**
     * Enables a single plugin in the system.
     *
     * @param string $id Plugin code/namespace
     * @param bool $isUser Set to true if enabled by the user
     * @return bool
     * @todo Strict type!
     */
    public function enablePlugin($id, $isUser = false): bool;

    /**
     * Scans the system plugins to locate any dependencies that are not currently
     * installed. Returns an array of plugin codes that are needed.
     *
     * @return array
     */
    public function findMissingDependencies(): array;

    /**
     * Returns the plugin identifiers that are required by the supplied plugin.
     *
     * @param  string $plugin Plugin identifier, object or class
     * @return null|bool|array
     * @todo Strict type!
     */
    public function getDependencies($plugin);

    /**
     * Sorts a collection of plugins, in the order that they should be actioned,
     * according to their given dependencies. Least dependent come first.
     *
     * @param array $plugins Object collection to sort, or null to sort all.
     * @return array Collection of sorted plugin identifiers
     * @todo This method is never used by the system itself. Maybe remove?
     * @todo Strict type!
     */
    public function sortByDependencies($plugins = null): array;

    /**
     * Completely roll back and delete a plugin from the system.
     *
     * @param string $id Plugin code/namespace
     * @return void
     * @todo Strict type!
     */
    public function deletePlugin($id);

    /**
     * Tears down a plugin's database tables and rebuilds them.
     *
     * @param string $id Plugin code/namespace
     * @return void
     * @todo Strict type!
     */
    public function refreshPlugin($id);
 }
