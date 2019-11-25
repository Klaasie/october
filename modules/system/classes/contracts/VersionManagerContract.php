<?php namespace System\Classes\Contracts;

use Illuminate\Console\Command;
use System\Classes\PluginBase;

/**
 * Interface VersionManagerContract
 *
 * @package System\Classes\Contracts
 */
interface VersionManagerContract
{
    /**
     * @return self
     * @deprecated V1.0.xxx Instead of using this method,
     *                      rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): self;

    /**
     * Updates a single plugin by its code or object with it's latest changes.
     * If the $stopOnVersion parameter is specified, the process stops after
     * the specified version is applied.
     * @param string|PluginBase $plugin
     * @param null $stopOnVersion
     * @return bool
     * @todo Make more strict
     */
    public function updatePlugin($plugin, $stopOnVersion = null): bool;

    /**
     * Returns a list of plugin versions to be applied.
     *
     * @param string|PluginBase $plugin
     * @return array
     * @todo Never used?
     */
    public function listNewVersions($plugin): array;

    /**
     * Removes and packs down a plugin from the system. Files are left intact.
     * If the $stopOnVersion parameter is specified, the process stops after
     * the specified version is rolled back.
     *
     * @param string|PluginBase $plugin
     * @param string|null $stopOnVersion
     * @return bool
     * @todo make more strict?
     */
    public function removePlugin($plugin, $stopOnVersion = null): bool;

    /**
     * Deletes all records from the version and history tables for a plugin.
     *
     * @param string $pluginCode
     * @return bool
     */
    public function purgePlugin(string $pluginCode): bool;

    /**
     * Get the notes for the last operation.
     *
     * @return array
     */
    public function getNotes(): array;

    /**
     * Resets the notes store.
     *
     * @return self
     */
    public function resetNotes(): VersionManagerContract;

    /**
     * Sets an output stream for writing notes.
     *
     * @param  Command $output
     * @return self
     */
    public function setNotesOutput($output): VersionManagerContract;
}
