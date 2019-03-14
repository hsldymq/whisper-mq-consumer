<?php

namespace Archman\BugsBunny;

use Archman\Whisper\AbstractMaster;
use Archman\Whisper\Helper;
use Archman\Whisper\Interfaces\WorkerFactoryInterface;
use Archman\Whisper\Message;
use Bunny\Async\Client;
use Bunny\Channel;
use Bunny\Message as AMQPMessage;
use Psr\Log\LoggerInterface;

/**
 * @event message
 * @event processed
 * @event limitReached
 * @event workerQuit
 * @event errorShuttingDown
 * @event errorSendingMessage
 * @event errorMessageHandling
 * @event errorDispatchingMessage
 * @event errorCreateWorker
 */
class Dispatcher extends AbstractMaster
{
    /**
     * @var array
     */
    protected $connectionOptions;

    /**
     * @var array
     * [
     *      'connections' => [
     *          [
     *              'client' => $client,
     *              'channel' => $channel,
     *              'tags' => [$tag1, $tag2, ...],
     *          ],
     *          ...
     *      ],
     *      'tags' => [
     *          $tag => $queueName,
     *          ...
     *      ]
     * ]
     */
    protected $connectionInfo = [
        'connections' => [],
        'tags' => [],
    ];

    /** @var array 要消费的队列 */
    protected $queues = [];

    /**
     * @var array
     * [
     *      $workerID => [
     *          'sent' => (int),        // 已向该worker投放的消息数量
     *          'processed' => (int),   // 已处理的消息数量
     *      ],
     *      ...
     * ]
     */
    protected $workersInfo = [];

    /**
     * @var WorkerFactoryInterface
     */
    protected $workerFactory;

    /**
     * @var WorkerScheduler
     */
    protected $workerScheduler;

    /**
     * @var int $maxWorkers 子进程数量上限. -1为不限制
     *
     * 当子进程到达上线后,调度器会停止接受消息队列的消息.
     * 等待有子进程闲置后,继续派发消息.
     */
    protected $maxWorkers = -1;

    /**
     * @var int 每个worker的消息队列容量
     */
    protected $workerCapacity = 1;

    /**
     * @var array
     * [
     *      $pid => $workerID,
     *      ...
     * ]
     */
    protected $idMap = [];

    /**
     * @var array 统计信息.
     * [
     *      'consumed' => (int),            // 消费了的消息总数
     *      'processed' => (int),           // 处理了的消息总数
     *      'peakWorkerNum' => (int),       // worker数量峰值
     *      'memoryUsage' => (int),         // 当前内存量,不含未使用的页(字节)
     *      'peakMemoryUsage' => (int)      // 内存使用峰值,不含未使用的页(字节)
     * ]
     */
    protected $stat = [
        'consumed' => 0,
        'processed' => 0,
        'peakWorkerNum' => 0,
    ];

    /**
     * @var LoggerInterface|null
     */
    protected $logger = null;

    /**
     * @var int 僵尸进程检查周期(秒)
     */
    protected $patrolPeriod = 300;

    /**
     * @var string 运行状态 running / shutting / shutdown.
     */
    protected $state = 'shutdown';

    /**
     * @param array $connectionOptions amqp server连接配置
     * [
     *      'host' => 'xxx',
     *      'port' => 123,
     *      'vhost' => 'yyy',
     *      'user' => 'zzz',
     *      'password' => 'uuu',
     *      'connections' => 1,                 // =1, 同时创建多少个连接. [可选]默认为1
     *      // 'reconnectOnError' => false / true, // [可选]默认为true
     *      // 'maxReconnectRetries => 1,          // >=1, 重连尝试次数,超过将抛出异常. [可选] 默认为3
     * ]
     * @param WorkerFactoryInterface $factory
     */
    public function __construct(array $connectionOptions, WorkerFactoryInterface $factory)
    {
        parent::__construct();

        $this->workerScheduler = new WorkerScheduler($this->workerCapacity);
        $this->workerFactory = $factory;
        $this->connectionOptions = $connectionOptions;

        $this->on('workerExit', function (string $workerID) {
            $this->emit('workerQuit', [$workerID, $this]);
            $pid = $this->getWorkerPID($workerID);
            $this->clearWorker($workerID, $pid ?? 0);
        });

        $this->addSignalHandler(SIGCHLD, function () {
            $this->tryWaitAndClear();
        });
    }

