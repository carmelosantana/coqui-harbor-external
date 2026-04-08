<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\CoquiHarborExternal\HarborToolkit;

// ──────────────────────────────────────────────────
// Toolkit Interface & Registration
// ──────────────────────────────────────────────────

test('implements ToolkitInterface', function () {
    $toolkit = new HarborToolkit();

    expect($toolkit)->toBeInstanceOf(ToolkitInterface::class);
});

test('tools returns 14 tools', function () {
    $toolkit = new HarborToolkit();
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(14);
});

test('tools have expected names', function () {
    $toolkit = new HarborToolkit();
    $names = array_map(fn ($t) => $t->name(), $toolkit->tools());

    expect($names)->toBe([
        // Discovery & Validation
        'harbor_check',
        'harbor_task_validate',
        'harbor_dataset_list',
        // Task Authoring
        'harbor_task_init',
        'harbor_task_list',
        'harbor_task_delete',
        // Execution
        'harbor_run',
        'harbor_run_status',
        'harbor_view',
        // Analysis
        'harbor_results',
        'harbor_trial_inspect',
        'harbor_compare',
        'harbor_failures',
        'harbor_cleanup',
    ]);
});

test('guidelines contains key workflow sections', function () {
    $toolkit = new HarborToolkit();
    $guidelines = $toolkit->guidelines();

    expect($guidelines)
        ->toContain('HARBOR-GUIDELINES')
        ->toContain('Harbor Benchmarking Workflow')
        ->toContain('harbor_check')
        ->toContain('harbor_run')
        ->toContain('Harbor Task Structure')
        ->toContain('instruction.md')
        ->toContain('task.toml');
});

// ──────────────────────────────────────────────────
// Task Validation
// ──────────────────────────────────────────────────

test('task validate detects missing required files', function () {
    $tmpDir = sys_get_temp_dir() . '/harbor-test-' . bin2hex(random_bytes(4));
    mkdir($tmpDir, 0755, true);

    try {
        $toolkit = new HarborToolkit();
        $tool = findTool($toolkit, 'harbor_task_validate');
        $result = $tool->execute(['path' => $tmpDir]);

        $data = json_decode($result->content, true);

        expect($data['valid'])->toBeFalse()
            ->and($data['issues'])->toContain('Missing required file: instruction.md')
            ->and($data['issues'])->toContain('Missing required file: task.toml')
            ->and($data['issues'])->toContain('Missing required directory: environment/')
            ->and($data['issues'])->toContain('Missing required directory: tests/');
    } finally {
        rmdir($tmpDir);
    }
});

test('task validate passes on valid structure', function () {
    $tmpDir = sys_get_temp_dir() . '/harbor-test-valid-' . bin2hex(random_bytes(4));
    mkdir($tmpDir, 0755, true);
    mkdir($tmpDir . '/environment', 0755, true);
    mkdir($tmpDir . '/tests', 0755, true);
    mkdir($tmpDir . '/solution', 0755, true);

    // Copy fixture task.toml
    copy(__DIR__ . '/../Fixtures/task.toml', $tmpDir . '/task.toml');
    file_put_contents($tmpDir . '/instruction.md', 'Create a hello world script.');
    file_put_contents($tmpDir . '/environment/Dockerfile', 'FROM php:8.4-cli');
    file_put_contents($tmpDir . '/tests/test.sh', '#!/bin/bash\necho 1.0 > reward.txt');

    try {
        $toolkit = new HarborToolkit();
        $tool = findTool($toolkit, 'harbor_task_validate');
        $result = $tool->execute(['path' => $tmpDir]);

        $data = json_decode($result->content, true);

        expect($data['valid'])->toBeTrue()
            ->and($data['issues'])->toBeEmpty()
            ->and($data['info']['task_name'])->toBe('coqui/file-editing-01')
            ->and($data['info']['task_description'])->toBe('Create a Python script that prints hello world')
            ->and($data['info']['agent_timeout_sec'])->toBe(300)
            ->and($data['info']['has_solution'])->toBeTrue()
            ->and($data['info']['environment_type'])->toBe('Dockerfile');
    } finally {
        deleteDir($tmpDir);
    }
});

test('task validate returns error for missing path', function () {
    $toolkit = new HarborToolkit();
    $tool = findTool($toolkit, 'harbor_task_validate');
    $result = $tool->execute(['path' => '']);

    expect($result->content)->toContain('Task path is required');
});

