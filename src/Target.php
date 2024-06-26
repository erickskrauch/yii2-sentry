<?php
/**
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace nohnaimer\sentry;

use Yii;
use Throwable;
use yii\web\User;
use Sentry\Event;
use yii\log\Logger;
use yii\di\Instance;
use Sentry\Severity;
use yii\web\Request;
use Sentry\EventHint;
use Sentry\State\Scope;
use yii\helpers\ArrayHelper;

use function Sentry\withScope;
use function Sentry\captureEvent;
use function Sentry\captureException;

/**
 * SentryTarget records log messages in a Sentry.
 * @see https://sentry.io
 */
class Target extends \yii\log\Target
{
    /**
     * @var string|Component
     */
    public $sentry = 'sentry';
    /**
     * @var callable Callback function that can modify extra's array
     */
    public $extraCallback;

    public function __construct($config = [])
    {
        parent::__construct($config);

        $this->sentry = Instance::ensure($this->sentry, Component::class);
    }

    /**
     * {@inheritdoc}
     */
    protected function getContextMessage()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function export()
    {
        foreach ($this->messages as $message) {
            [$text, $level, $category] = $message;

            $data = [
                'message' => '',
                'tags' => ['category' => $category],
                'extra' => [],
                'userData' => [],
            ];

            $request = Yii::$app->getRequest();
            if ($request instanceof Request && $request->getUserIP()) {
                $data['userData']['ip_address'] = $request->getUserIP();
            }

            try {
                /** @var User $user */
                $user = Yii::$app->has('user', true) ? Yii::$app->get('user', false) : null;
                if ($user && ($identity = $user->getIdentity(false))) {
                    $data['userData']['id'] = $identity->getId();
                }
            } catch (Throwable $e) {}

            withScope(function (Scope $scope) use ($text, $level, $data) {
                if (is_array($text)) {
                    if (isset($text['msg'])) {
                        $data['message'] = (string) $text['msg'];
                        unset($text['msg']);
                    }

                    if (isset($text['message'])) {
                        $data['message'] = (string) $text['message'];
                        unset($text['message']);
                    }

                    if (isset($text['tags'])) {
                        $data['tags'] = ArrayHelper::merge($data['tags'], $text['tags']);
                        unset($text['tags']);
                    }

                    if (isset($text['exception']) && $text['exception'] instanceof Throwable) {
                        $data['exception'] = $text['exception'];
                        unset($text['exception']);
                    }

                    $data['extra'] = $text;
                } else {
                    $data['message'] = (string) $text;
                }

                if ($this->sentry->context) {
                    $data['extra']['context'] = parent::getContextMessage();
                }

                $data = $this->runExtraCallback($text, $data);

                $scope->setUser($data['userData']);
                foreach ($data['extra'] as $key => $value) {
                    $scope->setExtra((string) $key, $value);
                }
                foreach ($data['tags'] as $key => $value) {
                    if ($value) {
                        $scope->setTag($key, $value);
                    }
                }

                if ($text instanceof Throwable) {
                    captureException($text);
                } else {
                    $event = Event::createEvent();
                    $event->setMessage($data['message']);
                    $event->setLevel($this->getLogLevel($level));

                    captureEvent($event, EventHint::fromArray(array_filter([
                        'exception' => $data['exception'] ?? null,
                    ])));
                }
            });
        }
    }

    /**
     * Calls the extra callback if it exists
     *
     * @param mixed $text
     * @param array $data
     *
     * @return array
     */
    public function runExtraCallback($text, $data)
    {
        if (is_callable($this->extraCallback)) {
            $data['extra'] = call_user_func($this->extraCallback, $text, $data['extra'] ?? []);
        }

        return $data;
    }

    /**
     * Returns the text display of the specified level for the Sentry.
     *
     * @deprecated Deprecated from 1.5, will remove in 2.0
     *
     * @param int $level The message level, e.g. [[LEVEL_ERROR]], [[LEVEL_WARNING]].
     *
     * @return string
     */
    public static function getLevelName($level)
    {
        static $levels = [
            Logger::LEVEL_ERROR => 'error',
            Logger::LEVEL_WARNING => 'warning',
            Logger::LEVEL_INFO => 'info',
            Logger::LEVEL_TRACE => 'debug',
            Logger::LEVEL_PROFILE_BEGIN => 'debug',
            Logger::LEVEL_PROFILE_END => 'debug',
        ];

        return $levels[$level] ?? 'error';
    }

    /**
     * Translates Yii2 log levels to Sentry Severity.
     *
     * @param int $level
     *
     * @return Severity
     */
    protected function getLogLevel($level): Severity
    {
        switch ($level) {
            case Logger::LEVEL_PROFILE:
            case Logger::LEVEL_PROFILE_BEGIN:
            case Logger::LEVEL_PROFILE_END:
            case Logger::LEVEL_TRACE:
                return Severity::debug();
            case Logger::LEVEL_WARNING:
                return Severity::warning();
            case Logger::LEVEL_ERROR:
                return Severity::error();
            case Logger::LEVEL_INFO:
            default:
                return Severity::info();
        }
    }
}