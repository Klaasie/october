<?php namespace Cms\Classes\Contracts;

use Cms\Classes\CmsObject;
use Cms\Classes\ComponentBase;
use October\Rain\Exception\SystemException;

/**
 * Interface ComponentManagerContract
 */
interface ComponentManagerContract
{
    /**
     * Manually registers a component for consideration. Usage:
     *
     *     ComponentManager::registerComponents(function ($manager) {
     *         $manager->registerComponent('October\Demo\Components\Test', 'testComponent');
     *     });
     *
     * @param callable $definitions
     * @return void
     */
    public function registerComponents(callable $definitions);

    /**
     * Registers a single component.
     *
     * @param $className
     * @param null $code
     * @param null $plugin
     * @return void
     * @throws SystemException
     */
    public function registerComponent($className, $code = null, $plugin = null);

    /**
     * Returns a list of registered components.
     *
     * @return array Array keys are codes, values are class names.
     * @throws SystemException
     */
    public function listComponents(): array;

    /**
     * Returns an array of all component detail definitions.
     *
     * @return array Array keys are component codes, values are the details defined in the component.
     * @throws SystemException
     */
    public function listComponentDetails(): array;

    /**
     * Returns a class name from a component code
     * Normalizes a class name or converts an code to it's class name.
     *
     * @param $name
     * @return string|null The class name resolved, or null.
     * @throws SystemException
     */
    public function resolve($name);

    /**
     * Checks to see if a component has been registered.
     *
     * @param string $name A component class name or code.
     * @return bool Returns true if the component is registered, otherwise false.
     * @throws SystemException
     */
    public function hasComponent($name): bool;

    /**
     * Makes a component object with properties set.
     *
     * @param string $name A component class name or code.
     * @param CmsObject $cmsObject The Cms object that spawned this component.
     * @param array $properties The properties set by the Page or Layout.
     * @return ComponentBase The component object.
     * @throws SystemException
     */
    public function makeComponent($name, $cmsObject = null, $properties = []): ComponentBase;

    /**
     * Returns a parent plugin for a specific component object.
     *
     * @param mixed $component A component to find the plugin for.
     * @return mixed Returns the plugin object or null.
     */
    public function findComponentPlugin($component);
}
