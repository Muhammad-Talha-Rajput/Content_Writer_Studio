<?php
namespace App\Services;
class AiProviderException extends \RuntimeException
{
    public function __construct(public string $reason, string $message, public bool $transient = false, public array $usage = []) { parent::__construct($message); }
}
