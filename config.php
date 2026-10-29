<?php
declare(strict_types=1);

/**
 * Cairn — an AI-curated Python tutor.
 * Plain PHP + JSON files. No database, no server-side code execution.
 */

define('APP_NAME', 'Cairn');
define('APP_ROOT', __DIR__);
define('DATA_DIR', APP_ROOT . '/data');
define('USERS_DIR', DATA_DIR . '/users');
define('CONTENT_DIR', APP_ROOT . '/content');

define('PYODIDE_VERSION', '0.29.5');
define('MONACO_VERSION', '0.57.0');

/* ------------------------------------------------------------------ */
/* Storage bootstrap                                                   */
/* ------------------------------------------------------------------ */

foreach ([DATA_DIR, USERS_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

/**
 * Secret used to encrypt users' provider API keys at rest.
 * Generated once on first run. If you deploy this, back it up — losing it
 * means users must re-enter their keys.
 */
function app_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $file = DATA_DIR . '/secret.key';
    if (!is_file($file)) {
        $key = bin2hex(random_bytes(32));
        @file_put_contents($file, $key);
        @chmod($file, 0600);
    }
    $key = trim((string)@file_get_contents($file));
    $secret = $key !== '' ? $key : hash('sha256', APP_ROOT . php_uname());
    return $secret;
}

/* ------------------------------------------------------------------ */
/* Model providers the learner can choose from                         */
/* ------------------------------------------------------------------ */

function providers(): array
{
    return [
        'nvidia' => [
            'label'    => 'NVIDIA NIM',
            'kind'     => 'openai',
            'base'     => 'https://integrate.api.nvidia.com/v1',
            'key_url'  => 'https://build.nvidia.com/',
            'note'     => 'Free API credits. Get a key at build.nvidia.com.',
            'models'   => [
                'meta/llama-3.3-70b-instruct',
                'nvidia/llama-3.3-nemotron-super-49b-v1.5',
                'nvidia/nemotron-4-340b-instruct',
                'meta/llama-3.1-8b-instruct',
                'mistralai/mistral-large-2-instruct',
                'qwen/qwen2.5-coder-32b-instruct',
                'deepseek-ai/deepseek-r1',
            ],
        ],
        'openai' => [
            'label'   => 'OpenAI',
            'kind'    => 'openai',
            'base'    => 'https://api.openai.com/v1',
            'key_url' => 'https://platform.openai.com/api-keys',
            'note'    => 'GPT models. Requires a paid account.',
            'models'  => ['gpt-4.1-mini', 'gpt-4.1', 'gpt-4o', 'gpt-4o-mini', 'o4-mini'],
        ],
        'openrouter' => [
            'label'   => 'OpenRouter',
            'kind'    => 'openai',
            'base'    => 'https://openrouter.ai/api/v1',
            'key_url' => 'https://openrouter.ai/keys',
            'note'    => 'One key, hundreds of models, some free.',
            'models'  => [
                'anthropic/claude-sonnet-4',
                'google/gemini-2.5-flash',
                'deepseek/deepseek-chat',
                'meta-llama/llama-3.3-70b-instruct',
                'qwen/qwen-2.5-coder-32b-instruct',
            ],
        ],
        'groq' => [
            'label'   => 'Groq',
            'kind'    => 'openai',
            'base'    => 'https://api.groq.com/openai/v1',
            'key_url' => 'https://console.groq.com/keys',
            'note'    => 'Very fast inference, generous free tier.',
            'models'  => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'qwen/qwen3-32b'],
        ],
        'deepseek' => [
            'label'   => 'DeepSeek',
            'kind'    => 'openai',
            'base'    => 'https://api.deepseek.com/v1',
            'key_url' => 'https://platform.deepseek.com/api_keys',
            'note'    => 'Cheap, strong at code.',
            'models'  => ['deepseek-chat', 'deepseek-reasoner'],
        ],
        'mistral' => [
            'label'   => 'Mistral',
            'kind'    => 'openai',
            'base'    => 'https://api.mistral.ai/v1',
            'key_url' => 'https://console.mistral.ai/api-keys/',
            'note'    => 'European provider, solid code models.',
            'models'  => ['mistral-large-latest', 'codestral-latest', 'mistral-small-latest'],
        ],
        'together' => [
            'label'   => 'Together AI',
            'kind'    => 'openai',
            'base'    => 'https://api.together.xyz/v1',
            'key_url' => 'https://api.together.ai/settings/api-keys',
            'note'    => 'Hosts many open models.',
            'models'  => ['meta-llama/Llama-3.3-70B-Instruct-Turbo', 'Qwen/Qwen2.5-Coder-32B-Instruct'],
        ],
        'anthropic' => [
            'label'   => 'Anthropic',
            'kind'    => 'anthropic',
            'base'    => 'https://api.anthropic.com/v1',
            'key_url' => 'https://console.anthropic.com/settings/keys',
            'note'    => 'Claude models.',
            'models'  => ['claude-sonnet-4-20250514', 'claude-3-5-haiku-20241022'],
        ],
        'google' => [
            'label'   => 'Google Gemini',
            'kind'    => 'google',
            'base'    => 'https://generativelanguage.googleapis.com/v1beta',
            'key_url' => 'https://aistudio.google.com/apikey',
            'note'    => 'Free tier available.',
            'models'  => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash'],
        ],
        'ollama' => [
            'label'   => 'Ollama (local)',
            'kind'    => 'openai',
            'base'    => 'http://localhost:11434/v1',
            'key_url' => 'https://ollama.com/download',
            'note'    => 'Runs on your own machine. No key needed.',
            'models'  => ['llama3.2', 'qwen2.5-coder', 'mistral'],
        ],
        'custom' => [
            'label'   => 'Custom (OpenAI-compatible)',
            'kind'    => 'openai',
            'base'    => '',
            'key_url' => '',
            'note'    => 'Any endpoint that speaks the OpenAI chat-completions API.',
            'models'  => [],
        ],
    ];
}

function provider(?string $id): ?array
{
    $all = providers();
    return $all[$id] ?? null;
}
