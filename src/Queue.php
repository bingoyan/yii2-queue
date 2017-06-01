<?php
/**
 * @link https://github.com/zhuravljov/yii2-queue
 * @copyright Copyright (c) 2017 Roman Zhuravlev
 * @license http://opensource.org/licenses/BSD-3-Clause
 */

namespace zhuravljov\yii\queue;

use Yii;
use yii\base\Component;
use yii\base\InvalidParamException;
use yii\di\Instance;
use zhuravljov\yii\queue\serializers\Serializer;
use zhuravljov\yii\queue\serializers\PhpSerializer;

/**
 * Base Queue
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
abstract class Queue extends Component
{
    /**
     * @event PushEvent
     */
    const EVENT_BEFORE_PUSH = 'beforePush';
    /**
     * @event PushEvent
     */
    const EVENT_AFTER_PUSH = 'afterPush';
    /**
     * @event ExecEvent
     */
    const EVENT_BEFORE_EXEC = 'beforeExec';
    /**
     * @event ExecEvent
     */
    const EVENT_AFTER_EXEC = 'afterExec';
    /**
     * @event ExecEvent
     */
    const EVENT_AFTER_EXEC_ERROR = 'afterExecError';
    /**
     * @see Queue::isWaiting()
     */
    const STATUS_WAITING = 1;
    /**
     * @see Queue::isReserved()
     */
    const STATUS_RESERVED = 2;
    /**
     * @see Queue::isDone()
     */
    const STATUS_DONE = 3;

    /**
     * @var Serializer|array
     */
    public $serializer = PhpSerializer::class;
    /**
     * @var int default time to reserve a job
     */
    public $ttr = 60;
    /**
     * @var int default attempt count
     */
    public $attempts = 1;

    private $pushTtr;
    private $pushDelay;
    private $pushPriority;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->serializer = Instance::ensure($this->serializer, Serializer::class);
    }

    /**
     * Sets TTR for job execute
     *
     * @param int|mixed $value
     * @return $this
     */
    public function ttr($value)
    {
        $this->pushTtr = $value;
        return $this;
    }

    /**
     * Sets delay for later execute
     *
     * @param int|mixed $value
     * @return $this
     */
    public function delay($value)
    {
        $this->pushDelay = $value;
        return $this;
    }

    /**
     * Sets job priority
     *
     * @param mixed $value
     * @return $this
     */
    public function priority($value)
    {
        $this->pushPriority = $value;
        return $this;
    }

    /**
     * Pushes job into queue
     *
     * @param Job|mixed $job
     * @return string|null id of a job message
     */
    public function push($job)
    {
        $event = new PushEvent([
            'job' => $job,
            'ttr' => $job instanceof RetryableJob ? $job->getTtr() : ($this->pushTtr ?: $this->ttr),
            'delay' => $this->pushDelay ?: 0,
            'priority' => $this->pushPriority,
        ]);
        $this->pushTtr = null;
        $this->pushDelay = null;
        $this->pushPriority = null;

        $this->trigger(self::EVENT_BEFORE_PUSH, $event);
        $message = $this->serializer->serialize($event->job);
        $event->id = $this->pushMessage($message, $event->ttr, $event->delay, $event->priority);
        $this->trigger(self::EVENT_AFTER_PUSH, $event);

        return $event->id;
    }

    /**
     * @param string $message
     * @param int $ttr time to reserve in seconds
     * @param int $delay
     * @param mixed $priority
     * @return string|null id of a job message
     */
    abstract protected function pushMessage($message, $ttr, $delay, $priority);

    /**
     * @param string|null $id of a job message
     * @param string $message
     * @param int $ttr time to reserve
     * @param int $attempt number
     * @return boolean
     */
    protected function handleMessage($id, $message, $ttr, $attempt)
    {
        $job = $this->serializer->unserialize($message);
        if (!($job instanceof Job)) {
            throw new InvalidParamException('Message must be ' . Job::class . ' object.');
        }

        $eventOptions = ['id' => $id, 'job' => $job, 'ttr' => $ttr, 'attempt' => $attempt];
        $this->trigger(self::EVENT_BEFORE_EXEC, new ExecEvent($eventOptions));
        try {
            $job->execute($this);
        } catch (\Exception $error) {
            return $this->handleError($id, $job, $ttr, $attempt, $error);
        }
        $this->trigger(self::EVENT_AFTER_EXEC, new ExecEvent($eventOptions));

        return true;
    }

    /**
     * @param string|null $id
     * @param Job $job
     * @param int $ttr
     * @param int $attempt
     * @param \Exception $error
     * @return bool
     * @internal
     */
    public function handleError($id, $job, $ttr, $attempt, $error)
    {
        $event = new ErrorEvent([
            'id' => $id,
            'job' => $job,
            'ttr' => $ttr,
            'attempt' => $attempt,
            'error' => $error,
            'retry' => $job instanceof RetryableJob
                ? $job->canRetry($attempt, $error)
                : $attempt < $this->attempts,
        ]);
        $this->trigger(self::EVENT_AFTER_EXEC_ERROR, $event);

        return !$event->retry;
    }

    /**
     * @param string $id of a job message
     * @return bool
     */
    public function isWaiting($id)
    {
        return $this->status($id) === Queue::STATUS_WAITING;
    }

    /**
     * @param string $id of a job message
     * @return bool
     */
    public function isReserved($id)
    {
        return $this->status($id) === Queue::STATUS_RESERVED;
    }

    /**
     * @param string $id of a job message
     * @return bool
     */
    public function isDone($id)
    {
        return $this->status($id) === Queue::STATUS_DONE;
    }

    /**
     * @param string $id of a job message
     * @return int status code
     */
    abstract protected function status($id);
}