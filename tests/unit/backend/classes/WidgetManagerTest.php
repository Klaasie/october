<?php

use Backend\Classes\Contracts\WidgetManagerContract;
use Backend\Classes\Controller;
use Backend\Classes\WidgetManager;

class WidgetManagerTest extends TestCase
{
    public function testListFormWidgets()
    {
        /** @var WidgetManagerContract $manager */
        $manager = resolve(WidgetManagerContract::class);
        $widgets = $manager->listFormWidgets();

        $this->assertArrayHasKey('TestVendor\Test\FormWidgets\Sample', $widgets);
        $this->assertArrayHasKey('October\Tester\FormWidgets\Preview', $widgets);
    }

    public function testIfWidgetsCanBeExtended()
    {
        /** @var WidgetManagerContract $manager */
        $manager = resolve(WidgetManagerContract::class);
        $manager->registerReportWidget('Acme\Fake\ReportWidget\HelloWorld', [
            'name' => 'Hello World Test',
            'context' => 'dashboard'
        ]);
        $widgets = $manager->listReportWidgets();

        $this->assertArrayHasKey('Acme\Fake\ReportWidget\HelloWorld', $widgets);
    }

    public function testIfWidgetsCanBeRemoved()
    {
        /** @var WidgetManagerContract $manager */
        $manager = resolve(WidgetManagerContract::class);
        $manager->registerReportWidget('Acme\Fake\ReportWidget\HelloWorld', [
            'name' => 'Hello World Test',
            'context' => 'dashboard'
        ]);
        $manager->registerReportWidget('Acme\Fake\ReportWidget\ByeWorld', [
            'name' => 'Hello World Bye',
            'context' => 'dashboard'
        ]);

        $manager->removeReportWidget('Acme\Fake\ReportWidget\ByeWorld');

        $widgets = $manager->listReportWidgets();

        $this->assertCount(1, $widgets);
    }
}
