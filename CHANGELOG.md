# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.0.0] — 2026-05-27

### Added
- `AiTask` base class — define reusable AI tasks with prompt, system, driver, model, and options
- `AI` facade — `AI::send()`, `AI::stream()`, `AI::queue()` entry points
- Driver-based architecture: OpenAI, Anthropic, Gemini, custom drivers via config
- `ai_runs` table — persists every run with status, tokens, cost, request, response
- Queue support — `AI::queue()` dispatches `ProcessAiPayload` job
- Modalities: text, vision, embed
- `ai:budget` command — usage and cost report per model/provider
- `AiRun` Eloquent model with `subject` morph relation and status helpers
- Events: `AiRunCreated`, `AiRunCompleted`, `AiRunFailed`
