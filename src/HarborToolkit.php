<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiHarborExternal;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

/**
 * Harbor benchmarking toolkit for Coqui.
 *
 * Wraps the Harbor CLI to provide agent-facing tools for managing tasks,
 * running evaluations, and analyzing benchmark results. Treats Harbor as
 * a CLI tool and reads its well-defined output format (JSON result files,
 * log directories, reward files).
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 *
 * @see https://harborframework.com/docs
 */
final class HarborToolkit implements ToolkitInterface
{
    private const string DEFAULT_JOBS_DIR = 'jobs';
    private const int PROCESS_TIMEOUT = 30;
    private const int MAX_LOG_LINES = 100;
    private const int MAX_TRAJECTORY_STEPS = 20;
    private const int MAX_FAILURES = 50;

    public function __construct(
        private readonly string $jobsDir = self::DEFAULT_JOBS_DIR,
    ) {}

    public function tools(): array
    {
        return [
            // Discovery & Validation
            $this->harborCheckTool(),
            $this->harborTaskValidateTool(),
            $this->harborDatasetListTool(),
            // Task Authoring
            $this->harborTaskInitTool(),
            $this->harborTaskListTool(),
            $this->harborTaskDeleteTool(),
            // Execution
            $this->harborRunTool(),
            $this->harborRunStatusTool(),
            $this->harborViewTool(),
            // Analysis
            $this->harborResultsTool(),
            $this->harborTrialInspectTool(),
            $this->harborCompareTool(),
            $this->harborFailuresTool(),
            $this->harborCleanupTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
        <HARBOR-GUIDELINES>
        ## Harbor Benchmarking Workflow

        Recommended tool chaining order:
        1. `harbor_check` — verify Harbor CLI is installed and accessible
        2. `harbor_task_validate` — validate task directory structure before running
        3. `harbor_run` — execute an evaluation (use background tools for long-running evals)
        4. `harbor_results` — parse job results into a structured summary
        5. `harbor_failures` — extract failed trials for triage
        6. `harbor_compare` — compare multiple jobs for regression detection

        ### Key Patterns
        - Always validate tasks before running: `harbor_task_validate` catches structural issues early.
        - Use background tools for `harbor_run` — evals can take minutes to hours depending on task count and complexity.
        - After a run completes, create a Coqui artifact with `harbor_results` output for versioned tracking.
        - Use `harbor_compare` to detect regressions between runs (e.g., before/after a code change).
        - Store benchmark plans as `plan` artifacts and track individual tasks as todos for structured campaigns.

        ### Job Directory Convention
        - Harbor stores results in a `jobs/` directory by default.
        - Each job contains trial subdirectories with `result.json`, `agent/`, and `verifier/` folders.
        - Use `harbor_trial_inspect` to drill into individual trial logs and trajectories.

        ### Harbor Task Structure
        A valid Harbor task directory contains:
        - `instruction.md` — task instruction for the agent
        - `task.toml` — configuration and metadata
        - `environment/` — Dockerfile or environment definition
        - `tests/` — test.sh that produces reward.txt or reward.json
        - `solution/` (optional) — reference solution for Oracle agent
        </HARBOR-GUIDELINES>
        GUIDELINES;
    }

    // ──────────────────────────────────────────────
    // Discovery & Validation Tools
    // ──────────────────────────────────────────────

    private function harborCheckTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_check',
            description: 'Verify that the Harbor CLI is installed and accessible. Returns the Harbor version, Python version, and whether Docker is available.',
            parameters: [],
            callback: fn (array $input): ToolResult => $this->executeHarborCheck(),
        );
    }

    private function harborTaskValidateTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_task_validate',
            description: 'Validate a Harbor task directory has the required structure: instruction.md, task.toml, environment/, and tests/. Parses task.toml and reports configuration issues.',
            parameters: [
                new StringParameter('path', 'Path to the task directory to validate'),
            ],
            callback: fn (array $input): ToolResult => $this->executeTaskValidate($input),
        );
    }

    private function harborDatasetListTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_dataset_list',
            description: 'List registered datasets from the Harbor registry. Shows available benchmarks that can be run with harbor_run.',
            parameters: [],
            callback: fn (array $input): ToolResult => $this->executeDatasetList(),
        );
    }

    // ──────────────────────────────────────────────
    // Task Authoring Tools
    // ──────────────────────────────────────────────

    private function harborTaskInitTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_task_init',
            description: 'Scaffold a new Harbor task directory with the standard structure (instruction.md, task.toml, environment/, solution/, tests/).',
            parameters: [
                new StringParameter('name', 'Task name in org/name format (e.g. "coqui/file-editing")'),
                new StringParameter(
                    'path',
                    'Directory where the task should be created. Defaults to current directory.',
                    required: false,
                ),
                new StringParameter(
                    'metadata_template',
                    'Path to a TOML template file to pre-populate task.toml with defaults',
                    required: false,
                ),
            ],
            callback: fn (array $input): ToolResult => $this->executeTaskInit($input),
        );
    }

    private function harborTaskListTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_task_list',
            description: 'List all tasks in a local dataset directory. Shows each task name, description, agent timeout, and verifier timeout from task.toml.',
            parameters: [
                new StringParameter('path', 'Path to the dataset directory containing task subdirectories'),
            ],
            callback: fn (array $input): ToolResult => $this->executeTaskList($input),
        );
    }

    private function harborTaskDeleteTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_task_delete',
            description: 'Delete a Harbor task directory. This is destructive and cannot be undone.',
            parameters: [
                new StringParameter('path', 'Path to the task directory to delete'),
            ],
            callback: fn (array $input): ToolResult => $this->executeTaskDelete($input),
        );
    }

    // ──────────────────────────────────────────────
    // Execution Tools
    // ──────────────────────────────────────────────

    private function harborRunTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_run',
            description: 'Run a Harbor evaluation. This spawns Docker containers and executes agent trials against tasks. For long-running evaluations, use this via a background tool. Returns the job directory path for subsequent analysis.',
            parameters: [
                new StringParameter(
                    'dataset',
                    'Registered dataset to run (e.g. "terminal-bench/terminal-bench-2"). Mutually exclusive with path.',
                    required: false,
                ),
                new StringParameter(
                    'path',
                    'Path to a local dataset or single task directory. Mutually exclusive with dataset.',
                    required: false,
                ),
                new StringParameter(
                    'agent',
                    'Agent name (built-in) or import path for a custom agent (e.g. "coqui_harbor_agent.agent:CoquiExternalAgent")',
                    required: false,
                ),
                new StringParameter(
                    'model',
                    'Model to use for the agent (e.g. "anthropic/claude-sonnet-4-20250514", "openai/gpt-4o")',
                    required: false,
                ),
                new NumberParameter(
                    'concurrency',
                    'Number of concurrent trials to run',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: 64,
                ),
                new StringParameter(
                    'env_type',
                    'Environment type for trial execution (e.g. "docker", "daytona", "modal")',
                    required: false,
                ),
                new StringParameter(
                    'job_config',
                    'Path to a job.yaml or job.json configuration file. Overrides other flags.',
                    required: false,
                ),
                new StringParameter(
                    'jobs_dir',
                    'Directory to store job results. Defaults to "jobs".',
                    required: false,
                ),
                new StringParameter(
                    'extra_args',
                    'Additional CLI arguments to pass to harbor run (e.g. "--agent-import-path path:Agent")',
                    required: false,
                ),
            ],
            callback: fn (array $input): ToolResult => $this->executeHarborRun($input),
        );
    }

    private function harborRunStatusTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_run_status',
            description: 'Check the status of a Harbor job by inspecting its output directory. Reports whether the job has a result.json (completed) and trial completion progress.',
            parameters: [
                new StringParameter('job_dir', 'Path to the job directory to check'),
            ],
            callback: fn (array $input): ToolResult => $this->executeRunStatus($input),
        );
    }

    private function harborViewTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_view',
            description: 'Launch Harbor\'s web-based results viewer for browsing jobs, inspecting trials, viewing trajectories, and comparing runs.',
            parameters: [
                new StringParameter(
                    'jobs_dir',
                    'Directory containing job results. Defaults to "jobs".',
                    required: false,
                ),
                new NumberParameter(
                    'port',
                    'Port for the viewer web server. Defaults to 8080.',
                    required: false,
                    integer: true,
                    minimum: 1024,
                    maximum: 65535,
                ),
            ],
            callback: fn (array $input): ToolResult => $this->executeView($input),
        );
    }

    // ──────────────────────────────────────────────
    // Analysis Tools
    // ──────────────────────────────────────────────

    private function harborResultsTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_results',
            description: 'Parse a Harbor job\'s results. Returns a structured summary with: total trials, pass/fail counts, reward distribution (min/max/mean/median), average duration, and per-trial details.',
            parameters: [
                new StringParameter('job_dir', 'Path to the job directory containing trial results'),
                new BoolParameter(
                    'include_trials',
                    'Include per-trial detail in the output',
                    required: false,
                ),
            ],
            callback: fn (array $input): ToolResult => $this->executeResults($input),
        );
    }

    private function harborTrialInspectTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_trial_inspect',
            description: 'Inspect a specific trial\'s detailed output: agent trajectory (tool calls and observations), verifier stdout/stderr, reward file, and configuration.',
            parameters: [
                new StringParameter('trial_dir', 'Path to the trial directory (e.g. "jobs/my-job/trial-name")'),
                new BoolParameter(
                    'include_trajectory',
                    'Include the agent\'s execution trajectory (tool calls, observations). Defaults to true.',
                    required: false,
                ),
                new NumberParameter(
                    'trajectory_steps',
                    'Maximum number of trajectory steps to include (most recent). Defaults to 20.',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: 100,
                ),
            ],
            callback: fn (array $input): ToolResult => $this->executeTrialInspect($input),
        );
    }

    private function harborCompareTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_compare',
            description: 'Compare two or more Harbor job directories. Produces a task-by-task comparison matrix showing reward deltas, duration changes, and pass/fail flips between runs.',
            parameters: [
                new StringParameter('job_dirs', 'Comma-separated paths to job directories to compare (e.g. "jobs/run-a,jobs/run-b")'),
            ],
            callback: fn (array $input): ToolResult => $this->executeCompare($input),
        );
    }

    private function harborFailuresTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_failures',
            description: 'Extract all failed trials from a Harbor job. For each failure: task name, reward value, verifier stderr excerpt, and agent trajectory summary (last N steps).',
            parameters: [
                new StringParameter('job_dir', 'Path to the job directory to analyze'),
                new NumberParameter(
                    'max_failures',
                    'Maximum number of failures to return. Defaults to 50.',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: 200,
                ),
                new NumberParameter(
                    'trajectory_tail',
                    'Number of final trajectory steps to include per failure. Defaults to 5.',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: 50,
                ),
            ],
            callback: fn (array $input): ToolResult => $this->executeFailures($input),
        );
    }

    private function harborCleanupTool(): ToolInterface
    {
        return new Tool(
            name: 'harbor_cleanup',
            description: 'Delete old Harbor job directories older than a specified age. This is destructive and cannot be undone.',
            parameters: [
                new StringParameter(
                    'jobs_dir',
                    'Directory containing job results. Defaults to "jobs".',
                    required: false,
                ),
                new NumberParameter(
                    'older_than_days',
                    'Delete jobs older than this many days. Defaults to 30.',
                    required: false,
                    integer: true,
                    minimum: 1,
                ),
                new BoolParameter(
                    'dry_run',
                    'If true, list what would be deleted without actually deleting. Defaults to false.',
                    required: false,
                ),
            ],
            callback: fn (array $input): ToolResult => $this->executeCleanup($input),
        );
    }

    // ──────────────────────────────────────────────
    // Discovery & Validation Implementations
    // ──────────────────────────────────────────────

    private function executeHarborCheck(): ToolResult
    {
        $results = [];

        // Check Harbor CLI
        $harborVersion = $this->runCommand('harbor --version');
        $results['harbor'] = $harborVersion !== null
            ? ['installed' => true, 'version' => trim($harborVersion)]
            : ['installed' => false, 'error' => 'Harbor CLI not found. Install with: uv tool install harbor'];

        // Check Python
        $pythonVersion = $this->runCommand('python3 --version') ?? $this->runCommand('python --version');
        $results['python'] = $pythonVersion !== null
            ? ['installed' => true, 'version' => trim($pythonVersion)]
            : ['installed' => false];

        // Check Docker
        $dockerVersion = $this->runCommand('docker --version');
        $results['docker'] = $dockerVersion !== null
            ? ['installed' => true, 'version' => trim($dockerVersion)]
            : ['installed' => false, 'error' => 'Docker not found — required for local Harbor evaluations'];

        // Check uv
        $uvVersion = $this->runCommand('uv --version');
        $results['uv'] = $uvVersion !== null
            ? ['installed' => true, 'version' => trim($uvVersion)]
            : ['installed' => false];

        $allGood = $results['harbor']['installed'] && $results['docker']['installed'];

        return ToolResult::success($this->jsonEncode([
            'ready' => $allGood,
            'dependencies' => $results,
        ]));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeTaskValidate(array $input): ToolResult
    {
        $path = $input['path'] ?? '';

        if ($path === '') {
            return ToolResult::error('Task path is required.');
        }

        if (!is_dir($path)) {
            return ToolResult::error("Task directory not found: {$path}");
        }

        $issues = [];
        $info = ['path' => $path];

        // Check required files
        $requiredFiles = ['instruction.md', 'task.toml'];
        $requiredDirs = ['environment', 'tests'];
        $optionalDirs = ['solution'];

        foreach ($requiredFiles as $file) {
            $filePath = $path . '/' . $file;
            if (!is_file($filePath)) {
                $issues[] = "Missing required file: {$file}";
            } elseif ($file === 'instruction.md') {
                $content = file_get_contents($filePath);
                $info['instruction_length'] = $content !== false ? mb_strlen($content) : 0;
                if ($info['instruction_length'] === 0) {
                    $issues[] = 'instruction.md is empty';
                }
            }
        }

        foreach ($requiredDirs as $dir) {
            if (!is_dir($path . '/' . $dir)) {
                $issues[] = "Missing required directory: {$dir}/";
            }
        }

        foreach ($optionalDirs as $dir) {
            $info['has_' . $dir] = is_dir($path . '/' . $dir);
        }

        // Check for Dockerfile in environment
        if (is_dir($path . '/environment')) {
            $hasDockerfile = is_file($path . '/environment/Dockerfile');
            $hasCompose = is_file($path . '/environment/docker-compose.yaml')
                || is_file($path . '/environment/docker-compose.yml');
            if (!$hasDockerfile && !$hasCompose) {
                $issues[] = 'environment/ has no Dockerfile or docker-compose.yaml';
            }
            $info['environment_type'] = $hasDockerfile ? 'Dockerfile' : ($hasCompose ? 'docker-compose' : 'unknown');
        }

        // Check for test.sh in tests
        if (is_dir($path . '/tests') && !is_file($path . '/tests/test.sh')) {
            $issues[] = 'tests/ missing test.sh — verifier needs this to produce reward files';
        }

        // Parse task.toml if it exists
        $tomlPath = $path . '/task.toml';
        if (is_file($tomlPath)) {
            $tomlData = $this->parseToml($tomlPath);
            if ($tomlData !== null) {
                $info['task_name'] = $tomlData['task']['name'] ?? null;
                $info['task_description'] = $tomlData['task']['description'] ?? null;
                $info['agent_timeout_sec'] = $tomlData['agent']['timeout_sec'] ?? null;
                $info['verifier_timeout_sec'] = $tomlData['verifier']['timeout_sec'] ?? null;
                $info['allow_internet'] = $tomlData['environment']['allow_internet'] ?? null;
            } else {
                $issues[] = 'task.toml could not be parsed';
            }
        }

        $valid = empty($issues);

        return ToolResult::success($this->jsonEncode([
            'valid' => $valid,
            'issues' => $issues,
            'info' => $info,
        ]));
    }

    private function executeDatasetList(): ToolResult
    {
        $output = $this->runCommand('harbor dataset list');

        if ($output === null) {
            return ToolResult::error('Failed to run "harbor dataset list". Is Harbor installed? Run harbor_check first.');
        }

        return ToolResult::success($output);
    }

    // ──────────────────────────────────────────────
    // Task Authoring Implementations
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function executeTaskInit(array $input): ToolResult
    {
        $name = $input['name'] ?? '';

        if ($name === '') {
            return ToolResult::error('Task name is required (format: "org/name").');
        }

        if (!preg_match('#^[a-zA-Z0-9_-]+/[a-zA-Z0-9_-]+$#', $name)) {
            return ToolResult::error('Task name must be in "org/name" format (alphanumeric, hyphens, underscores).');
        }

        $args = ['harbor', 'init', '--task', escapeshellarg($name)];

        $path = $input['path'] ?? '';
        if ($path !== '') {
            $args[] = '--path';
            $args[] = escapeshellarg($path);
        }

        $metadataTemplate = $input['metadata_template'] ?? '';
        if ($metadataTemplate !== '') {
            if (!is_file($metadataTemplate)) {
                return ToolResult::error("Metadata template not found: {$metadataTemplate}");
            }
            $args[] = '--metadata-template';
            $args[] = escapeshellarg($metadataTemplate);
        }

        $cmd = implode(' ', $args);
        $output = $this->runCommand($cmd);

        if ($output === null) {
            return ToolResult::error("Failed to initialize Harbor task. Command: {$cmd}");
        }

        return ToolResult::success("Task scaffolded successfully.\n\n{$output}");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeTaskList(array $input): ToolResult
    {
        $path = $input['path'] ?? '';

        if ($path === '') {
            return ToolResult::error('Dataset path is required.');
        }

        if (!is_dir($path)) {
            return ToolResult::error("Dataset directory not found: {$path}");
        }

        $tasks = [];
        $entries = scandir($path);

        if ($entries === false) {
            return ToolResult::error("Cannot read directory: {$path}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $taskDir = $path . '/' . $entry;
            if (!is_dir($taskDir)) {
                continue;
            }

            // Check if this looks like a task directory
            if (!is_file($taskDir . '/task.toml') && !is_file($taskDir . '/instruction.md')) {
                continue;
            }

            $task = ['name' => $entry, 'path' => $taskDir];

            // Parse task.toml for metadata
            $tomlPath = $taskDir . '/task.toml';
            if (is_file($tomlPath)) {
                $toml = $this->parseToml($tomlPath);
                if ($toml !== null) {
                    $task['description'] = $toml['task']['description'] ?? null;
                    $task['agent_timeout_sec'] = $toml['agent']['timeout_sec'] ?? null;
                    $task['verifier_timeout_sec'] = $toml['verifier']['timeout_sec'] ?? null;
                    $task['keywords'] = $toml['task']['keywords'] ?? null;
                }
            }

            $task['has_instruction'] = is_file($taskDir . '/instruction.md');
            $task['has_environment'] = is_dir($taskDir . '/environment');
            $task['has_tests'] = is_dir($taskDir . '/tests');
            $task['has_solution'] = is_dir($taskDir . '/solution');

            $tasks[] = $task;
        }

        if (empty($tasks)) {
            return ToolResult::success($this->jsonEncode([
                'path' => $path,
                'task_count' => 0,
                'tasks' => [],
                'message' => 'No Harbor tasks found in this directory.',
            ]));
        }

        return ToolResult::success($this->jsonEncode([
            'path' => $path,
            'task_count' => count($tasks),
            'tasks' => $tasks,
        ]));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeTaskDelete(array $input): ToolResult
    {
        $path = $input['path'] ?? '';

        if ($path === '') {
            return ToolResult::error('Task path is required.');
        }

        if (!is_dir($path)) {
            return ToolResult::error("Task directory not found: {$path}");
        }

        // Verify this looks like a task directory to prevent accidental deletion
        if (!is_file($path . '/task.toml') && !is_file($path . '/instruction.md')) {
            return ToolResult::error('This does not appear to be a Harbor task directory (no task.toml or instruction.md found). Refusing to delete.');
        }

        $this->deleteDirectory($path);

        return ToolResult::success("Deleted task directory: {$path}");
    }

    // ──────────────────────────────────────────────
    // Execution Implementations
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function executeHarborRun(array $input): ToolResult
    {
        $dataset = $input['dataset'] ?? '';
        $path = $input['path'] ?? '';
        $jobConfig = $input['job_config'] ?? '';

        // Must have at least one source
        if ($dataset === '' && $path === '' && $jobConfig === '') {
            return ToolResult::error('Provide at least one of: dataset (-d), path (-p), or job_config (-c).');
        }

        if ($dataset !== '' && $path !== '') {
            return ToolResult::error('Cannot specify both dataset and path. Use one or the other.');
        }

        $args = ['harbor', 'run'];

        if ($jobConfig !== '') {
            if (!is_file($jobConfig)) {
                return ToolResult::error("Job config file not found: {$jobConfig}");
            }
            $args[] = '-c';
            $args[] = escapeshellarg($jobConfig);
        } else {
            if ($dataset !== '') {
                $args[] = '-d';
                $args[] = escapeshellarg($dataset);
            } elseif ($path !== '') {
                if (!is_dir($path) && !is_file($path)) {
                    return ToolResult::error("Dataset/task path not found: {$path}");
                }
                $args[] = '-p';
                $args[] = escapeshellarg($path);
            }

            $agent = $input['agent'] ?? '';
            if ($agent !== '') {
                // Detect if this is an import path (contains : or .) vs a built-in name
                if (str_contains($agent, ':')) {
                    $args[] = '--agent-import-path';
                    $args[] = escapeshellarg($agent);
                } else {
                    $args[] = '-a';
                    $args[] = escapeshellarg($agent);
                }
            }

            $model = $input['model'] ?? '';
            if ($model !== '') {
                $args[] = '-m';
                $args[] = escapeshellarg($model);
            }

            $concurrency = (int) ($input['concurrency'] ?? 0);
            if ($concurrency > 0) {
                $args[] = '-n';
                $args[] = (string) $concurrency;
            }

            $envType = $input['env_type'] ?? '';
            if ($envType !== '') {
                $args[] = '--env';
                $args[] = escapeshellarg($envType);
            }
        }

        $jobsDir = $input['jobs_dir'] ?? $this->jobsDir;
        $args[] = '--jobs-dir';
        $args[] = escapeshellarg($jobsDir);

        $extraArgs = $input['extra_args'] ?? '';
        if ($extraArgs !== '') {
            $args[] = $extraArgs;
        }

        $cmd = implode(' ', $args);

        // Run the command — note: this can take a very long time
        // The agent should use background tools for real evaluations
        $output = $this->runCommand($cmd, timeout: 0);

        if ($output === null) {
            return ToolResult::error("Harbor run command failed. Command: {$cmd}");
        }

        // Try to find the job directory from the output
        $jobDir = $this->findLatestJobDir($jobsDir);

        return ToolResult::success($this->jsonEncode([
            'command' => $cmd,
            'output' => $output,
            'job_dir' => $jobDir,
            'hint' => 'Use harbor_results to analyze the job output, or harbor_run_status to check progress.',
        ]));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeRunStatus(array $input): ToolResult
    {
        $jobDir = $input['job_dir'] ?? '';

        if ($jobDir === '') {
            return ToolResult::error('Job directory path is required.');
        }

        if (!is_dir($jobDir)) {
            return ToolResult::error("Job directory not found: {$jobDir}");
        }

        $hasJobResult = is_file($jobDir . '/result.json');
        $hasJobConfig = is_file($jobDir . '/config.json');

        // Count trial directories and their completion status
        $trials = [];
        $entries = scandir($jobDir);

        if ($entries === false) {
            return ToolResult::error("Cannot read job directory: {$jobDir}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $trialDir = $jobDir . '/' . $entry;
            if (!is_dir($trialDir) || !is_file($trialDir . '/config.json')) {
                continue;
            }

            $completed = is_file($trialDir . '/result.json');
            $hasReward = is_file($trialDir . '/verifier/reward.txt')
                || is_file($trialDir . '/verifier/reward.json');

            $trials[] = [
                'name' => $entry,
                'completed' => $completed,
                'has_reward' => $hasReward,
            ];
        }

        $completedCount = count(array_filter($trials, fn (array $t): bool => $t['completed']));
        $totalCount = count($trials);

        $status = match (true) {
            $hasJobResult => 'completed',
            $totalCount === 0 => 'starting',
            $completedCount < $totalCount => 'running',
            default => 'finishing',
        };

        return ToolResult::success($this->jsonEncode([
            'status' => $status,
            'job_dir' => $jobDir,
            'has_result' => $hasJobResult,
            'trials_total' => $totalCount,
            'trials_completed' => $completedCount,
            'progress_percent' => $totalCount > 0 ? round(($completedCount / $totalCount) * 100, 1) : 0,
            'trials' => $trials,
        ]));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeView(array $input): ToolResult
    {
        $jobsDir = $input['jobs_dir'] ?? $this->jobsDir;
        $port = (int) ($input['port'] ?? 8080);

        if (!is_dir($jobsDir)) {
            return ToolResult::error("Jobs directory not found: {$jobsDir}");
        }

        $cmd = sprintf('harbor view %s --port %d', escapeshellarg($jobsDir), $port);
        $output = $this->runCommand($cmd, timeout: 5);

        return ToolResult::success($this->jsonEncode([
            'command' => $cmd,
            'url' => "http://127.0.0.1:{$port}",
            'hint' => 'The results viewer runs as a background web server. Open the URL in a browser to browse jobs and inspect trials.',
        ]));
    }

    // ──────────────────────────────────────────────
    // Analysis Implementations
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function executeResults(array $input): ToolResult
    {
        $jobDir = $input['job_dir'] ?? '';
        $includeTrials = (bool) ($input['include_trials'] ?? false);

        if ($jobDir === '') {
            return ToolResult::error('Job directory path is required.');
        }

        if (!is_dir($jobDir)) {
            return ToolResult::error("Job directory not found: {$jobDir}");
        }

        // Read job-level result.json if it exists
        $jobResult = $this->readJson($jobDir . '/result.json');
        $jobConfig = $this->readJson($jobDir . '/config.json');

        // Scan trial directories
        $trialResults = [];
        $rewards = [];
        $durations = [];
        $passCount = 0;
        $failCount = 0;
        $errorCount = 0;

        $entries = scandir($jobDir);
        if ($entries === false) {
            return ToolResult::error("Cannot read job directory: {$jobDir}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $trialDir = $jobDir . '/' . $entry;
            if (!is_dir($trialDir) || !is_file($trialDir . '/config.json')) {
                continue;
            }

            $trialResult = $this->readJson($trialDir . '/result.json');
            $reward = $this->readReward($trialDir);

            $trialSummary = [
                'name' => $entry,
                'completed' => $trialResult !== null,
                'reward' => $reward,
            ];

            if ($trialResult !== null) {
                $trialSummary['duration_sec'] = $trialResult['duration_sec'] ?? null;
                if (isset($trialResult['duration_sec'])) {
                    $durations[] = (float) $trialResult['duration_sec'];
                }
            }

            if ($reward !== null) {
                $rewards[] = $reward;
                if ($reward >= 1.0) {
                    $passCount++;
                } elseif ($reward === 0.0) {
                    $failCount++;
                } else {
                    // Partial reward
                    $failCount++;
                }
            } else {
                $errorCount++;
            }

            $trialResults[] = $trialSummary;
        }

        $totalTrials = count($trialResults);
        $summary = [
            'job_dir' => $jobDir,
            'total_trials' => $totalTrials,
            'passed' => $passCount,
            'failed' => $failCount,
            'error' => $errorCount,
            'pass_rate' => $totalTrials > 0 ? round(($passCount / $totalTrials) * 100, 1) : 0,
        ];

        if (!empty($rewards)) {
            sort($rewards);
            $summary['rewards'] = [
                'min' => min($rewards),
                'max' => max($rewards),
                'mean' => round(array_sum($rewards) / count($rewards), 4),
                'median' => $this->median($rewards),
            ];
        }

        if (!empty($durations)) {
            sort($durations);
            $summary['durations_sec'] = [
                'min' => round(min($durations), 2),
                'max' => round(max($durations), 2),
                'mean' => round(array_sum($durations) / count($durations), 2),
                'median' => round($this->median($durations), 2),
                'total' => round(array_sum($durations), 2),
            ];
        }

        if ($jobResult !== null) {
            $summary['job_result'] = $jobResult;
        }

        if ($includeTrials) {
            $summary['trials'] = $trialResults;
        }

        return ToolResult::success($this->jsonEncode($summary));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeTrialInspect(array $input): ToolResult
    {
        $trialDir = $input['trial_dir'] ?? '';
        $includeTrajectory = (bool) ($input['include_trajectory'] ?? true);
        $maxSteps = (int) ($input['trajectory_steps'] ?? self::MAX_TRAJECTORY_STEPS);

        if ($trialDir === '') {
            return ToolResult::error('Trial directory path is required.');
        }

        if (!is_dir($trialDir)) {
            return ToolResult::error("Trial directory not found: {$trialDir}");
        }

        $result = [
            'trial_dir' => $trialDir,
            'name' => basename($trialDir),
        ];

        // Read result.json
        $trialResult = $this->readJson($trialDir . '/result.json');
        if ($trialResult !== null) {
            $result['result'] = $trialResult;
        }

        // Read config.json
        $trialConfig = $this->readJson($trialDir . '/config.json');
        if ($trialConfig !== null) {
            $result['config'] = $trialConfig;
        }

        // Read reward
        $result['reward'] = $this->readReward($trialDir);

        // Read verifier logs
        $verifierDir = $trialDir . '/verifier';
        if (is_dir($verifierDir)) {
            $result['verifier'] = [];

            $stdout = $this->readFileTail($verifierDir . '/test-stdout.txt', self::MAX_LOG_LINES);
            if ($stdout !== null) {
                $result['verifier']['stdout'] = $stdout;
            }

            $stderr = $this->readFileTail($verifierDir . '/test-stderr.txt', self::MAX_LOG_LINES);
            if ($stderr !== null) {
                $result['verifier']['stderr'] = $stderr;
            }
        }

        // Read agent trajectory
        if ($includeTrajectory) {
            $agentDir = $trialDir . '/agent';
            if (is_dir($agentDir)) {
                $trajectoryFile = $agentDir . '/trajectory.json';
                if (is_file($trajectoryFile)) {
                    $trajectory = $this->readJson($trajectoryFile);
                    if (is_array($trajectory)) {
                        // Take the last N steps
                        if (count($trajectory) > $maxSteps) {
                            $result['trajectory_truncated'] = true;
                            $result['trajectory_total_steps'] = count($trajectory);
                            $trajectory = array_slice($trajectory, -$maxSteps);
                        }
                        $result['trajectory'] = $trajectory;
                    }
                }
            }
        }

        return ToolResult::success($this->jsonEncode($result));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeCompare(array $input): ToolResult
    {
        $jobDirsStr = $input['job_dirs'] ?? '';

        if ($jobDirsStr === '') {
            return ToolResult::error('Comma-separated job directory paths are required.');
        }

        $jobDirs = array_map('trim', explode(',', $jobDirsStr));

        if (count($jobDirs) < 2) {
            return ToolResult::error('At least two job directories are required for comparison.');
        }

        // Validate all directories exist
        foreach ($jobDirs as $dir) {
            if (!is_dir($dir)) {
                return ToolResult::error("Job directory not found: {$dir}");
            }
        }

        // Collect results per job, keyed by task name
        $jobData = [];
        foreach ($jobDirs as $dir) {
            $jobData[$dir] = $this->collectTrialsByTask($dir);
        }

        // Build comparison matrix
        // Collect all task names across jobs
        $allTasks = [];
        foreach ($jobData as $trials) {
            foreach (array_keys($trials) as $taskName) {
                $allTasks[$taskName] = true;
            }
        }
        ksort($allTasks);

        $matrix = [];
        $summaryStats = [];

        foreach ($jobDirs as $dir) {
            $rewards = [];
            foreach ($jobData[$dir] as $trial) {
                if ($trial['reward'] !== null) {
                    $rewards[] = $trial['reward'];
                }
            }
            $summaryStats[basename($dir)] = [
                'total_trials' => count($jobData[$dir]),
                'pass_count' => count(array_filter($rewards, fn (float $r): bool => $r >= 1.0)),
                'mean_reward' => !empty($rewards) ? round(array_sum($rewards) / count($rewards), 4) : null,
            ];
        }

        foreach (array_keys($allTasks) as $taskName) {
            $row = ['task' => $taskName];
            $rewardValues = [];

            foreach ($jobDirs as $dir) {
                $label = basename($dir);
                $trial = $jobData[$dir][$taskName] ?? null;
                $row[$label] = [
                    'reward' => $trial['reward'] ?? null,
                    'duration_sec' => $trial['duration_sec'] ?? null,
                    'passed' => $trial !== null && ($trial['reward'] ?? 0) >= 1.0,
                ];
                if ($trial !== null && $trial['reward'] !== null) {
                    $rewardValues[$label] = $trial['reward'];
                }
            }

            // Compute deltas between consecutive jobs
            $labels = array_map('basename', $jobDirs);
            /** @phpstan-ignore greaterOrEqual.alwaysTrue */
            if (count($labels) >= 2) {
                $first = $labels[0];
                $last = $labels[count($labels) - 1];
                $firstReward = $rewardValues[$first] ?? null;
                $lastReward = $rewardValues[$last] ?? null;

                if ($firstReward !== null && $lastReward !== null) {
                    $row['reward_delta'] = round($lastReward - $firstReward, 4);
                    $row['regression'] = $lastReward < $firstReward;
                    $row['improvement'] = $lastReward > $firstReward;
                }
            }

            $matrix[] = $row;
        }

        return ToolResult::success($this->jsonEncode([
            'jobs' => array_map('basename', $jobDirs),
            'summary' => $summaryStats,
            'comparison' => $matrix,
            'task_count' => count($allTasks),
        ]));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeFailures(array $input): ToolResult
    {
        $jobDir = $input['job_dir'] ?? '';
        $maxFailures = (int) ($input['max_failures'] ?? self::MAX_FAILURES);
        $trajectoryTail = (int) ($input['trajectory_tail'] ?? 5);

        if ($jobDir === '') {
            return ToolResult::error('Job directory path is required.');
        }

        if (!is_dir($jobDir)) {
            return ToolResult::error("Job directory not found: {$jobDir}");
        }

        $failures = [];
        $entries = scandir($jobDir);

        if ($entries === false) {
            return ToolResult::error("Cannot read job directory: {$jobDir}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $trialDir = $jobDir . '/' . $entry;
            if (!is_dir($trialDir) || !is_file($trialDir . '/config.json')) {
                continue;
            }

            $reward = $this->readReward($trialDir);

            // A trial is a failure if: no reward, or reward < 1.0
            if ($reward !== null && $reward >= 1.0) {
                continue;
            }

            $failure = [
                'trial' => $entry,
                'reward' => $reward,
            ];

            // Excerpt from verifier stderr
            $stderr = $this->readFileTail($trialDir . '/verifier/test-stderr.txt', 20);
            if ($stderr !== null) {
                $failure['verifier_stderr'] = $stderr;
            }

            $stdout = $this->readFileTail($trialDir . '/verifier/test-stdout.txt', 20);
            if ($stdout !== null) {
                $failure['verifier_stdout'] = $stdout;
            }

            // Last N trajectory steps
            $trajectoryFile = $trialDir . '/agent/trajectory.json';
            if (is_file($trajectoryFile)) {
                $trajectory = $this->readJson($trajectoryFile);
                if (is_array($trajectory) && !empty($trajectory)) {
                    $failure['trajectory_tail'] = array_slice($trajectory, -$trajectoryTail);
                    $failure['trajectory_total_steps'] = count($trajectory);
                }
            }

            // Trial result for error info
            $trialResult = $this->readJson($trialDir . '/result.json');
            if ($trialResult !== null) {
                $failure['duration_sec'] = $trialResult['duration_sec'] ?? null;
                $failure['error'] = $trialResult['error'] ?? null;
            }

            $failures[] = $failure;

            if (count($failures) >= $maxFailures) {
                break;
            }
        }

        return ToolResult::success($this->jsonEncode([
            'job_dir' => $jobDir,
            'failure_count' => count($failures),
            'failures' => $failures,
        ]));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeCleanup(array $input): ToolResult
    {
        $jobsDir = $input['jobs_dir'] ?? $this->jobsDir;
        $olderThanDays = (int) ($input['older_than_days'] ?? 30);
        $dryRun = (bool) ($input['dry_run'] ?? false);

        if (!is_dir($jobsDir)) {
            return ToolResult::error("Jobs directory not found: {$jobsDir}");
        }

        $cutoff = time() - ($olderThanDays * 86400);
        $candidates = [];
        $entries = scandir($jobsDir);

        if ($entries === false) {
            return ToolResult::error("Cannot read jobs directory: {$jobsDir}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $dirPath = $jobsDir . '/' . $entry;
            if (!is_dir($dirPath)) {
                continue;
            }

            $mtime = filemtime($dirPath);
            if ($mtime !== false && $mtime < $cutoff) {
                $candidates[] = [
                    'name' => $entry,
                    'path' => $dirPath,
                    'age_days' => (int) round((time() - $mtime) / 86400),
                    'modified' => date('Y-m-d H:i:s', $mtime),
                ];
            }
        }

        if (empty($candidates)) {
            return ToolResult::success($this->jsonEncode([
                'message' => "No job directories older than {$olderThanDays} days found.",
                'deleted' => 0,
            ]));
        }

        if ($dryRun) {
            return ToolResult::success($this->jsonEncode([
                'dry_run' => true,
                'would_delete' => count($candidates),
                'candidates' => $candidates,
            ]));
        }

        $deleted = 0;
        foreach ($candidates as $candidate) {
            $this->deleteDirectory($candidate['path']);
            $deleted++;
        }

        return ToolResult::success($this->jsonEncode([
            'deleted' => $deleted,
            'candidates' => $candidates,
        ]));
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function runCommand(string $command, int $timeout = self::PROCESS_TIMEOUT): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);

        // Set non-blocking on stdout for timeout support
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            if ($timeout > 0 && (time() - $startTime) > $timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return null;
            }

            $stdout .= fread($pipes[1], 8192) ?: '';
            usleep(50_000); // 50ms
        }

        // Read remaining output
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            // Return stderr as part of stdout for debugging
            if ($stderr !== '') {
                return $stdout . "\n[stderr]: " . $stderr;
            }
            return null;
        }

        return $stdout;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function readReward(string $trialDir): ?float
    {
        // Try reward.txt first (Harbor default)
        $rewardTxt = $trialDir . '/verifier/reward.txt';
        if (is_file($rewardTxt)) {
            $content = file_get_contents($rewardTxt);
            if ($content !== false) {
                $value = trim($content);
                if (is_numeric($value)) {
                    return (float) $value;
                }
            }
        }

        // Fall back to reward.json
        $rewardJson = $trialDir . '/verifier/reward.json';
        if (is_file($rewardJson)) {
            $data = $this->readJson($rewardJson);
            if ($data !== null) {
                // reward.json can have multiple metrics; prefer 'reward' key, then first value
                if (isset($data['reward'])) {
                    return (float) $data['reward'];
                }
                foreach ($data as $value) {
                    if (is_numeric($value)) {
                        return (float) $value;
                    }
                }
            }
        }

        return null;
    }

    private function readFileTail(string $path, int $maxLines): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }

        $lines = explode("\n", $content);
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
            return "[... truncated to last {$maxLines} lines ...]\n" . implode("\n", $lines);
        }

        return $content;
    }

    /**
     * Parse a TOML file into a nested array.
     *
     * Uses a lightweight parser for the subset of TOML used by Harbor's task.toml.
     * Handles: key = "value", [section], [[array]], numbers, booleans, arrays.
     *
     * @return array<string, mixed>|null
     */
    private function parseToml(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $result = [];
        $currentSection = &$result;
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // Section header [section.subsection]
            if (preg_match('/^\[([^\]]+)\]$/', $line, $matches)) {
                $parts = explode('.', $matches[1]);
                $currentSection = &$result;
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (!isset($currentSection[$part]) || !is_array($currentSection[$part])) {
                        $currentSection[$part] = [];
                    }
                    $currentSection = &$currentSection[$part];
                }
                continue;
            }

            // Key = value
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(.+)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                $currentSection[$key] = $this->parseTomlValue($value);
            }
        }

        return $result;
    }

    /**
     * @return string|int|float|bool|array<int, string|int|float|bool|array<mixed>>
     */
    private function parseTomlValue(string $value): string|int|float|bool|array
    {
        // String (quoted)
        if (preg_match('/^"(.*)"$/', $value, $m)) {
            return $m[1];
        }
        if (preg_match("/^'(.*)'$/", $value, $m)) {
            return $m[1];
        }

        // Boolean
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        // Float
        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float) $value;
        }

        // Integer
        if (preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        // Simple array (single-line)
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $inner = substr($value, 1, -1);
            $items = array_map(function (?string $item): string|int|float|bool|array {
                return $this->parseTomlValue(trim((string) $item));
            }, str_getcsv($inner, escape: ''));
            return $items;
        }

        return $value;
    }

    /**
     * Collect trial results from a job directory, keyed by task name.
     *
     * @return array<string, array{reward: ?float, duration_sec: ?float}>
     */
    private function collectTrialsByTask(string $jobDir): array
    {
        $trials = [];
        $entries = scandir($jobDir);

        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $trialDir = $jobDir . '/' . $entry;
            if (!is_dir($trialDir) || !is_file($trialDir . '/config.json')) {
                continue;
            }

            $reward = $this->readReward($trialDir);
            $result = $this->readJson($trialDir . '/result.json');

            $trials[$entry] = [
                'reward' => $reward,
                'duration_sec' => $result['duration_sec'] ?? null,
            ];
        }

        return $trials;
    }

    private function findLatestJobDir(string $jobsDir): ?string
    {
        if (!is_dir($jobsDir)) {
            return null;
        }

        $entries = scandir($jobsDir);
        if ($entries === false) {
            return null;
        }

        $latest = null;
        $latestTime = 0;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $dirPath = $jobsDir . '/' . $entry;
            if (!is_dir($dirPath)) {
                continue;
            }

            $mtime = filemtime($dirPath);
            if ($mtime !== false && $mtime > $latestTime) {
                $latestTime = $mtime;
                $latest = $dirPath;
            }
        }

        return $latest;
    }

    /**
     * @param array<mixed> $values Sorted array of numeric values
     */
    private function median(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $mid = (int) ($count / 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }

        return (float) $values[$mid];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonEncode(array $data): string
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        return $encoded;
    }

    private function deleteDirectory(string $path): void
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
                $this->deleteDirectory($fullPath);
            } else {
                unlink($fullPath);
            }
        }

        rmdir($path);
    }
}