    public function run()
    {
        if ($this->state !== 'shutdown') {
            return;
        }

        $this->workersInfo = [];
        $this->idMap = [];
        $this->state = 'running';

        while ($this->state !== 'shutdown') {
            $this->process($this->patrolPeriod);

            // 补杀僵尸进程
            for ($i = 0, $len = count($this->idMap); $i < $len; $i++) {
                if (!$this->tryWaitAndClear()) {
                    break;
                }
            }
        }
    }

    public function shutdown()
    {
        $this->state = 'shutting';
        $this->disconnect();

        foreach ($this->workersInfo as $workerID => $each) {
            try {
                $this->sendMessage($workerID, new Message(MessageTypeEnum::LAST_MSG, ''));
            } catch (\Throwable $e) {
                // TODO 如果没有成功向所有进程发送关闭,考虑在其他地方需要做重试机制
                if ($this->logger) {
                    $msg = "Failed to send message(to {$workerID}). {$e->getMessage()}.";
                    $this->logger->error($msg, ['trace' => $e->getTrace()]);
                }
                $this->emit('errorShuttingDown', [$e, $this]);
            }
        }
    }

    /**
     * @param array $queues 要拉取消息的队列名称列表
     */
    public function connect(array $queues)
    {
        $this->queues = $queues;
        $connections = $this->connectionOptions['connections'] ?? 1;
        $options = array_intersect_key($this->connectionOptions, [
            'host' => true,
            'port' => true,
            'vhost' => true,
            'user' => true,
            'password' => true,
        ]);

        for ($i = 0; $i < $connections; $i++) {
            $this->makeConnection($options, $queues);
        }
    }

    public function disconnect()
    {
        foreach ($this->connectionInfo['connections'] as $index => $each) {
            /** @var Client $client */
            $client = $each['client'];
            $tags = $each['tags'];
            $client->disconnect()->done();
            unset($this->connectionInfo['connections'][$index]);
            foreach ($tags as $t) {
                unset($this->connectionInfo['tags'][$t]);
            }
        }
    }

    /**
     * @param array $queues
     */
    public function reconnect(array $queues)
    {
        $this->disconnect();
        $this->connect($queues);
    }

