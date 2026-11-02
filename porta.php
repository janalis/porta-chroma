<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractSubprocessHighlighter;
use Porta\Plugin\Api\BinarySearch;
use Porta\Plugin\Api\PluginApi;
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
 * The debounce, the latest-wins guard, the concurrency ceiling and the token →
 * ANSI conversion all live in {@see AbstractSubprocessHighlighter}: scheduling
 * work on the typing path is exactly the kind of code that belongs under the
 * coverage gate rather than in a plugin file. What is left here is chroma's own
 * truth — its lexer arguments and its type-name table.
 *
 * It deliberately does **not** register token spans for project search: search
 * classifies every matched file, so a 500-hit search would mean 500
 * subprocesses.
 */
return new class('chroma', 'chroma', BinarySearch::PathOnly) extends AbstractSubprocessHighlighter {
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

    public function activate(PluginApi $api): void
    {
        $this->registerBinarySetting($api);
        $this->registerSubprocessHighlighter($api, 'Chroma', 'rust,go,ruby,elixir,zig');
    }

    protected function lexerArgs(string $language): array
    {
        return ['-f', 'json', '--lexer', $language];
    }

    protected function tokenMap(): array
    {
        return self::TOKEN_MAP;
    }
};
