<?php namespace System\Classes;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\ConnectionInterface;
use October\Rain\Filesystem\Filesystem;
use October\Rain\Parse\Yaml;
use System\Classes\Contracts\PluginManagerContract;
use System\Classes\Contracts\VersionManagerContract;
use Carbon\Carbon;
use October\Rain\Database\Updater;

/**
 * Version manager
 *
 * Manages the versions and database updates for plugins.
 *
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class VersionManager implements VersionManagerContract
{
    /**
     * Value when no updates are found.
     */
    const NO_VERSION_VALUE = 0;

    /**
     * Morph types for history table.
     */
    const HISTORY_TYPE_COMMENT = 'comment';
    const HISTORY_TYPE_SCRIPT = 'script';

    /**
     * The notes for the current operation.
     * @var array
     */
    protected $notes = [];

    /**
     * @var OutputStyle
     */
    protected $notesOutput;

    /**
     * Cache of plugin versions as files.
     */
    protected $fileVersions;

    /**
     * Cache of database versions
     */
    protected $databaseVersions;

    /**
     * Cache of database history
     */
    protected $databaseHistory;

    /**
     * @var Updater
     */
    protected $updater;

    /**
     * @var PluginManagerContract
     */
    protected $pluginManager;

    /**
     * @var ConnectionInterface
     */
    private $db;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Yaml
     */
    private $yaml;

    /**
     * VersionManager constructor.
     *
     * @param PluginManagerContract $pluginManager
     * @param ConnectionInterface $db
     * @param Filesystem $filesystem
     */
    public function __construct(PluginManagerContract $pluginManager, ConnectionInterface $db, Filesystem $filesystem)
    {
        $this->pluginManager = $pluginManager;
        $this->db = $db;
        $this->filesystem = $filesystem;
        $this->yaml = resolve('parse.yaml');

        $this->updater = new Updater;
    }

    /**
     * {@inheritDoc}
     */
    public static function instance(): VersionManagerContract
    {
        return resolve(self::class);
    }

    /**
     * {@inheritDoc}
     */
    public function updatePlugin($plugin, $stopOnVersion = null): bool
    {
        $code = is_string($plugin) ? $plugin : $this->pluginManager->getIdentifier($plugin);

        if (!$this->hasVersionFile($code)) {
            return false;
        }

        $currentVersion = $this->getLatestFileVersion($code);
        $databaseVersion = $this->getDatabaseVersion($code);

        // No updates needed
        if ($currentVersion === $databaseVersion) {
            $this->note('- <info>Nothing to update.</info>');
            return true;
        }

        $newUpdates = $this->getNewFileVersions($code, $databaseVersion);

        foreach ($newUpdates as $version => $details) {
            $this->applyPluginUpdate($code, $version, $details);

            if ($stopOnVersion === $version) {
                return true;
            }
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function listNewVersions($plugin): array
    {
        $code = is_string($plugin) ? $plugin : $this->pluginManager->getIdentifier($plugin);

        if (!$this->hasVersionFile($code)) {
            return [];
        }

        $databaseVersion = $this->getDatabaseVersion($code);
        return $this->getNewFileVersions($code, $databaseVersion);
    }

    /**
     * Applies a single version update to a plugin.
     *
     * @param $code
     * @param $version
     * @param $details
     * @return void
     * @todo Type hints!
     */
    protected function applyPluginUpdate($code, $version, $details)
    {
        list($comments, $scripts) = $this->extractScriptsAndComments($details);

        /*
         * Apply scripts, if any
         */
        foreach ($scripts as $script) {
            if ($this->hasDatabaseHistory($code, $version, $script)) {
                continue;
            }

            $this->applyDatabaseScript($code, $version, $script);
        }

        /*
         * Register the comment and update the version
         */
        if (!$this->hasDatabaseHistory($code, $version)) {
            foreach ($comments as $comment) {
                $this->applyDatabaseComment($code, $version, $comment);

                $this->note(sprintf('- <info>v%s: </info> %s', $version, $comment));
            }
        }

        $this->setDatabaseVersion($code, $version);
    }

    /**
     * {@inheritDoc}
     */
    public function removePlugin($plugin, $stopOnVersion = null): bool
    {
        $code = is_string($plugin) ? $plugin : $this->pluginManager->getIdentifier($plugin);

        if (!$this->hasVersionFile($code)) {
            return false;
        }

        $pluginHistory = $this->getDatabaseHistory($code);
        $pluginHistory = array_reverse($pluginHistory);

        $stopOnNextVersion = false;
        $newPluginVersion = null;

        foreach ($pluginHistory as $history) {
            if ($stopOnNextVersion && $history->version !== $stopOnVersion) {
                // Stop if the $stopOnVersion value was found and
                // this is a new version. The history could contain
                // multiple items for a single version (comments and scripts).
                $newPluginVersion = $history->version;
                break;
            }

            if ($history->type === self::HISTORY_TYPE_COMMENT) {
                $this->removeDatabaseComment($code, $history->version);
            }
            elseif ($history->type === self::HISTORY_TYPE_SCRIPT) {
                $this->removeDatabaseScript($code, $history->version, $history->detail);
            }

            if ($stopOnVersion === $history->version) {
                $stopOnNextVersion = true;
            }
        }

        $this->setDatabaseVersion($code, $newPluginVersion);

        if (isset($this->fileVersions[$code])) {
            unset($this->fileVersions[$code]);
        }
        if (isset($this->databaseVersions[$code])) {
            unset($this->databaseVersions[$code]);
        }
        if (isset($this->databaseHistory[$code])) {
            unset($this->databaseHistory[$code]);
        }
        return true;
    }

    /**
     * Deletes all records from the version and history tables for a plugin.
     *
     * @param string $pluginCode
     * @return bool
     */
    public function purgePlugin(string $pluginCode): bool
    {
        $versions = $this->db->table('system_plugin_versions')->where('code', $pluginCode);
        if ($countVersions = $versions->count()) {
            $versions->delete();
        }

        $history = $this->db->table('system_plugin_history')->where('code', $pluginCode);
        if ($countHistory = $history->count()) {
            $history->delete();
        }

        return ($countHistory + $countVersions) > 0;
    }

    //
    // File representation
    //

    /**
     * Returns the latest version of a plugin from its version file.
     *
     * @param $code
     * @return int|string
     */
    protected function getLatestFileVersion($code)
    {
        $versionInfo = $this->getFileVersions($code);
        if (!$versionInfo) {
            return self::NO_VERSION_VALUE;
        }

        return trim(key(array_slice($versionInfo, -1, 1)));
    }

    /**
     * Returns any new versions from a supplied version, ie. unapplied versions.
     *
     * @param $code
     * @param null $version
     * @return array
     */
    protected function getNewFileVersions($code, $version = null)
    {
        if ($version === null) {
            $version = self::NO_VERSION_VALUE;
        }

        $versions = $this->getFileVersions($code);
        $position = array_search($version, array_keys($versions));
        return array_slice($versions, ++$position);
    }

    /**
     * Returns all versions of a plugin from its version file.
     *
     * @param $code
     * @return array
     */
    protected function getFileVersions($code): array
    {
        if ($this->fileVersions !== null && array_key_exists($code, $this->fileVersions)) {
            return $this->fileVersions[$code];
        }

        $versionFile = $this->getVersionFile($code);
        $versionInfo = $this->yaml->parseFile($versionFile);

        if (!is_array($versionInfo)) {
            $versionInfo = [];
        }

        if ($versionInfo) {
            uksort($versionInfo, function ($a, $b) {
                return version_compare($a, $b);
            });
        }

        return $this->fileVersions[$code] = $versionInfo;
    }

    /**
     * Returns the absolute path to a version file for a plugin.
     *
     * @param $code
     * @return string
     */
    protected function getVersionFile($code): string
    {
        return $this->pluginManager->getPluginPath($code) . '/updates/version.yaml';
    }

    /**
     * Checks if a plugin has a version file.
     *
     * @param $code
     * @return bool
     */
    protected function hasVersionFile($code): bool
    {
        $versionFile = $this->getVersionFile($code);
        return $this->filesystem->isFile($versionFile);
    }

    //
    // Database representation
    //

    /**
     * Returns the latest version of a plugin from the database.
     *
     * @param $code
     * @return int|string
     */
    protected function getDatabaseVersion($code)
    {
        if ($this->databaseVersions === null) {
            $this->databaseVersions = $this->db->table('system_plugin_versions')->lists('version', 'code');
        }

        if (!isset($this->databaseVersions[$code])) {
            $this->databaseVersions[$code] = $this->db->table('system_plugin_versions')
                ->where('code', $code)
                ->value('version')
            ;
        }

        return $this->databaseVersions[$code] ?? self::NO_VERSION_VALUE;
    }

    /**
     * Updates a plugin version in the database.
     *
     * @param $code
     * @param null $version
     * @return void
     */
    protected function setDatabaseVersion($code, $version = null)
    {
        $currentVersion = $this->getDatabaseVersion($code);

        if ($version && !$currentVersion) {
            $this->db->table('system_plugin_versions')->insert([
                'code' => $code,
                'version' => $version,
                'created_at' => new Carbon
            ]);
        }
        elseif ($version && $currentVersion) {
            $this->db->table('system_plugin_versions')->where('code', $code)->update([
                'version' => $version,
                'created_at' => new Carbon
            ]);
        }
        elseif ($currentVersion) {
            $this->db->table('system_plugin_versions')->where('code', $code)->delete();
        }

        $this->databaseVersions[$code] = $version;
    }

    /**
     * Registers a database update comment in the history table.
     *
     * @param $code
     * @param $version
     * @param $comment
     * @return void
     */
    protected function applyDatabaseComment($code, $version, $comment)
    {
        $this->db->table('system_plugin_history')->insert([
            'code' => $code,
            'type' => self::HISTORY_TYPE_COMMENT,
            'version' => $version,
            'detail' => $comment,
            'created_at' => new Carbon
        ]);
    }

    /**
     * Removes a database update comment in the history table.
     *
     * @param $code
     * @param $version
     * @return void
     */
    protected function removeDatabaseComment($code, $version)
    {
        $this->db->table('system_plugin_history')
            ->where('code', $code)
            ->where('type', self::HISTORY_TYPE_COMMENT)
            ->where('version', $version)
            ->delete();
    }

    /**
     * Registers a database update script in the history table.
     *
     * @param $code
     * @param $version
     * @param $script
     * @return void
     */
    protected function applyDatabaseScript($code, $version, $script)
    {
        /*
         * Execute the database PHP script
         */
        $updateFile = $this->pluginManager->getPluginPath($code) . '/updates/' . $script;

        if (!$this->filesystem->isFile($updateFile)) {
            $this->note('- <error>v' . $version . ':  Migration file "' . $script . '" not found</error>');
            return;
        }

        $this->updater->setUp($updateFile);

        $this->db->table('system_plugin_history')->insert([
            'code' => $code,
            'type' => self::HISTORY_TYPE_SCRIPT,
            'version' => $version,
            'detail' => $script,
            'created_at' => new Carbon
        ]);
    }

    /**
     * Removes a database update script in the history table.
     *
     * @param $code
     * @param $version
     * @param $script
     * @return void
     */
    protected function removeDatabaseScript($code, $version, $script)
    {
        /*
         * Execute the database PHP script
         */
        $updateFile = $this->pluginManager->getPluginPath($code) . '/updates/' . $script;
        $this->updater->packDown($updateFile);

        $this->db->table('system_plugin_history')
            ->where('code', $code)
            ->where('type', self::HISTORY_TYPE_SCRIPT)
            ->where('version', $version)
            ->where('detail', $script)
            ->delete();
    }

    /**
     * Returns all the update history for a plugin.
     *
     * @param $code
     * @return array
     */
    protected function getDatabaseHistory($code): array
    {
        if ($this->databaseHistory !== null && array_key_exists($code, $this->databaseHistory)) {
            return $this->databaseHistory[$code];
        }

        $historyInfo = $this->db->table('system_plugin_history')
            ->where('code', $code)
            ->orderBy('id')
            ->get()
            ->all();

        return $this->databaseHistory[$code] = $historyInfo;
    }

    /**
     * Checks if a plugin has an applied update version.
     *
     * @param $code
     * @param $version
     * @param null $script
     * @return bool
     */
    protected function hasDatabaseHistory($code, $version, $script = null): bool
    {
        $historyInfo = $this->getDatabaseHistory($code);
        if (!$historyInfo) {
            return false;
        }

        foreach ($historyInfo as $history) {
            if ($history->version != $version) {
                continue;
            }

            if ($history->type == self::HISTORY_TYPE_COMMENT && !$script) {
                return true;
            }

            if ($history->type == self::HISTORY_TYPE_SCRIPT && $history->detail == $script) {
                return true;
            }
        }

        return false;
    }

    //
    // Notes
    //

    /**
     * Raise a note event for the migrator.
     *
     * @param  string  $message
     * @return self
     */
    protected function note($message): VersionManagerContract
    {
        if ($this->notesOutput !== null) {
            $this->notesOutput->writeln($message);
        }
        else {
            $this->notes[] = $message;
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getNotes(): array
    {
        return $this->notes;
    }

    /**
     * {@inheritDoc}
     */
    public function resetNotes(): VersionManagerContract
    {
        $this->notesOutput = null;

        $this->notes = [];

        return $this;
    }

    /**
     * Sets an output stream for writing notes.
     *
     * @param  Command $output
     * @return self
     */
    public function setNotesOutput($output): VersionManagerContract
    {
        $this->notesOutput = $output;

        return $this;
    }

    /**
     * @param $details
     * @return array
     */
    protected function extractScriptsAndComments($details): array
    {
        if (is_array($details)) {
            $fileNamePattern = "/^[a-z0-9\_\-\.\/\\\]+\.php$/i";

            $comments = array_values(array_filter($details, function ($detail) use ($fileNamePattern) {
                return !preg_match($fileNamePattern, $detail);
            }));

            $scripts = array_values(array_filter($details, function ($detail) use ($fileNamePattern) {
                return preg_match($fileNamePattern, $detail);
            }));
        } else {
            $comments = (array)$details;
            $scripts = [];
        }

        return [$comments, $scripts];
    }
}
