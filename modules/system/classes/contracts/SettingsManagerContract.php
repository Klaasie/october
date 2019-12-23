<?php namespace System\Classes\Contracts;

/**
 * Interface SettingsManagerContract
 *
 * @package System\Classes\Contracts
 */
interface SettingsManagerContract
{
    /**
     * Returns a collection of all settings by group, filtered by context
     *
     * @param  string $context
     * @return array
     * @todo Type hint!
     */
    public function listItems($context = null): array;

    /**
     * Registers a callback function that defines setting items.
     * The callback function should register setting items by calling the manager's
     * registerSettingItems() function. The manager instance is passed to the
     * callback function as an argument. Usage:
     *
     *     SettingsManager::registerCallback(function ($manager) {
     *         $manager->registerSettingItems([...]);
     *     });
     *
     * @param callable $callback A callable function.
     * @return void
     */
    public function registerCallback(callable $callback);

    /**
     * Registers the back-end setting items.
     * The argument is an array of the settings items. The array keys represent the
     * setting item codes, specific for the plugin/module. Each element in the
     * array should be an associative array with the following keys:
     * - label - specifies the settings label localization string key, required.
     * - icon - an icon name from the Font Awesome icon collection, required.
     * - url - the back-end relative URL the setting item should point to.
     * - class - the back-end relative URL the setting item should point to.
     * - permissions - an array of permissions the back-end user should have, optional.
     *   The item will be displayed if the user has any of the specified permissions.
     * - order - a position of the item in the setting, optional.
     * - category - a string to assign this item to a category, optional.
     *
     * @param string $owner Specifies the setting items owner plugin or module in the format Vendor.Module.
     * @param array $definitions An array of the setting item definitions.
     * @return void
     * @todo Type hint!
     */
    public function registerSettingItems($owner, array $definitions);

    /**
     * Dynamically add an array of setting items
     *
     * @param string $owner
     * @param array  $definitions
     * @return void
     * @todo Type hint!
     */
    public function addSettingItems($owner, array $definitions);

    /**
     * Dynamically add a single setting item
     *
     * @param string $owner
     * @param string $code
     * @param array  $definition
     * @return void
     * @todo Type hint!
     */
    public function addSettingItem($owner, $code, array $definition);

    /**
     * Removes a single setting item
     *
     * @param $owner
     * @param $code
     * @return void
     * @todo Type hint!
     */
    public function removeSettingItem($owner, $code);

    /**
     * @param string $contextOwner
     * @return void
     */
    public function setContextOwner(string $contextOwner);

    /**
     * @param string $contextItemCode
     * @return void
     */
    public function setContextItemCode(string $contextItemCode);

    /**
     * Returns information about the current settings context.
     *
     * @return mixed Returns an object with the following fields:
     * - itemCode
     * - owner
     * @todo Value object!
     */
    public function getContext();

    /**
     * Locates a setting item object by it's owner and code
     *
     * @param string $owner
     * @param string $code
     * @return mixed The item object or FALSE if nothing is found
     * @todo Type hint & single return type!
     */
    public function findSettingItem($owner, $code);
}
