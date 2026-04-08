# Coqui Harbor External

Harbor benchmarking toolkit for [Coqui](https://github.com/carmelosantana/coqui). Manage tasks, run evaluations, and analyze benchmark results via the [Harbor](https://harborframework.com) CLI.

## Requirements

- PHP 8.4+
- [Harbor CLI](https://harborframework.com/docs/getting-started) (`uv tool install harbor`)
- Docker (for local evaluations)
- [Coqui](https://github.com/carmelosantana/coqui)

## Installation

```bash
composer require carmelosantana/coqui-harbor-external
```

The toolkit is auto-discovered by Coqui — no code changes needed.

## Tools Provided

### Discovery & Validation

| Tool | Description |
|------|-------------|
| `harbor_check` | Verify Harbor CLI, Python, Docker, and uv are installed |
| `harbor_task_validate` | Validate a task directory has the required structure |
| `harbor_dataset_list` | List registered datasets from the Harbor registry |

### Task Authoring

| Tool | Description |
|------|-------------|
| `harbor_task_init` | Scaffold a new task directory (instruction.md, task.toml, environment/, tests/) |
| `harbor_task_list` | List all tasks in a local dataset directory |
| `harbor_task_delete` | Delete a task directory *(gated — requires confirmation)* |

### Execution

| Tool | Description |
|------|-------------|
| `harbor_run` | Run a Harbor evaluation against a dataset or task path *(gated)* |
| `harbor_run_status` | Check job progress (trial completion, overall status) |
| `harbor_view` | Launch Harbor's web-based results viewer |

### Analysis

| Tool | Description |
|------|-------------|
| `harbor_results` | Parse job results: pass/fail, reward distribution, durations |
| `harbor_trial_inspect` | Inspect a trial's trajectory, verifier logs, and reward |
| `harbor_compare` | Compare two or more jobs for regression detection |
| `harbor_failures` | Extract failed trials with root cause details |
| `harbor_cleanup` | Delete old job directories *(gated)* |

## Python Agent Wrapper

The package includes a Python external agent that bridges Harbor's evaluation framework with Coqui's CLI. This allows Harbor to drive Coqui as the agent under test.

### Setup

```bash
cd agent
uv pip install -e .
```

### Usage

```bash
harbor run \
  -p ./my-tasks \
  --agent-import-path coqui_harbor_agent.agent:CoquiExternalAgent \
  -m anthropic/claude-sonnet-4-20250514
```

### Configuration

| Environment Variable | Default | Description |
|---------------------|---------|-------------|
| `COQUI_BIN` | `coqui` | Path to the Coqui binary |
| `COQUI_TIMEOUT` | `600` | Max seconds per task |
| `COQUI_MAX_ITERATIONS` | `100` | Agent iteration limit |
| `COQUI_MODEL` | *(from Harbor -m)* | Model override |
| `COQUI_ROLE` | `coder` | Agent role |
| `COQUI_AUTO_APPROVE` | `true` | Auto-approve tool calls |
| `COQUI_EXTRA_ARGS` | | Additional CLI arguments |

## Bundled Skill

The `harbor-benchmarking` skill provides an operational SOP for running benchmark campaigns — including task creation, evaluation execution, failure triage, regression detection, and reporting. It is auto-discovered when the package is installed.

## Bundled Loop

The `benchmark` loop definition automates a full benchmark cycle:

1. **Plan** — validate tasks, define success criteria, create plan artifact
2. **Coder** — execute benchmark runs, analyze results, create report artifact
3. **Reviewer** — verify completeness, check for regressions, approve or request changes

Terminates when the reviewer responds with `APPROVED`.

## Development

```bash
composer install
composer test      # Run Pest tests
composer analyse   # Run PHPStan (level 8)
```

## License

MIT
