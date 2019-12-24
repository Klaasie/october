<?php namespace Backend\Classes;

use Backend\Classes\Contracts\WidgetManagerContract;
use Illuminate\Contracts\Events\Dispatcher;
use October\Rain\Exception\SystemException;
use October\Rain\Support\Str;
use System\Classes\Contracts\PluginManagerContract;

/**
 * Widget manager
 *
 * @package october\backend
 * @author Alexey Bobkov, Samuel Georges
 */
class WidgetManager implements WidgetManagerContract
{
    /**
     * @var array An array of form widgets. Stored in the form of ['FormWidgetClass' => $formWidgetInfo].
     */
    protected $formWidgets;

    /**
     * @var array Cache of form widget registration callbacks.
     */
    protected $formWidgetCallbacks = [];

    /**
     * @var array An array of form widgets keyed by their code. Stored in the form of ['formwidgetcode' => 'FormWidgetClass'].
     */
    protected $formWidgetHints;

    /**
     * @var array An array of report widgets.
     */
    protected $reportWidgets;

    /**
     * @var array Cache of report widget registration callbacks.
     */
    protected $reportWidgetCallbacks = [];

    /**
     * @var PluginManagerContract
     */
    protected $pluginManager;

    /**
     * @var AuthManager
     */
    private $authManager;

    /**
     * @var Dispatcher
     */
    private $dispatcher;

    /**
     * WidgetManager constructor.
     * @param PluginManagerContract $pluginManager
     */
    public function __construct(PluginManagerContract $pluginManager)
    {
        $this->pluginManager = $pluginManager;
        $this->authManager = resolve('backend.auth');
        $this->dispatcher = resolve('events');
    }

    /**
     * Static instance method
     * Kept this one to remain backwards compatible.
     *
     * @deprecated V1.0.xxx Instead of using this method,
     *             rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): WidgetManagerContract
    {
        return resolve(self::class);
    }

    //
    // Form Widgets
    //

    /**
     * {@inheritDoc}
     */
    public function listFormWidgets(): array
    {
        if ($this->formWidgets === null) {
            $this->formWidgets = [];

            /*
             * Load module widgets
             */
            foreach ($this->formWidgetCallbacks as $callback) {
                $callback($this);
            }

            /*
             * Load plugin widgets
             */
            $plugins = $this->pluginManager->getPlugins();

            foreach ($plugins as $plugin) {
                if (!is_array($widgets = $plugin->registerFormWidgets())) {
                    continue;
                }

                foreach ($widgets as $className => $widgetInfo) {
                    $this->registerFormWidget($className, $widgetInfo);
                }
            }
        }

        return $this->formWidgets;
    }

    /**
     * {@inheritDoc}
     */
    public function registerFormWidget($className, $widgetInfo = null)
    {
        if (!is_array($widgetInfo)) {
            $widgetInfo = ['code' => $widgetInfo];
        }

        $widgetCode = $widgetInfo['code'] ?? null;

        if (!$widgetCode) {
            $widgetCode = Str::getClassId($className);
        }

        $this->formWidgets[$className] = $widgetInfo;
        $this->formWidgetHints[$widgetCode] = $className;
    }

    /**
     * {@inheritDoc}
     */
    public function registerFormWidgets(callable $definitions)
    {
        $this->formWidgetCallbacks[] = $definitions;
    }

    /**
     * Returns a class name from a form widget code
     * Normalizes a class name or converts an code to its class name.
     *
     * @param string $name Class name or form widget code.
     * @return string The class name resolved, or the original name.
     */
    public function resolveFormWidget($name): string
    {
        if ($this->formWidgets === null) {
            $this->listFormWidgets();
        }

        $hints = $this->formWidgetHints;

        if (isset($hints[$name])) {
            return $hints[$name];
        }

        $_name = Str::normalizeClassName($name);
        if (isset($this->formWidgets[$_name])) {
            return $_name;
        }

        return $name;
    }

    //
    // Report Widgets
    //

    /**
     * {@inheritDoc}
     */
    public function listReportWidgets(): array
    {
        if ($this->reportWidgets === null) {
            $this->reportWidgets = [];

            /*
             * Load module widgets
             */
            foreach ($this->reportWidgetCallbacks as $callback) {
                $callback($this);
            }

            /*
             * Load plugin widgets
             */
            $plugins = $this->pluginManager->getPlugins();

            foreach ($plugins as $plugin) {
                if (!is_array($widgets = $plugin->registerReportWidgets())) {
                    continue;
                }

                foreach ($widgets as $className => $widgetInfo) {
                    $this->registerReportWidget($className, $widgetInfo);
                }
            }
        }

        /**
         * @event system.reportwidgets.extendItems
         * Enables adding or removing report widgets.
         *
         * You will have access to the WidgetManager instance and be able to call the appropiate methods
         * $manager->registerReportWidget();
         * $manager->removeReportWidget();
         *
         * Example usage:
         *
         *     Event::listen('system.reportwidgets.extendItems', function ($manager) {
         *          $manager->removeReportWidget('Acme\ReportWidgets\YourWidget');
         *     });
         *
         */
        $this->dispatcher->fire('system.reportwidgets.extendItems', [$this]);

        $user = $this->authManager->getUser();
        foreach ($this->reportWidgets as $widget => $config) {
            if (!empty($config['permissions']) && !$user->hasAccess($config['permissions'], false)) {
                unset($this->reportWidgets[$widget]);
            }
        }

        return $this->reportWidgets;
    }

    /**
     * {@inheritDoc}
     */
    public function getReportWidgets(): array
    {
        return $this->reportWidgets;
    }

    /**
     * {@inheritDoc}
     */
    public function registerReportWidget($className, $widgetInfo)
    {
        $this->reportWidgets[$className] = $widgetInfo;
    }

    /**
     * {@inheritDoc}
     */
    public function registerReportWidgets(callable $definitions)
    {
        $this->reportWidgetCallbacks[] = $definitions;
    }

    /**
     * {@inheritDoc}
     */
    public function removeReportWidget($className)
    {
        if (!$this->reportWidgets) {
            throw new SystemException('Unable to remove a widget before widgets are loaded.');
        }

        unset($this->reportWidgets[$className]);
    }
}
