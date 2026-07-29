<?php

declare(strict_types=1);

use Porta\Plugin\PluginApi;
use Porta\Plugin\PortaPlugin;
use Porta\Support\AsyncProcess;
use Revolt\EventLoop;
use Symfony\Component\Process\ExecutableFinder;
use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * Syntax highlighting via `chroma`, the Go highlighter (MIT, ~297 lexers).
 *
 * This is an **asynchronous** backend: chroma is a subprocess costing roughly
 * 40-70ms per run, so it dispatches and returns, and Porta repaints when the
 * result lands. That trade only makes sense where the built-in engine is weak,
 * which is why the default claim list is languages Tempest has no grammar for
 * (rust, go, ruby, elixir, zig) rather than `*`. Point it at php or json and
 * you will get worse typing latency for no accuracy gain.
 *
 * It asks chroma for `-f json` — a token stream — rather than pre-coloured
 * ANSI, so Porta keeps control of its own theme. chroma's token values
 * concatenate back to the source byte for byte, so no offsets are needed.
 *
 * It deliberately does **not** register token spans for project search: search
 * classifies every matched file, so a 500-hit search would mean 500
 * subprocesses.
 */
return new class implements PortaPlugin {
    /** Pygments-ish type-name prefix => Porta token type, longest prefix first. */
    private const array TOKEN_MAP = [
        'LiteralStringEscape' => TokenTypeEnum::LITERAL,
        'LiteralStringInterpol' => TokenTypeEnum::VARIABLE,
        'LiteralNumber' => TokenTypeEnum::NUMBER,
        'LiteralString' => TokenTypeEnum::VALUE,
        'NameAttribute' => TokenTypeEnum::PROPERTY,
        'NameBuiltinPseudo' => TokenTypeEnum::VARIABLE,
        'NameBuiltin' => TokenTypeEnum::GENERIC,
        'NameClass' => TokenTypeEnum::TYPE,
        'NameConstant' => TokenTypeEnum::LITERAL,
        'NameDecorator' => TokenTypeEnum::ATTRIBUTE,
        'NameException' => TokenTypeEnum::TYPE,
        'NameFunction' => TokenTypeEnum::GENERIC,
        'NameNamespace' => TokenTypeEnum::TYPE,
        'NameProperty' => TokenTypeEnum::PROPERTY,
        'NameTag' => TokenTypeEnum::PROPERTY,
        'NameVariable' => TokenTypeEnum::VARIABLE,
        'KeywordType' => TokenTypeEnum::TYPE,
        'Keyword' => TokenTypeEnum::KEYWORD,
        'Comment' => TokenTypeEnum::COMMENT,
        'Operator' => TokenTypeEnum::OPERATOR,
        'Punctuation' => TokenTypeEnum::OPERATOR,
        'Number' => TokenTypeEnum::NUMBER,
        'String' => TokenTypeEnum::VALUE,
        'Literal' => TokenTypeEnum::LITERAL,
    ];

    /** Cached binary lookup: this is on the typing path, unlike a command handler. */
    private ?string $binary = null;
    private bool $binaryResolved = false;

    /** @var array<string, string> language => pending debounce timer id */
    private array $timers = [];

    /** @var array<string, int> language => newest dispatched sequence */
    private array $latest = [];

    private int $sequence = 0;
    private int $running = 0;

    /** Hard ceiling on concurrent chroma processes across every language. */
    private const int MAX_CONCURRENT = 2;

    public function name(): string
    {
        return 'chroma';
    }

    public function activate(PluginApi $api): void
    {
        $api->registerSetting('binary', 'Chroma binary', 'text', '', hint: 'empty = vendor/bin, then PATH');
        $api->registerSetting('languages', 'Languages', 'text', 'rust,go,ruby,elixir,zig', hint: 'comma-separated grammar names, or * for all');
        $api->registerSetting('debounce', 'Debounce (ms)', 'int', 250);
        $api->registerSetting('maxBytes', 'Skip files larger than (bytes)', 'int', 262_144);

        $api->registerInstaller('chroma', fn (): bool => null !== $this->binary($api), [['brew', 'chroma']]);

        $theme = $api->syntaxTheme();
        $api->registerHighlighter(
            'Chroma',
            fn (): array => $this->claimedLanguages($api),
            function (string $text, string $language, Closure $deliver) use ($api, $theme): void {
                $this->schedule($api, $theme, $text, $language, $deliver);
            },
            isAvailable: fn (): bool => null !== $this->binary($api),
        );
    }

    /** @return list<string> */
    private function claimedLanguages(PluginApi $api): array
    {
        $raw = trim((string) $api->setting('languages'));

        return '' === $raw ? [] : array_values(array_filter(array_map(trim(...), explode(',', $raw))));
    }

    /**
     * Debounce, then run at most one chroma per language. A newer request
     * replaces a pending one outright rather than queueing: intermediate
     * keystrokes are worthless once a later version exists.
     */
    private function schedule(PluginApi $api, TerminalTheme $theme, string $text, string $language, Closure $deliver): void
    {
        if (\strlen($text) > (int) $api->setting('maxBytes')) {
            return; // too big to be worth a subprocess; Porta keeps its current paint
        }

        $pending = $this->timers[$language] ?? null;
        if (null !== $pending) {
            EventLoop::cancel($pending);
        }

        $sequence = ++$this->sequence;
        $this->latest[$language] = $sequence;

        $delay = max(0, (int) $api->setting('debounce')) / 1000;
        $this->timers[$language] = EventLoop::delay($delay, function () use ($api, $theme, $text, $language, $deliver, $sequence): void {
            unset($this->timers[$language]);
            $this->run($api, $theme, $text, $language, $deliver, $sequence);
        });
    }

    private function run(PluginApi $api, TerminalTheme $theme, string $text, string $language, Closure $deliver, int $sequence): void
    {
        $binary = $this->binary($api);
        if (null === $binary || $this->running >= self::MAX_CONCURRENT) {
            return;
        }

        ++$this->running;
        new AsyncProcess([$binary, '-f', 'json', '--lexer', $language], $api->projectRoot(), stdin: $text)
            ->start(function (int $code, string $stdout) use ($theme, $language, $deliver, $sequence): void {
                --$this->running;
                if (0 !== $code || ($this->latest[$language] ?? 0) !== $sequence) {
                    return; // failed, or a newer version superseded this run
                }
                $markup = $this->toAnsi($stdout, $theme);
                if (null !== $markup) {
                    $deliver($markup);
                }
            });
    }

    /** chroma's JSON token stream as ANSI, or null when it isn't usable. */
    private function toAnsi(string $json, TerminalTheme $theme): ?string
    {
        try {
            $tokens = json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($tokens)) {
            return null;
        }

        $markup = '';
        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                return null;
            }
            $value = \is_string($token['value'] ?? null) ? $token['value'] : '';
            $type = $this->tokenType(\is_string($token['type'] ?? null) ? $token['type'] : '');
            $markup .= null === $type ? $value : $theme->before($type).$value.$theme->after($type);
        }

        return $markup;
    }

    /**
     * Pygments type names are a flattened hierarchy (`NameFunction` is
     * `Name.Function`), so match by longest prefix — an exact-match table would
     * leave most of chroma's 297 lexers uncoloured.
     */
    private function tokenType(string $name): ?TokenTypeEnum
    {
        foreach (self::TOKEN_MAP as $prefix => $type) {
            if (str_starts_with($name, $prefix)) {
                return $type;
            }
        }

        return null; // Text, Error, Other, and anything unmapped: leave it plain
    }

    /** Setting → project vendor/bin → PATH, memoised. */
    private function binary(PluginApi $api): ?string
    {
        if ($this->binaryResolved) {
            return $this->binary;
        }
        $this->binaryResolved = true;

        $configured = trim((string) $api->setting('binary'));
        if ('' !== $configured) {
            return $this->binary = $configured;
        }

        $vendor = $api->projectRoot().'/vendor/bin/chroma';
        if (is_file($vendor) && is_executable($vendor)) {
            return $this->binary = $vendor;
        }

        return $this->binary = new ExecutableFinder()->find('chroma');
    }
};
