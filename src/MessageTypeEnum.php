<?php

declare(strict_types=1);

namespace Archman\BugsBunny;

class MessageTypeEnum
{
    // 正常队列消息
    const QUEUE = 0;

    // worker已处理完消息
    const PROCESSED = 1;

    // worker告知dispatcher不要投放新消息
    const STOP_SENDING = 2;

    // dispatcher告知worker不再向他发送消息
    const LAST_MSG = 3;

    // 对于被动退出模式,worker进程处理完所有内容,请求dispatcher杀死自己
    const KILL_ME = 4;

    // 对于主动退出模式,worker进程通知dispatcher准备退出
    const I_AM_QUIT = 5;

    // 对于主动退出模式,dispatcher收到I_AM_QUIT消息后,告知dispatcher准备好接受worker的退出
    const ROGER_THAT = 6;

    // 已接收但尚未处理完的消息
    const ACCEPT = 7;
}