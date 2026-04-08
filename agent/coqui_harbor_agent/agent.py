"""
Coqui External Agent for Harbor.

Shell-bridge approach: Harbor manages the Docker environment and test harness.
This agent receives an instruction and runs Coqui CLI inside the environment
container to execute the task. The agent communicates with Coqui via subprocess
(exec) calls through Harbor's environment abstraction.

Usage:
    harbor run -p ./my-tasks --agent-import-path coqui_harbor_agent.agent:CoquiExternalAgent -m anthropic/claude-sonnet-4-20250514
"""

from __future__ import annotations

import json
import logging
import os
import time
from typing import Any

from harbor.agent.base import BaseAgent
from harbor.environment.base import BaseEnvironment

logger = logging.getLogger(__name__)

# Default Coqui binary path (inside container or host)
DEFAULT_COQUI_BIN = os.environ.get("COQUI_BIN", "coqui")

# Maximum time (seconds) to wait for Coqui to complete a task
DEFAULT_TIMEOUT = int(os.environ.get("COQUI_TIMEOUT", "600"))

# Maximum agent iterations for the task
DEFAULT_MAX_ITERATIONS = int(os.environ.get("COQUI_MAX_ITERATIONS", "100"))


class CoquiExternalAgent(BaseAgent):
    """
    Harbor external agent that delegates task execution to Coqui CLI.

    The agent runs Coqui in headless mode (--headless) inside Harbor's
    environment container via environment.exec(). Coqui receives the task
    instruction as a prompt and works autonomously with its full toolkit
    (filesystem, shell, web, etc.).

    Configuration via environment variables:
        COQUI_BIN: Path to the Coqui binary (default: "coqui")
        COQUI_TIMEOUT: Max seconds for task execution (default: 600)
        COQUI_MAX_ITERATIONS: Agent iteration limit (default: 100)
        COQUI_MODEL: Model override (default: uses Harbor's -m flag)
        COQUI_ROLE: Agent role to use (default: "coder")
        COQUI_AUTO_APPROVE: Auto-approve tool calls (default: "true")
        COQUI_EXTRA_ARGS: Additional CLI arguments for Coqui
    """

    def name(self) -> str:
        return "coqui"

    def version(self) -> str:
        return "0.1.0"

    def setup(self, environment: BaseEnvironment) -> None:
        """
        Prepare the environment for Coqui execution.

        Installs Coqui CLI into the environment container if not already
        present. This runs once per trial before run() is called.
        """
        logger.info("Setting up Coqui agent in environment")

        # Check if Coqui is already available in the container
        result = environment.exec(f"which {DEFAULT_COQUI_BIN} 2>/dev/null || echo 'NOT_FOUND'")
        output = result.stdout.strip() if hasattr(result, "stdout") else str(result).strip()

        if "NOT_FOUND" in output:
            logger.info("Coqui not found in environment, installing...")
            # Install via Composer (assumes PHP is available in the container)
            install_cmds = [
                "composer global require carmelosantana/coqui --no-interaction 2>&1 || true",
                "export PATH=$PATH:$(composer global config bin-dir --absolute 2>/dev/null)",
            ]
            for cmd in install_cmds:
                environment.exec(cmd)
        else:
            logger.info(f"Coqui found at: {output}")

    def run(
        self,
        instruction: str,
        environment: BaseEnvironment,
        context: dict[str, Any] | None = None,
    ) -> str:
        """
        Execute a task using Coqui CLI inside the Harbor environment.

        Args:
            instruction: The task instruction from Harbor (contents of instruction.md).
            environment: Harbor environment abstraction for executing commands.
            context: Optional context dict (model name, environment info, etc.).

        Returns:
            The agent's final output/response as a string.
        """
        model = self._resolve_model(context)
        role = os.environ.get("COQUI_ROLE", "coder")
        auto_approve = os.environ.get("COQUI_AUTO_APPROVE", "true").lower() == "true"
        max_iterations = DEFAULT_MAX_ITERATIONS
        timeout = DEFAULT_TIMEOUT
        extra_args = os.environ.get("COQUI_EXTRA_ARGS", "")

        # Build the Coqui CLI command
        cmd_parts = [DEFAULT_COQUI_BIN, "run"]

        # Core flags
        cmd_parts.extend(["--headless"])
        cmd_parts.extend(["--role", role])
        cmd_parts.extend(["--max-iterations", str(max_iterations)])

        if model:
            cmd_parts.extend(["--model", model])

        if auto_approve:
            cmd_parts.append("--auto-approve")

        # Pass the instruction as a prompt via stdin or --prompt flag
        # Using --prompt with escaped instruction
        escaped_instruction = self._escape_for_shell(instruction)
        cmd_parts.extend(["--prompt", escaped_instruction])

        if extra_args:
            cmd_parts.append(extra_args)

        cmd = " ".join(cmd_parts)

        logger.info(f"Executing Coqui command: {cmd[:200]}...")

        start_time = time.time()

        try:
            result = environment.exec(cmd, timeout=timeout)
            elapsed = time.time() - start_time

            # Extract output
            if hasattr(result, "stdout"):
                output = result.stdout
            else:
                output = str(result)

            logger.info(
                f"Coqui completed in {elapsed:.1f}s, output length: {len(output)}"
            )

            return output

        except TimeoutError:
            elapsed = time.time() - start_time
            logger.warning(f"Coqui timed out after {elapsed:.1f}s (limit: {timeout}s)")
            return f"[TIMEOUT] Coqui agent timed out after {timeout} seconds."

        except Exception as e:
            elapsed = time.time() - start_time
            logger.error(f"Coqui execution failed after {elapsed:.1f}s: {e}")
            return f"[ERROR] Coqui agent failed: {e}"

    def _resolve_model(self, context: dict[str, Any] | None) -> str | None:
        """Resolve the model to use, checking multiple sources."""
        # 1. Environment variable override
        env_model = os.environ.get("COQUI_MODEL")
        if env_model:
            return env_model

        # 2. Harbor context (from -m flag)
        if context and "model" in context:
            return context["model"]

        # 3. Default (let Coqui use its own config)
        return None

    @staticmethod
    def _escape_for_shell(text: str) -> str:
        """Escape text for safe shell argument passing."""
        # Use single quotes, escaping any existing single quotes
        escaped = text.replace("'", "'\"'\"'")
        return f"'{escaped}'"
