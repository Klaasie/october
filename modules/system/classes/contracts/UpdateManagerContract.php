<?php

namespace System\Classes\Contracts;

use Illuminate\Console\Command;
use System\Classes\UpdateManager;

/**
 * Interface UpdateManagerContract
 *
 * @package System\Classes\Contracts
 */
interface UpdateManagerContract
{
    /**
     * @return self
     * @deprecated V1.0.xxx Instead of using this method,
     *                      rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): self;

    /**
     * Creates the migration table and updates
     *
     * @return self
     */
    public function update(): self;

    /**
     * Checks for new updates and returns the amount of updates yet to be applied.
     * Only requests from the server at a set interval (retry timer).
     *
     * @param  boolean $force Ignore the retry timer.
     * @return int Number of pending updates.
     * @todo Type hint!
     */
    public function check($force = false): int;

    /**
     * Requests an update list used for checking for new updates.
     *
     * @param  boolean $force Request application and plugins hash list regardless of version.
     * @return array
     * @todo Type hint!
     */
    public function requestUpdateList($force = false): array;

    /**
     * Requests details about a project based on its identifier.
     *
     * @param  string $projectId
     * @return array
     * @todo Type hint!
     */
    public function requestProjectDetails($projectId): array;

    /**
     * Roll back all modules and plugins.
     *
     * @return self
     */
    public function uninstall(): self;

    /**
     * Asks the gateway for the latest build number and stores it.
     *
     * @return int
     */
    public function setBuildNumberManually(): int;

    /**
     * Returns the currently installed system hash.
     *
     * @return string
     * @todo Type hint!
     */
    public function getHash();

    /**
     * Run migrations on a single module
     *
     * @param string $module Module name
     * @return self
     * @todo Type hint!
     */
    public function migrateModule($module): self;

    /**
     * Run seeds on a module
     *
     * @param string $module Module name
     * @return self
     * @todo Type hint!
     */
    public function seedModule($module): self;

    /**
     * Downloads the core from the update server.
     *
     * @param string $hash Expected file hash.
     * @return void
     * @todo Type hint!
     */
    public function downloadCore($hash);

    /**
     * Extracts the core after it has been downloaded.
     *
     * @return void
     */
    public function extractCore();

    /**
     * Sets the build number and hash
     *
     * @param string $hash
     * @param string $build
     * @return void
     * @todo Type hint!
     */
    public function setBuild($build, $hash = null);

    /**
     * Looks up a plugin from the update server.
     *
     * @param string $name Plugin name.
     * @return array Details about the plugin.
     * @todo Type hint!
     */
    public function requestPluginDetails($name): array;

    /**
     * Looks up content for a plugin from the update server.
     *
     * @param string $name Plugin name.
     * @return array Content for the plugin.
     * @todo Type hint!
     */
    public function requestPluginContent($name): array;

    /**
     * Runs update on a single plugin
     *
     * @param string $name Plugin name.
     * @return self
     * @todo Type hint!
     */
    public function updatePlugin($name): self;

    /**
     * Removes an existing plugin
     *
     * @param string $name Plugin name.
     * @return self
     * @todo Type hint!
     */
    public function rollbackPlugin($name): self;

    /**
     * Downloads a plugin from the update server.

     * @param string $name Plugin name.
     * @param string $hash Expected file hash.
     * @param boolean $installation Indicates whether this is a plugin installation request.
     * @return self
     * @todo Type hint!
     */
    public function downloadPlugin($name, $hash, $installation = false): self;

    /**
     * Extracts a plugin after it has been downloaded.
     *
     * @return void
     * @todo Type hint!
     */
    public function extractPlugin($name, $hash);

    /**
     * Looks up a theme from the update server.
     *
     * @param string $name Theme name.
     * @return array Details about the theme.
     * @todo Type hint!
     */
    public function requestThemeDetails($name): array;

    /**
     * Downloads a theme from the update server.
     *
     * @param string $name Theme name.
     * @param string $hash Expected file hash.
     * @return void
     * @todo Type hint!
     */
    public function downloadTheme($name, $hash);

    /**
     * Extracts a theme after it has been downloaded.
     *
     * @return void
     * @todo Type hint!
     */
    public function extractTheme($name, $hash);

    /**
     * @param $codes
     * @param string $type
     * @return array
     * @todo Type hint!
     */
    public function requestProductDetails($codes, $type = null): array;

    /**
     * Returns popular themes found on the marketplace.
     *
     * @param null $type
     * @return array
     * @todo Type hint!
     */
    public function requestPopularProducts($type = null): array;

    /**
     * Returns the latest changelog information.
     *
     * @return bool|null|array
     */
    public function requestChangelog();

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
    public function resetNotes(): self;

    /**
     * Sets an output stream for writing notes.
     *
     * @param  Command $output
     * @return self
     * @todo Type hint!
     */
    public function setNotesOutput($output): UpdateManagerContract;

    /**
     * Contacts the update server for a response.
     *
     * @param  string $uri Gateway API URI
     * @param  array  $postData Extra post data
     * @return array
     * @todo Type hint!
     */
    public function requestServerData($uri, $postData = []): array;

    /**
     * Downloads a file from the update server.
     *
     * @param  string $uri          Gateway API URI
     * @param  string $fileCode     A unique code for saving the file.
     * @param  string $expectedHash The expected file hash of the file.
     * @param  array  $postData     Extra post data
     * @return void
     * @todo Type hint!
     */
    public function requestServerFile($uri, $fileCode, $expectedHash, $postData = []);

    /**
     * Set the API security for all transmissions.
     *
     * @param string $key    API Key
     * @param string $secret API Secret
     * @return void
     * @todo Type hint!
     */
    public function setSecurity($key, $secret);

    /**
     * @return string
     */
    public function getMigrationTableName(): string;
}