test('task validate returns error for nonexistent directory', function () {
    $toolkit = new HarborToolkit();
    $tool = findTool($toolkit, 'harbor_task_validate');
    $result = $tool->execute(['path' => '/nonexistent/path/xyz123']);

    expect($result->content)->toContain('Task directory not found');
});

// ──────────────────────────────────────────────────
// Task Listing
// ──────────────────────────────────────────────────

test('task list discovers tasks in a dataset directory', function () {
    $tmpDir = sys_get_temp_dir() . '/harbor-dataset-' . bin2hex(random_bytes(4));
    mkdir($tmpDir, 0755, true);

    // Create two task directories
    $task1 = $tmpDir . '/task-one';
    mkdir($task1, 0755, true);
    copy(__DIR__ . '/../Fixtures/task.toml', $task1 . '/task.toml');
    file_put_contents($task1 . '/instruction.md', 'Task one instruction.');

    $task2 = $tmpDir . '/task-two';
    mkdir($task2, 0755, true);
    file_put_contents($task2 . '/instruction.md', 'Task two instruction.');

    // Non-task directory (no task.toml or instruction.md)
    mkdir($tmpDir . '/not-a-task', 0755, true);
    file_put_contents($tmpDir . '/not-a-task/readme.txt', 'ignore me');

    try {
        $toolkit = new HarborToolkit();
        $tool = findTool($toolkit, 'harbor_task_list');
        $result = $tool->execute(['path' => $tmpDir]);

        $data = json_decode($result->content, true);

        expect($data['task_count'])->toBe(2)
            ->and($data['tasks'])->toHaveCount(2);

        $names = array_column($data['tasks'], 'name');
        sort($names);
        expect($names)->toBe(['task-one', 'task-two']);
    } finally {
        deleteDir($tmpDir);
    }
});

test('task list returns empty for directory with no tasks', function () {
    $tmpDir = sys_get_temp_dir() . '/harbor-empty-' . bin2hex(random_bytes(4));
    mkdir($tmpDir, 0755, true);

    try {
        $toolkit = new HarborToolkit();
        $tool = findTool($toolkit, 'harbor_task_list');
        $result = $tool->execute(['path' => $tmpDir]);

        $data = json_decode($result->content, true);

        expect($data['task_count'])->toBe(0)
            ->and($data['message'])->toContain('No Harbor tasks found');
    } finally {
        rmdir($tmpDir);
    }
});

// ──────────────────────────────────────────────────
// Results Parsing
// ──────────────────────────────────────────────────

test('results parses a mock job directory', function () {
    $jobDir = createMockJobDir([
        'trial-pass-1' => ['reward' => 1.0, 'duration' => 95.3],
        'trial-pass-2' => ['reward' => 1.0, 'duration' => 70.1],
        'trial-fail-1' => ['reward' => 0.0, 'duration' => 180.6],
    ]);

    try {
        $toolkit = new HarborToolkit();
        $tool = findTool($toolkit, 'harbor_results');
        $result = $tool->execute(['job_dir' => $jobDir, 'include_trials' => true]);

        $data = json_decode($result->content, true);

        expect($data['total_trials'])->toBe(3)
            ->and($data['passed'])->toBe(2)
            ->and($data['failed'])->toBe(1)
            ->and($data['pass_rate'])->toBe(66.7)
            ->and($data['rewards']['min'])->toEqual(0.0)
            ->and($data['rewards']['max'])->toEqual(1.0)
            ->and($data['trials'])->toHaveCount(3);
    } finally {
        deleteDir($jobDir);
    }
});

test('results returns error for missing job directory', function () {
    $toolkit = new HarborToolkit();
    $tool = findTool($toolkit, 'harbor_results');
    $result = $tool->execute(['job_dir' => '/nonexistent/job']);

    expect($result->content)->toContain('Job directory not found');
});

// ──────────────────────────────────────────────────
// Reward Reading
// ──────────────────────────────────────────────────

test('reads reward from reward.txt', function () {
    $jobDir = createMockJobDir([
        'trial-txt' => ['reward' => 0.75, 'duration' => 50.0, 'reward_format' => 'txt'],
    ]);

    try {
        $toolkit = new HarborToolkit();
        $tool = findTool($toolkit, 'harbor_results');
        $result = $tool->execute(['job_dir' => $jobDir, 'include_trials' => true]);

        $data = json_decode($result->content, true);
        expect($data['trials'][0]['reward'])->toBe(0.75);
    } finally {
        deleteDir($jobDir);
    }
});

