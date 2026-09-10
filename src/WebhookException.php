<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * フォーム連携Webhookの業務エラー。
 * $errorCode はレスポンスの機械可読コード（例: out_of_order）、メッセージは人間向け説明。
 */
final class WebhookException extends RuntimeException
{
    public function __construct(private string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
