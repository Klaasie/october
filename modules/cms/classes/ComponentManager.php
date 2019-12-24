<?php namespace Cms\Classes;

use Cms\Classes\Contracts\ComponentManagerContract;
use October\Rain\Exception\SystemException;
use October\Rain\Support\Str;
use System\Classes\Contracts\PluginManagerContract;

/**
 * Component manager
 *
 * @package october\cms
 * @author Alexey Bobkov, Samuel Georges
 */
class ComponentManager implements ComponentManagerContract
{
    /**
     * @var array Cache of registration callbacks.
     */
    protected $callbacks = [];

    /**
     * @var array An array where keys are codes and values are class names.
     */
    protected $codeMap;

    /**
     * @var array An array where keys are class names and values are codes.
     */
    protected $classMap;

    /**
     * @var array An array containing references to a corresponding plugin for each component class.
     */
    protected $pluginMap;

    /**
     * @var array A cached array of component details.
     */
    protected $detailsCache;

    /**
     * Static instance method
     * Kept this one to remain backwards compatible.
     *
     * @deprecated V1.0.xxx Instead of using this method,
     *             rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): ComponentManagerContract
    {
        return resolve(self::class);
    }

    /**
     * Scans each plugin an loads it's components.
     *
     * @return void
     * @throws SystemException
     */
    protected function loadComponents()
    {
        /*
         * Load module components
         */
        foreach ($this->callbacks as $callback) {
            $callback($this);
        }

        /*
         * Load plugin components
         */
        /** @var PluginManagerContract $pluginManager */
        $pluginManager = resolve(PluginManagerContract::class);
        $plugins = $pluginManager->getPlugins();

        foreach ($plugins as $plugin) {
            $components = $plugin->registerComponents();
            if (!is_array($components)) {
                continue;
            }

            foreach ($components as $className => $code) {
                $this->registerComponent($className, $code, $plugin);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function registerComponents(callable $definitions)
    {
        $this->callbacks[] = $definitions;
    }

    /**
     * {@inheritDoc}
     */
    public function registerComponent($className, $code = null, $plugin = null)
    {
        if (!$this->classMap) {
            $this->classMap = [];
        }

        if (!$this->codeMap) {
            $this->codeMap = [];
        }

        if (!$code) {
            $code = Str::getClassId($className);
        }

        if ($code === 'viewBag' && $className !== 'Cms\Components\ViewBag') {
            throw new SystemException(sprintf(
                'The component code viewBag is reserved. Please use another code for the component class %s.',
                $className
            ));
        }

        $className = Str::normalizeClassName($className);
        $this->codeMap[$code] = $className;
        $this->classMap[$className] = $code;
        if ($plugin !== null) {
            $this->pluginMap[$className] = $plugin;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function listComponents(): array
    {
        if ($this->codeMap === null) {
            $this->loadComponents();
        }

        return $this->codeMap;
    }

    /**
     * {@inheritDoc}
     */
    public function listComponentDetails(): array
    {
        if ($this->detailsCache !== null) {
            return $this->detailsCache;
        }

        $details = [];
        foreach ($this->listComponents() as $componentAlias => $componentClass) {
            $details[$componentAlias] = $this->makeComponent($componentClass)->componentDetails();
        }

        return $this->detailsCache = $details;
    }

    /**
     * {@inheritDoc}
     */
    public function resolve($name)
    {
        $codes = $this->listComponents();

        if (isset($codes[$name])) {
            return $codes[$name];
        }

        $name = Str::normalizeClassName($name);
        if (isset($this->classMap[$name])) {
            return $name;
        }

        return null;
    }

    /**
     * Checks to see if a component has been registered.
     *
     * @param string $name A component class name or code.
     * @return bool Returns true if the component is registered, otherwise false.
     * @throws SystemException
     */
    public function hasComponent($name): bool
    {
        $className = $this->resolve($name);
        if (!$className) {
            return false;
        }

        return isset($this->classMap[$className]);
    }

    /**
     * {@inheritDoc}
     */
    public function makeComponent($name, $cmsObject = null, $properties = []): ComponentBase
    {
        $className = $this->resolve($name);
        if (!$className) {
            throw new SystemException(sprintf(
                'Class name is not registered for the component "%s". Check the component plugin.',
                $name
            ));
        }

        if (!class_exists($className)) {
            throw new SystemException(sprintf(
                'Component class not found "%s". Check the component plugin.',
                $className
            ));
        }

        $component = app()->make($className, [$cmsObject, $properties]);
        $component->name = $name;

        return $component;
    }

    /**
     * {@inheritDoc}
     */
    public function findComponentPlugin($component)
    {
        $className = Str::normalizeClassName(get_class($component));
        return $this->pluginMap[$className] ?? null;
    }
}