test('reads reward from reward.json', function () {
    $jobDir = createMockJobDir([
        'trial-json' => ['reward' => 0.5, 'duration' => 60.0, 'reward_format' => 'json'],
    ]);

    try {
        $toolkit = new HarborToolkit();
        $tool = findTool($toolkit, 'harbor_results');
        $result = $tool->execute(['job_dir' => $jobDir, 'include_trials' => true]);

        $data = json_decode($result->content, true);
        expect($data['trials'][0]['reward'])->toBe(0.5);
    } finally {
        deleteDir($jobDir);
    }
});

// ──────────────────────────────────────────────────
// Run Status
// ──────────────────────────────────────────────────

test('run status reports completed job', function () {
    $jobDir = createMockJobDir([
        'trial-1' => ['reward' => 1.0, 'duration' => 50.0],
    ]);

    // Add job-level result.json to mark completion
    file_put_contents($jobDir . '/result.json', json_encode(['status' => 'completed']));

    try {
        $toolkit = new HarborToolkit();
        $tool = findTool($toolkit, 'harbor_run_status');
        $result = $tool->execute(['job_dir' => $jobDir]);

        $data = json_decode($result->content, true);

        expect($data['status'])->toBe('completed')
            ->and($data['has_result'])->toBeTrue()
            ->and($data['trials_total'])->toBe(1)
            ->and($data['trials_completed'])->toBe(1)
            ->and($data['progress_percent'])->toEqual(100.0);
    } finally {
        deleteDir($jobDir);
    }
});

// ──────────────────────────────────────────────────
// Comparison
// ──────────────────────────────────────────────────

test('compare requires at least two job directories', function () {
    $toolkit = new HarborToolkit();
    $tool = findTool($toolkit, 'harbor_compare');
    $result = $tool->execute(['job_dirs' => '/some/single/dir']);

    expect($result->content)->toContain('At least two job directories are required');
});

test('compare detects regressions between runs', function () {
    $jobA = createMockJobDir(['task-1' => ['reward' => 1.0, 'duration' => 50.0]]);
    $jobB = createMockJobDir(['task-1' => ['reward' => 0.0, 'duration' => 80.0]]);

    try {
        $toolkit = new HarborToolkit();
        $tool = findTool($toolkit, 'harbor_compare');
        $result = $tool->execute(['job_dirs' => "{$jobA},{$jobB}"]);

        $data = json_decode($result->content, true);

        expect($data['task_count'])->toBe(1)
            ->and($data['comparison'][0]['reward_delta'])->toEqual(-1.0)
            ->and($data['comparison'][0]['regression'])->toBeTrue();
    } finally {
        deleteDir($jobA);
        deleteDir($jobB);
    }
});

// ──────────────────────────────────────────────────
// Failures
// ──────────────────────────────────────────────────

test('failures extracts failed trials', function () {
    $jobDir = createMockJobDir([
        'trial-pass' => ['reward' => 1.0, 'duration' => 50.0],
        'trial-fail' => ['reward' => 0.0, 'duration' => 120.0],
    ]);

    // Add verifier stderr for the failed trial
    if (!is_dir($jobDir . '/trial-fail/verifier')) {
        mkdir($jobDir . '/trial-fail/verifier', 0755, true);
    }
    file_put_contents($jobDir . '/trial-fail/verifier/test-stderr.txt', 'AssertionError: expected output mismatch');

    try {
        $toolkit = new HarborToolkit();
        $tool = findTool($toolkit, 'harbor_failures');
        $result = $tool->execute(['job_dir' => $jobDir]);

        $data = json_decode($result->content, true);

        expect($data['failure_count'])->toBe(1)
            ->and($data['failures'][0]['trial'])->toBe('trial-fail')
            ->and($data['failures'][0]['reward'])->toEqual(0.0)
            ->and($data['failures'][0]['verifier_stderr'])->toContain('AssertionError');
    } finally {
        deleteDir($jobDir);
    }
});

// ──────────────────────────────────────────────────
// Cleanup Dry Run
// ──────────────────────────────────────────────────

test('cleanup dry run lists old jobs without deleting', function () {
    $jobsDir = sys_get_temp_dir() . '/harbor-jobs-' . bin2hex(random_bytes(4));
    mkdir($jobsDir, 0755, true);

    // Create an "old" job directory and back-date it
    $oldJob = $jobsDir . '/old-job';
    mkdir($oldJob, 0755, true);
    touch($oldJob, time() - (60 * 86400)); // 60 days ago

    // Create a "recent" job directory
    $newJob = $jobsDir . '/new-job';
    mkdir($newJob, 0755, true);

    try {
        $toolkit = new HarborToolkit();
        $tool = findTool($toolkit, 'harbor_cleanup');
        $result = $tool->execute([
            'jobs_dir' => $jobsDir,
            'older_than_days' => 30,
            'dry_run' => true,
        ]);

        $data = json_decode($result->content, true);

        expect($data['dry_run'])->toBeTrue()
            ->and($data['would_delete'])->toBe(1)
            ->and($data['candidates'][0]['name'])->toBe('old-job');

        // Verify nothing was actually deleted
        expect(is_dir($oldJob))->toBeTrue();
    } finally {
        deleteDir($jobsDir);
    }
});

