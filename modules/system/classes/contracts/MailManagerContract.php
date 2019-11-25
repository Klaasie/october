<?php namespace System\Classes\Contracts;

use Illuminate\Mail\Message;

/**
 * Interface MailManagerContract
 *
 * @package System\Classes\Contracts
 */
interface MailManagerContract
{
    /**
     * Same as `addContentToMailer` except with raw content.
     *
     * @param $message
     * @param $content
     * @param $data
     * @return bool
     * @todo Type hint!
     */
    public function addRawContentToMailer($message, $content, $data): bool;

    /**
     * This function hijacks the `addContent` method of the `October\Rain\Mail\Mailer`
     * class, using the `mailer.beforeAddContent` event.
     *
     * @param Message $message
     * @param string $code
     * @param array $data
     * @param bool $plainOnly Add only plain text content to the message
     * @return bool
     * @todo Type hint!
     * @todo never used?
     */
    public function addContentToMailer($message, $code, $data, $plainOnly = false): bool;

    /**
     * Render the Markdown template into HTML.
     *
     * @param  string  $content
     * @param  array  $data
     * @return string
     * @todo Type hint!
     */
    public function render($content, $data = []): string;

    /**
     * @param $template
     * @param array $data
     * @return string
     * @todo Type hint!
     */
    public function renderTemplate($template, $data = []): string;

    /**
     * Render the Markdown template into text.
     *
     * @param $content
     * @param array $data
     * @return string
     * @todo Type hint!
     */
    public function renderText($content, $data = []): string;

    /**
     * @param $template
     * @param array $data
     * @return string
     * @todo Type hint!
     */
    public function renderTextTemplate($template, $data = []): string;

    /**
     * @param $code
     * @param array $params
     * @return string
     * @todo Type hint!
     */
    public function renderPartial($code, array $params = []): string;

    /**
     * Loads registered mail templates from modules and plugins
     *
     * @return void
     */
    public function loadRegisteredTemplates();

    /**
     * Returns a list of the registered templates.
     *
     * @return array
     */
    public function listRegisteredTemplates(): array;

    /**
     * Returns a list of the registered partials.
     *
     * @return array
     */
    public function listRegisteredPartials(): array;

    /**
     * Returns a list of the registered layouts.
     *
     * @return array
     */
    public function listRegisteredLayouts(): array;

    /**
     * Registers a callback function that defines mail templates.
     * The callback function should register templates by calling the manager's
     * registerMailTemplates() function. Thi instance is passed to the
     * callback function as an argument. Usage:
     *
     *     MailManager::registerCallback(function ($manager) {
     *         $manager->registerMailTemplates([...]);
     *     });
     *
     * @param callable $callback A callable function.
     * @param void
     */
    public function registerCallback(callable $callback);

    /**
     * Registers mail views and manageable templates.
     *
     * @param array $definitions
     * @return void
     */
    public function registerMailTemplates(array $definitions);

    /**
     * Registers mail views and manageable layouts.
     *
     * @param array $definitions
     * @return void
     */
    public function registerMailPartials(array $definitions);

    /**
     * Registers mail views and manageable layouts.
     *
     * @param array $definitions
     * @return void
     */
    public function registerMailLayouts(array $definitions);
}
