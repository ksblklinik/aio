<?php

declare(strict_types=1);

namespace Core\Workflow;

use Closure;

class WorkflowDefinition
{
    protected string $name;
    protected array $states = [];
    protected string $initialState = '';
    protected array $transitions = [];

    public function __construct(string $name, array $config = [])
    {
        $this->name = $name;

        if (!empty($config['states'])) {
            $this->states = $config['states'];
            $this->initialState = $config['initial'] ?? $config['states'][0];
        }

        if (!empty($config['transitions'])) {
            foreach ($config['transitions'] as $transName => $transConfig) {
                if (is_int($transName)) {
                    $transName = $transConfig['name'] ?? 'trans_' . $transName;
                }
                $this->addTransition(
                    $transName,
                    (array) ($transConfig['from'] ?? []),
                    (string) ($transConfig['to'] ?? ''),
                    (array) ($transConfig['guards'] ?? [])
                );
            }
        }
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function states(array $states): self
    {
        $this->states = $states;
        if (empty($this->initialState) && !empty($states)) {
            $this->initialState = $states[0];
        }
        return $this;
    }

    public function initial(string $state): self
    {
        $this->initialState = $state;
        if (!in_array($state, $this->states, true)) {
            $this->states[] = $state;
        }
        return $this;
    }

    public function addTransition(string $name, array $from, string $to, array $guards = []): self
    {
        foreach ($from as $state) {
            if ($state !== '*' && !in_array($state, $this->states, true)) {
                $this->states[] = $state;
            }
        }
        if (!in_array($to, $this->states, true)) {
            $this->states[] = $to;
        }

        $this->transitions[$name] = [
            'name' => $name,
            'from' => $from,
            'to' => $to,
            'guards' => $guards,
        ];

        return $this;
    }

    public function getStates(): array
    {
        return $this->states;
    }

    public function getInitialState(): string
    {
        return $this->initialState;
    }

    public function getTransitions(): array
    {
        return $this->transitions;
    }

    public function hasState(string $state): bool
    {
        return in_array($state, $this->states, true);
    }

    /**
     * Find transition by name OR by (fromStatus, toStatus).
     */
    public function findTransition(string $fromStatus, string $transitionOrToStatus): ?array
    {
        // 1. By transition name
        if (isset($this->transitions[$transitionOrToStatus])) {
            $trans = $this->transitions[$transitionOrToStatus];
            if (in_array('*', $trans['from'], true) || in_array($fromStatus, $trans['from'], true)) {
                return $trans;
            }
        }

        // 2. By matching from -> to
        foreach ($this->transitions as $trans) {
            $fromMatches = in_array('*', $trans['from'], true) || in_array($fromStatus, $trans['from'], true);
            if ($fromMatches && $trans['to'] === $transitionOrToStatus) {
                return $trans;
            }
        }

        return null;
    }
}
