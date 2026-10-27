<?php

declare(strict_types=1);

namespace Core\Workflow;

use Exception;

class WorkflowException extends Exception
{
    protected ?string $entityType = null;
    protected ?string $fromStatus = null;
    protected ?string $toStatus = null;

    public function __construct(
        string $message,
        ?string $entityType = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        int $code = 422,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->entityType = $entityType;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function getFromStatus(): ?string
    {
        return $this->fromStatus;
    }

    public function getToStatus(): ?string
    {
        return $this->toStatus;
    }
}
