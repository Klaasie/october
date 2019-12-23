<?php namespace Backend\Classes\Contracts;

use October\Rain\Exception\SystemException;

/**
 * Interface NavigationManagerContract
 */
interface NavigationManagerContract
{
    /**
     * Registers a callback function that defines menu items.
     * The callback function should register menu items by calling the manager's
     * `registerMenuItems` method. The manager instance is passed to the callback
     * function as an argument. Usage:
     *
     *     BackendMenu::registerCallback(function ($manager) {
     *         $manager->registerMenuItems([...]);
     *     });
     *
     * @param callable $callback A callable function.
     */
    public function registerCallback(callable $callback);

    /**
     * Registers the back-end menu items.
     * The argument is an array of the main menu items. The array keys represent the
     * menu item codes, specific for the plugin/module. Each element in the
     * array should be an associative array with the following keys:
     * - label - specifies the menu label localization string key, required.
     * - icon - an icon name from the Font Awesome icon collection, required.
     * - url - the back-end relative URL the menu item should point to, required.
     * - permissions - an array of permissions the back-end user should have, optional.
     *   The item will be displayed if the user has any of the specified permissions.
     * - order - a position of the item in the menu, optional.
     * - counter - an optional numeric value to output near the menu icon. The value should be
     *   a number or a callable returning a number.
     * - counterLabel - an optional string value to describe the numeric reference in counter.
     * - sideMenu - an array of side menu items, optional. If provided, the array items
     *   should represent the side menu item code, and each value should be an associative
     *   array with the following keys:
     *      - label - specifies the menu label localization string key, required.
     *      - icon - an icon name from the Font Awesome icon collection, required.
     *      - url - the back-end relative URL the menu item should point to, required.
     *      - attributes - an array of attributes and values to apply to the menu item, optional.
     *      - permissions - an array of permissions the back-end user should have, optional.
     *      - counter - an optional numeric value to output near the menu icon. The value should be
     *        a number or a callable returning a number.
     *      - counterLabel - an optional string value to describe the numeric reference in counter.
     *
     * @param string $owner Specifies the menu items owner plugin or module in the format Author.Plugin.
     * @param array $definitions An array of the menu item definitions.
     * @throws SystemException
     */
    public function registerMenuItems($owner, array $definitions);

    /**
     * Dynamically add an array of main menu items
     *
     * @param string $owner
     * @param array  $definitions
     */
    public function addMainMenuItems($owner, array $definitions);

    /**
     * Dynamically add a single main menu item
     *
     * @param string $owner
     * @param string $code
     * @param array $definition
     */
    public function addMainMenuItem($owner, $code, array $definition);

    /**
     * Removes a single main menu item
     *
     * @param $owner
     * @param $code
     */
    public function removeMainMenuItem($owner, $code);

    /**
     * Dynamically add an array of side menu items
     *
     * @param string $owner
     * @param string $code
     * @param array  $definitions
     */
    public function addSideMenuItems($owner, $code, array $definitions);

    /**
     * Dynamically add a single side menu item
     *
     * @param string $owner
     * @param string $code
     * @param string $sideCode
     * @param array $definition
     * @return bool
     */
    public function addSideMenuItem($owner, $code, $sideCode, array $definition): bool;

    /**
     * Removes a single main menu item
     *
     * @param $owner
     * @param $code
     * @param $sideCode
     * @return bool
     */
    public function removeSideMenuItem($owner, $code, $sideCode): bool;

    /**
     * Returns a list of the main menu items.
     *
     * @return array
     */
    public function listMainMenuItems(): array;

    /**
     * Returns a list of side menu items for the currently active main menu item.
     * The currently active main menu item is set with the setContext methods.
     *
     * @param null $owner
     * @param null $code
     * @return array
     * @throws SystemException
     */
    public function listSideMenuItems($owner = null, $code = null): array;

    /**
     * Sets the navigation context.
     * The function sets the navigation owner, main menu item code and the side menu item code.
     *
     * @param string $owner Specifies the navigation owner in the format Vendor/Module
     * @param string $mainMenuItemCode Specifies the main menu item code
     * @param string $sideMenuItemCode Specifies the side menu item code
     */
    public function setContext($owner, $mainMenuItemCode, $sideMenuItemCode = null);

    /**
     * Sets the navigation context.
     * The function sets the navigation owner.
     *
     * @param string $owner Specifies the navigation owner in the format Vendor/Module
     */
    public function setContextOwner($owner);

    /**
     * Specifies a code of the main menu item in the current navigation context.
     *
     * @param string $mainMenuItemCode Specifies the main menu item code
     */
    public function setContextMainMenu($mainMenuItemCode);

    /**
     * Returns information about the current navigation context.
     *
     * @return mixed Returns an object with the following fields:
     * - mainMenuCode
     * - sideMenuCode
     * - owner
     */
    public function getContext();

    /**
     * Specifies a code of the side menu item in the current navigation context.
     * If the code is set to TRUE, the first item will be flagged as active.
     *
     * @param string $sideMenuItemCode Specifies the side menu item code
     */
    public function setContextSideMenu($sideMenuItemCode);

    /**
     * Determines if a main menu item is active.
     *
     * @param mixed $item Specifies the item object.
     * @return boolean Returns true if the menu item is active.
     */
    public function isMainMenuItemActive($item): bool;

    /**
     * Returns the currently active main menu item
     *
     * @return mixed|null
     * @throws SystemException
     */
    public function getActiveMainMenuItem();

    /**
     * Determines if a side menu item is active.
     *
     * @param mixed $item Specifies the item object.
     * @return boolean Returns true if the side item is active.
     */
    public function isSideMenuItemActive($item): bool;

    /**
     * Registers a special side navigation partial for a specific main menu.
     * The sidenav partial replaces the standard side navigation.
     *
     * @param string $owner Specifies the navigation owner in the format Vendor/Module.
     * @param string $mainMenuItemCode Specifies the main menu item code.
     * @param string $partial Specifies the partial name.
     */
    public function registerContextSidenavPartial($owner, $mainMenuItemCode, $partial);

    /**
     * Returns the side navigation partial for a specific main menu previously registered
     * with the registerContextSidenavPartial() method.
     *
     * @param string $owner Specifies the navigation owner in the format Vendor/Module.
     * @param string $mainMenuItemCode Specifies the main menu item code.
     * @return mixed Returns the partial name or null.
     */
    public function getContextSidenavPartial($owner, $mainMenuItemCode);
}
