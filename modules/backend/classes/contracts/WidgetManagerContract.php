<?php namespace Backend\Classes\Contracts;

use October\Rain\Exception\SystemException;

/**
 * Interface WidgetManagerContract
 */
interface WidgetManagerContract
{
    /**
     * Returns a list of registered form widgets.
     *
     * @return array Array keys are class names.
     */
    public function listFormWidgets(): array;

    /**
     * Registers a single form widget.
     *
     * @param string $className Widget class name.
     * @param array $widgetInfo Registration information, can contain a `code` key.
     * @return void
     */
    public function registerFormWidget($className, $widgetInfo = null);

    /**
     * Manually registers form widget for consideration. Usage:
     *
     *     WidgetManager::registerFormWidgets(function ($manager) {
     *         $manager->registerFormWidget('Backend\FormWidgets\CodeEditor', 'codeeditor');
     *     });
     *
     * @param callable $definitions
     */
    public function registerFormWidgets(callable $definitions);

    /**
     * Returns a class name from a form widget code
     * Normalizes a class name or converts an code to its class name.
     *
     * @param string $name Class name or form widget code.
     * @return string The class name resolved, or the original name.
     */
    public function resolveFormWidget($name): string;

    /**
     * Returns a list of registered report widgets.
     *
     * @return array Array keys are class names.
     */
    public function listReportWidgets(): array;

    /**
     * Returns the raw array of registered report widgets.
     *
     * @return array Array keys are class names.
     */
    public function getReportWidgets(): array;

    /**
     * Registers a single report widget.
     *
     * @param $className
     * @param $widgetInfo
     */
    public function registerReportWidget($className, $widgetInfo);

    /**
     * Manually registers report widget for consideration. Usage:
     *
     *     WidgetManager::registerReportWidgets(function ($manager) {
     *         $manager->registerReportWidget('RainLab\GoogleAnalytics\ReportWidgets\TrafficOverview', [
     *             'name' => 'Google Analytics traffic overview',
     *             'context' => 'dashboard'
     *         ]);
     *     });
     *
     * @param callable $definitions
     */
    public function registerReportWidgets(callable $definitions);

    /**
     * Remove a registered ReportWidget.
     *
     * @param string $className Widget class name.
     * @return void
     * @throws SystemException
     */
    public function removeReportWidget($className);
}