    public function onMessage(string $workerID, Message $message)
    {
        $type = $message->getType();

        try {
            switch ($type) {
                case MessageTypeEnum::PROCESSED:
                    $this->stat['processed']++;
                    if (isset($this->workersInfo[$workerID])) {
                        $this->workersInfo[$workerID]['processed']++;
                    }

                    $this->workerScheduler->release($workerID);
                    $this->emit('processed', [$workerID, $this]);
                    break;
                case MessageTypeEnum::STOP_SENDING:
                    $this->workerScheduler->retire($workerID);
                    try {
                        $this->sendMessage($workerID, new Message(MessageTypeEnum::LAST_MSG, json_encode([
                            'messageID' => Helper::uuid(),
                            'meta' => ['sent' => $this->workersInfo[$workerID]['sent']],
                        ])));
                    } catch (\Throwable $e) {
                        if ($this->logger) {
                            $this->logger->error($e->getMessage(), ['trace' => $e->getTrace()]);
                        }
                        $this->emit('errorSendingMessage', [$e, $this]);
                    }
                    break;
                case MessageTypeEnum::KILL_ME:
                    $this->killWorker($workerID, SIGKILL, true);
                    if ($this->state === 'shutting' && $this->countWorkers() === 0) {
                        $this->stopProcess();
                        $this->state = 'shutdown';
                    }
                    break;
                default:
                    $this->emit('message', [$workerID, $message, $this]);
            }
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error($e->getMessage(), [
                    'trace' => $e->getTrace(),
                ]);
            }
            $this->emit('errorMessageHandling', [$e, $this]);
        }
    }

    public function onConsume(AMQPMessage $message, Channel $channel, Client $client)
    {
        $reachedBefore = $this->limitReached();
        try {
            $workerID = $this->scheduleWorker();
        } catch (\Throwable $e) {
            return;
        }

        $messageContent = json_encode([
            'messageID' => Helper::uuid(),
            'meta' => [
                'amqp' => [
                    'exchange' => $message->exchange,
                    'queue' => $this->connectionInfo['tags'][$message->consumerTag],
                    'routingKey' => $message->routingKey,
                ],
                'sent' => $this->workersInfo[$workerID]['sent'] + 1,
            ],
            'content' => $message->content,
        ]);

        try {
            $this->sendMessage($workerID, new Message(MessageTypeEnum::QUEUE, $messageContent));
        } catch (\Throwable $e) {
            $errorOccurred = true;
            $this->workerScheduler->release($workerID);
            if ($this->logger) {
                $this->logger->error($e->getMessage(), ['trace' => $e->getTrace()]);
            }
            $this->emit('errorDispatchingMessage', [$e, $this]);
        }

        if (!($errorOccurred ?? false)) {
            $this->stat['consumed']++;
            $this->workersInfo[$workerID]['sent']++;
            $channel->ack($message)->done();

            if ($this->limitReached()) {
                $this->stopConsuming();
                // 边沿触发
                if (!$reachedBefore) {
                    $this->emit('limitReached', [$this->countWorkers(), $this]);
                }
            }
        }
    }

    /**
     * @param LoggerInterface $logger
     *
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * 设置进程数量上限.
     *
     * @param int $num >0时为上限值, =-1时为不限数量, 0或者<-1时不做任何操作.
     *
     * @return self
     */
    public function setMaxWorkers(int $num): self
    {
        if ($num > 0 || $num === -1) {
            $this->maxWorkers = $num;
        }

        return $this;
    }

    /**
     * 设置每个worker的消息队列容量.
     *
     * 例如: 当这个值为10,一个worker正在处理一条消息,dispatcher可以继续向它发送9条消息等待它处理.
     * 不建议将这个值设置的.
     *
     * @param int $n 必须大于等于1
     *
     * @return self
     */
    public function setWorkerCapacity(int $n): self
    {
        if ($n > 0) {
            $this->workerCapacity = $n;
        }

        return $this;
    }

    /**
     * 进行僵尸进程检查的周期.
     *
     * 例如设置为5分钟,那么每5分钟会调用pcntl_wait清理掉没有成功清除的僵尸进程
     *
     * @param int $seconds
     *
     * @return Dispatcher
     */
    public function setPatrolPeriod(int $seconds): self
    {
        if ($seconds > 0) {
            $this->patrolPeriod = $seconds;
        }

        return $this;
    }

    /**
     * @return array
     */
    public function getStat(): array
    {
        $stat = $this->stat;
        $stat['memoryUsage'] = memory_get_usage();
        $stat['peakMemoryUsage'] = memory_get_peak_usage();

        return $stat;
    }

    public function clearStat()
    {
        $this->stat = [
            'consumed' => 0,
            'processed' => 0,
        ];
    }

    /**
     * @return string
     */
    protected function getState(): string
    {
        return $this->state;
    }

    /**
     * 是否已到达派发消息上限.
     *
     * 上限是指创建的worker数量已达上限,并且已没有空闲worker可以调度
     *
     * @return bool
     */
    protected function limitReached(): bool
    {
        if ($this->maxWorkers === -1) {
            return false;
        }

        return $this->countWorkers() >= $this->maxWorkers
            && $this->workerScheduler->countSchedulable() === 0;
    }

    /**
     * @return bool
     */
    protected function tryWaitAndClear(): bool
    {
        $pid = pcntl_wait($status, WNOHANG);
        if ($pid > 0) {
            $this->clearWorker($this->idMap[$pid] ?? '', $pid);

            return true;
        }

        return false;
    }

    /**
     * @param string $workerID
     * @param int $pid
     */
    protected function clearWorker(string $workerID, int $pid)
    {
        $this->workerScheduler->remove($workerID);
        unset($this->workersInfo[$workerID]);
        unset($this->idMap[$pid]);
    }

    /**
     * 安排一个worker,如果没有空闲worker,创建一个.
     *
     * @return string worker id
     * @throws
     */
    protected function scheduleWorker(): string
    {
        while (($workerID = $this->workerScheduler->allocate()) !== null) {
            $c = $this->getCommunicator($workerID);
            if ($c && $c->isWritable()) {
                break;
            }
        }

        if (!$workerID) {
            try {
                $workerID = $this->createWorker($this->workerFactory);
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->error($e->getMessage(), ['trace' => $e->getTrace()]);
                }
                $this->emit('errorCreateWorker', [$e, $this]);
                throw $e;
            }
            $this->stat['peakWorkerNum'] = max($this->countWorkers(), $this->stat['peakWorkerNum'] + 1);

            $pid = $this->getWorkerPID($workerID);
            $this->workerScheduler->add($workerID, true);
            $this->idMap[$pid] = $workerID;
            $this->workersInfo[$workerID] = [
                'sent' => 0,
                'processed' => 0,
            ];
        }

        return $workerID;
    }

    /**
     * @param array $options
     * @param array $queues
     */
    protected function makeConnection(array $options, array $queues)
    {
        $onReject = function ($reason) {
            $msg = "Failed to connect AMQP server.";

            if (is_string($reason)) {
                $msg .= $reason;
            } else if ($reason instanceof \Throwable) {
                $prev = $reason;
                $context = ['trace' => $prev->getTrace()];
                $msg .= $reason->getMessage();
            }

            if ($this->logger) {
                $this->logger->error($msg, $context ?? null);
            }

            throw new \Exception($msg, 0, $prev ?? null);
        };

        $client = new Client($this->getEventLoop(), $options);
        $client->connect()
            ->then(function (Client $client) {
                return $client->channel();
            }, $onReject)
            ->then(function (Channel $channel) use ($onReject) {
                return $channel->qos(0, 1)->then(function () use ($channel) {
                    return $channel;
                }, $onReject);
            }, $onReject)
            ->then(function (Channel $channel) use ($client, $queues) {
                $tags = $this->bindConsumer($channel, $queues, [$this, 'onConsume']);
                $this->connectionInfo['connections'][] = [
                    'client' => $client,
                    'channel' => $channel,
                    'tags' => array_keys($tags),
                ];
                $this->connectionInfo['tags'] = array_merge($this->connectionInfo['tags'], $tags);
            }, $onReject)
            ->done(function () {}, $onReject);
    }

    /**
     * 停止消费队列消息.
     */
    protected function stopConsuming()
    {
        foreach ($this->connectionInfo['connections'] as $each) {
            $tags = $each['tags'];
            $this->unbindConsumer($each['channel'], $tags);
            foreach ($tags as $t) {
                unset($this->connectionInfo['tags'][$t]);
            }
        }
    }

    /**
     * 恢复消费队列消息
     */
    protected function resumeConsuming()
    {
        foreach ($this->connectionInfo['connections'] as $index => $each) {
            $tags = $this->bindConsumer($each['channel'], $this->queues, [$this, 'onConsume']);
            $this->connectionInfo['connections'][$index]['tags'] = array_keys($tags);
            $this->connectionInfo['tags'] = array_merge($this->connectionInfo['tags'], $tags);
        }
    }

    /**
     * @param Channel $channel
     * @param array $queues
     * @param callable $handler
     *
     * @return array
     */
    protected function bindConsumer(Channel $channel, array $queues, callable $handler): array
    {
        $tags = [];
        foreach ($queues as $queueName) {
            $tag = Helper::uuid();
            $channel->consume($handler, $queueName, $tag)->done();
            $tags[$tag] = $queueName;
        }

        return $tags;
    }

    /**
     * @param Channel $channel
     * @param array $tags
     */
    protected function unbindConsumer(Channel $channel, array $tags)
    {
        foreach ($tags as $tag) {
            $channel->cancel($tag)->done();
        }
    }
}