// ──────────────────────────────────────────────────
// Task Init Validation
// ──────────────────────────────────────────────────

test('task init rejects invalid name format', function () {
    $toolkit = new HarborToolkit();
    $tool = findTool($toolkit, 'harbor_task_init');
    $result = $tool->execute(['name' => 'no-slash']);

    expect($result->content)->toContain('org/name');
});

test('task init rejects empty name', function () {
    $toolkit = new HarborToolkit();
    $tool = findTool($toolkit, 'harbor_task_init');
    $result = $tool->execute(['name' => '']);

    expect($result->content)->toContain('Task name is required');
});

// ──────────────────────────────────────────────────
// Harbor Run Validation
// ──────────────────────────────────────────────────

test('harbor run requires at least one source', function () {
    $toolkit = new HarborToolkit();
    $tool = findTool($toolkit, 'harbor_run');
    $result = $tool->execute([]);

    expect($result->content)->toContain('Provide at least one of');
});

test('harbor run rejects both dataset and path', function () {
    $toolkit = new HarborToolkit();
    $tool = findTool($toolkit, 'harbor_run');
    $result = $tool->execute(['dataset' => 'some/dataset', 'path' => '/some/path']);

    expect($result->content)->toContain('Cannot specify both dataset and path');
});

// ──────────────────────────────────────────────────
// Task Delete Safety
// ──────────────────────────────────────────────────

test('task delete refuses non-task directories', function () {
    $tmpDir = sys_get_temp_dir() . '/harbor-delete-test-' . bin2hex(random_bytes(4));
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/random.txt', 'not a task');

    try {
        $toolkit = new HarborToolkit();
        $tool = findTool($toolkit, 'harbor_task_delete');
        $result = $tool->execute(['path' => $tmpDir]);

        expect($result->content)->toContain('does not appear to be a Harbor task directory');
        expect(is_dir($tmpDir))->toBeTrue();
    } finally {
        deleteDir($tmpDir);
    }
});

// ──────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────

function findTool(HarborToolkit $toolkit, string $name): \CarmeloSantana\PHPAgents\Contract\ToolInterface
{
    foreach ($toolkit->tools() as $tool) {
        if ($tool->name() === $name) {
            return $tool;
        }
    }
    throw new RuntimeException("Tool not found: {$name}");
}

/**
 * Create a mock job directory with trial subdirectories.
 *
 * @param array<string, array{reward: float, duration: float, reward_format?: string}> $trials
 */
function createMockJobDir(array $trials): string
{
    $jobDir = sys_get_temp_dir() . '/harbor-job-' . bin2hex(random_bytes(4));
    mkdir($jobDir, 0755, true);

    // Add job config
    file_put_contents($jobDir . '/config.json', json_encode([
        'agent' => 'coqui',
        'model' => 'test-model',
    ]));

    foreach ($trials as $name => $trial) {
        $trialDir = $jobDir . '/' . $name;
        mkdir($trialDir, 0755, true);

        // Trial config (marks this as a trial directory)
        file_put_contents($trialDir . '/config.json', json_encode(['task' => $name]));

        // Trial result
        file_put_contents($trialDir . '/result.json', json_encode([
            'task' => $name,
            'status' => 'completed',
            'duration_sec' => $trial['duration'],
        ]));

        // Reward file
        $format = $trial['reward_format'] ?? 'txt';
        $verifierDir = $trialDir . '/verifier';
        mkdir($verifierDir, 0755, true);

        if ($format === 'json') {
            file_put_contents($verifierDir . '/reward.json', json_encode(['reward' => $trial['reward']]));
        } else {
            file_put_contents($verifierDir . '/reward.txt', (string) $trial['reward']);
        }
    }

    return $jobDir;
}

function deleteDir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $fullPath = $path . '/' . $entry;
        if (is_dir($fullPath)) {
            deleteDir($fullPath);
        } else {
            unlink($fullPath);
        }
    }
    rmdir($path);
}
