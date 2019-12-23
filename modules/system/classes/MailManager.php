<?php namespace System\Classes;

use Illuminate\Mail\Message;
use October\Rain\Parse\Markdown;
use October\Rain\Parse\Twig;
use System\Classes\Contracts\MailManagerContract;
use System\Classes\Contracts\MarkupManagerContract;
use System\Classes\Contracts\PluginManagerContract;
use System\Models\MailPartial;
use System\Models\MailTemplate;
use System\Models\MailBrandSetting;
use System\Helpers\View as ViewHelper;
use System\Twig\MailPartialTokenParser;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * This class manages Mail sending functions
 *
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class MailManager implements MailManagerContract
{
    /**
     * @var Twig
     */
    private $twig;

    /**
     * @var Markdown
     */
    private $markdown;

    /**
     * @var array Cache of registration callbacks.
     */
    protected $callbacks = [];

    /**
     * @var array A cache of customised mail templates.
     */
    protected $templateCache = [];

    /**
     * @var array List of registered templates in the system
     */
    protected $registeredTemplates;

    /**
     * @var array List of registered partials in the system
     */
    protected $registeredPartials;

    /**
     * @var array List of registered layouts in the system
     */
    protected $registeredLayouts;

    /**
     * @var bool Internal marker for rendering mode
     */
    protected $isHtmlRenderMode = false;

    /**
     * @var bool Internal marker for booting custom twig extensions.
     */
    protected $isTwigStarted = false;

    /**
     * MailManager constructor.
     *
     * @param Twig $twig
     * @param Markdown $markdown
     */
    public function __construct(Twig $twig, Markdown $markdown)
    {
        $this->twig = $twig;
        $this->markdown = $markdown;
    }


    /**
     * {@inheritDoc}
     */
    public static function instance(): MailManagerContract
    {
        return resolve(self::class);
    }

    /**
     * {@inheritDoc}
     */
    public function addRawContentToMailer($message, $content, $data): bool
    {
        $template = new MailTemplate;

        $template->fillFromContent($content);

        $this->addContentToMailerInternal($message, $template, $data);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function addContentToMailer($message, $code, $data, $plainOnly = false): bool
    {
        if (isset($this->templateCache[$code])) {
            $template = $this->templateCache[$code];
        }
        else {
            $this->templateCache[$code] = $template = MailTemplate::findOrMakeTemplate($code);
        }

        if (!$template) {
            return false;
        }

        $this->addContentToMailerInternal($message, $template, $data, $plainOnly);

        return true;
    }

    /**
     * Internal method used to share logic between `addRawContentToMailer` and `addContentToMailer`
     *
     * @param Message $message
     * @param string $template
     * @param array $data
     * @param bool $plainOnly Add only plain text content to the message
     * @return void
     */
    protected function addContentToMailerInternal($message, $template, $data, $plainOnly = false)
    {
        /*
         * Start twig transaction
         */
        $this->startTwig();

        /*
         * Inject global view variables
         */
        $globalVars = ViewHelper::getGlobalVars();
        if (!empty($globalVars)) {
            $data = (array) $data + $globalVars;
        }

        /*
         * Subject
         */
        $swiftMessage = $message->getSwiftMessage();

        if (empty($swiftMessage->getSubject())) {
            $message->subject($this->twig->parse($template->subject, $data));
        }

        $data += [
            'subject' => $swiftMessage->getSubject()
        ];

        if (!$plainOnly) {
            /*
             * HTML contents
             */
            $html = $this->renderTemplate($template, $data);

            $message->setBody($html, 'text/html');
        }

        /*
         * Text contents
         */
        $text = $this->renderTextTemplate($template, $data);

        $message->addPart($text, 'text/plain');

        /*
         * End twig transaction
         */
        $this->stopTwig();
    }

    //
    // Rendering
    //

    /**
     * {@inheritDoc}
     */
    public function render($content, $data = []): string
    {
        if (!$content) {
            return '';
        }

        $html = $this->renderTwig($content, $data);

        $html = $this->markdown->parseSafe($html);

        return $html;
    }

    /**
     * {@inheritDoc}
     */
    public function renderTemplate($template, $data = []): string
    {
        $this->isHtmlRenderMode = true;

        $html = $this->render($template->content_html, $data);

        $css = MailBrandSetting::renderCss();

        $disableAutoInlineCss = false;

        if ($template->layout) {
            $disableAutoInlineCss = array_get($template->layout->options, 'disable_auto_inline_css', $disableAutoInlineCss);

            $html = $this->renderTwig($template->layout->content_html, [
                'content' => $html,
                'css' => $template->layout->content_css,
                'brandCss' => $css
            ] + (array) $data);

            $css .= PHP_EOL . $template->layout->content_css;
        }

        if (!$disableAutoInlineCss) {
            $html = (new CssToInlineStyles)->convert($html, $css);
        }

        return $html;
    }

    /**
     * {@inheritDoc}
     */
    public function renderText($content, $data = []): string
    {
        if (!$content) {
            return '';
        }

        $text = $this->renderTwig($content, $data);

        $text = html_entity_decode(preg_replace("/[\r\n]{2,}/", "\n\n", $text), ENT_QUOTES, 'UTF-8');

        return $text;
    }

    /**
     * {@inheritDoc}
     */
    public function renderTextTemplate($template, $data = []): string
    {
        $this->isHtmlRenderMode = false;

        $templateText = $template->content_text;

        if (!strlen($template->content_text)) {
            $templateText = $template->content_html;
        }

        $text = $this->renderText($templateText, $data);

        if ($template->layout) {
            $text = $this->renderTwig($template->layout->content_text, [
                'content' => $text
            ] + (array) $data);
        }

        return $text;
    }


    /**
     * {@inheritDoc}
     */
    public function renderPartial($code, array $params = []): string
    {
        if (!$partial = MailPartial::findOrMakePartial($code)) {
            return '<!-- Missing partial: '.$code.' -->';
        }

        if ($this->isHtmlRenderMode) {
            $content = $partial->content_html;
        }
        else {
            $content = $partial->content_text ?: $partial->content_html;
        }

        if (trim($content) === '') {
            return '';
        }

        return $this->renderTwig($content, $params);
    }

    /**
     * Internal helper for rendering Twig
     *
     * @param $content
     * @param array $data
     * @return string
     * @return string
     */
    protected function renderTwig($content, $data = []): string
    {
        if ($this->isTwigStarted) {
            return $this->twig->parse($content, $data);
        }

        $this->startTwig();

        $result = $this->twig->parse($content, $data);

        $this->stopTwig();

        return $result;
    }

    /**
     * Temporarily registers mail based token parsers with Twig.
     *
     * @return void
     */
    protected function startTwig()
    {
        if ($this->isTwigStarted) {
            return;
        }

        $this->isTwigStarted = true;

        /** @var MarkupManagerContract $markupManager */
        $markupManager = resolve(MarkupManagerContract::class);
        $markupManager->beginTransaction();
        $markupManager->registerTokenParsers([
            new MailPartialTokenParser
        ]);
    }

    /**
     * Indicates that we are finished with Twig.
     *
     * @return void
     */
    protected function stopTwig()
    {
        if (!$this->isTwigStarted) {
            return;
        }

        /** @var MarkupManagerContract $markupManager */
        $markupManager = resolve(MarkupManagerContract::class);
        $markupManager->endTransaction();

        $this->isTwigStarted = false;
    }

    //
    // Registration
    //

    /**
     * {@inheritDoc}
     */
    public function loadRegisteredTemplates()
    {
        foreach ($this->callbacks as $callback) {
            $callback($this);
        }

        /** @var PluginManagerContract $pluginManager */
        $pluginManager = resolve(PluginManagerContract::class);
        $plugins = $pluginManager->getPlugins();
        foreach ($plugins as $pluginId => $pluginObj) {
            $layouts = $pluginObj->registerMailLayouts();
            if (is_array($layouts)) {
                $this->registerMailLayouts($layouts);
            }

            $templates = $pluginObj->registerMailTemplates();
            if (is_array($templates)) {
                $this->registerMailTemplates($templates);
            }

            $partials = $pluginObj->registerMailPartials();
            if (is_array($partials)) {
                $this->registerMailPartials($partials);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function listRegisteredTemplates(): array
    {
        if ($this->registeredTemplates === null) {
            $this->loadRegisteredTemplates();
        }

        return $this->registeredTemplates;
    }

    /**
     * {@inheritDoc}
     */
    public function listRegisteredPartials(): array
    {
        if ($this->registeredPartials === null) {
            $this->loadRegisteredTemplates();
        }

        return $this->registeredPartials;
    }

    /**
     * {@inheritDoc}
     */
    public function listRegisteredLayouts(): array
    {
        if ($this->registeredLayouts === null) {
            $this->loadRegisteredTemplates();
        }

        return $this->registeredLayouts;
    }

    /**
     * {@inheritDoc}
     */
    public function registerCallback(callable $callback)
    {
        $this->callbacks[] = $callback;
    }

    /**
     * {@inheritDoc}
     */
    public function registerMailTemplates(array $definitions)
    {
        if (!$this->registeredTemplates) {
            $this->registeredTemplates = [];
        }

        // Prior syntax where (key) code => (value) description
        if (!isset($definitions[0])) {
            $definitions = array_keys($definitions);
        }

        $definitions = array_combine($definitions, $definitions);

        $this->registeredTemplates = $definitions + $this->registeredTemplates;
    }

    /**
     * {@inheritDoc}
     */
    public function registerMailPartials(array $definitions)
    {
        if (!$this->registeredPartials) {
            $this->registeredPartials = [];
        }

        $this->registeredPartials = $definitions + $this->registeredPartials;
    }

    /**
     * {@inheritDoc}
     */
    public function registerMailLayouts(array $definitions)
    {
        if (!$this->registeredLayouts) {
            $this->registeredLayouts = [];
        }

        $this->registeredLayouts = $definitions + $this->registeredLayouts;
    }
}
