<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Call 1 when the investigator is on. Claude checks the reading with PRISM's
 * own read-only tools (see ToolBox), then names the likely cause and two
 * physical checks.
 *
 * Code stays in charge:
 * - The score, the band and the work order priority come from rules, not from
 *   the model. No tool changes them.
 * - The loop has a round cap and a time budget.
 * - The answer must name the tools it used, and every number in the text must
 *   appear in the input or in a tool result. Otherwise the answer is rejected
 *   and the caller shows the older Diagnose answer or the built-in text.
 *
 * @phpstan-type InvestigationResult array{source: string, data: array{cause: string, checks: list<string>, evidence: list<string>, tested_fix?: array{channel: string, value: float|int, score_from: int, score_to: int, text: string}|null}|null, tokens: array{input: int, output: int}|null, reason: string|null, trace: list<array{tool: string, summary: string, ok: bool}>}
 *
 * run() returns the same shape as AiStep::run() plus trace: a list of
 * {tool, summary, ok} for the page to show as "What the AI looked at".
 * The reason is null unless the source is "fallback". Then it is one of
 * low_risk, no_key, timeout, api_error, bad_reply or ungrounded.
 */
final class Investigator
{
    public const MAX_ROUNDS = 4;

    /** A rise in the learned chance smaller than this many percentage points is not a disagreement with the rules. */
    public const MIN_DISAGREE_POINTS = 1.0;

    /** Why the last answer was refused, for the log only. */
    private ?string $lastDetail = null;

    public const BUDGET_SECONDS = 12;

    private const MAX_TOKENS = 1000;

    private const AI_TTL_SECONDS = 3600;

    private const FALLBACK_TTL_SECONDS = 60;

    public function __construct(private readonly AiClient $ai) {}

    /**
     * @param  array{score: int, band: string, action: string, explanation: string, drivers: list<array{key: string, label: string, stress: int, scored?: bool}>, metrics: array<string, float>}  $risk  The RiskScorer result for the reading.
     * @return InvestigationResult
     */
    public function run(string $scenario, int $tick, array $risk): array
    {
        $this->lastDetail = null;
        $input = Diagnoser::input($risk);

        if ($input['band'] === 'low') {
            return $this->finish(null, 'low_risk', [], null);
        }

        if (! $this->ai->enabled()) {
            return $this->finish(null, 'no_key', [], null);
        }

        $key = 'ai:investigate:'.sha1($scenario.'|'.$tick.'|'.json_encode($input));
        $cached = $this->cacheGet($key);
        if (is_array($cached) && isset($cached['source'])) {
            /** @var InvestigationResult $cached Written by this class, so the shape is known. */
            $source = $cached['source'] === 'ai' ? 'cache' : 'fallback';
            $cached['source'] = $source;
            if ($source === 'cache') {
                $cached['reason'] = null;
            }

            return $this->log($cached);
        }

        $result = $this->loop($scenario, $tick, $input);
        $this->cachePut($key, $result, $result['source'] === 'ai' ? self::AI_TTL_SECONDS : self::FALLBACK_TTL_SECONDS);

        return $this->log($result);
    }

    private function system(): string
    {
        return 'You help a technician at a small machine shop. You get a JSON risk result for one CNC milling machine '
            .'and read-only tools that check that machine. Call one to four tools before you answer. '
            .'Call get_learned_estimate once, so the technician sees the trained model\'s chance of failure next to the rule score. Call it a second opinion from a model trained on the AI4I dataset. If its chance is low while the band is elevated or high, say so plainly. The result also holds a projection of the chance a few readings ahead. When the projection reaches 20 or 50 percent, give the number of readings (if both reach it at the same reading, say so once, for example \'20% and 50% both in about 4 readings\'), and say it is a straight-line projection on simulated data. Never say the model disagrees with the band without also giving that projection, because the model then expects the same problem soon. '
            .'Call time_to_limit for the driver with the highest stress, so the technician learns how long they have. '
            .'Call what_if once to test a fix: try a value for one channel that moves the reading away from the failure zone described in the JSON. '
            .'A change is a tested fix only when the what_if effect is lowers_score. Recommend a change only if it is a tested fix. '
            .'If no what_if lowers the score, say no tested change is known and give inspection checks only. Never advise a change toward a failure zone. '
            .'Call get_trend if you need to know whether a channel is rising or falling. Do not call get_check only to repeat numbers already in the JSON. '
            .'A limit is crossed only when a tool result shows stress 100 for that check. Below that, write near the limit or inside the margin. '
            .'Name a cause only when the JSON or a tool result states it. Do not guess parts such as bearings or motors. '
            .'When you give a time estimate, write straight-line estimate on simulated data, and name what it counts down to, for example about 150 minutes until the overstrain check reaches its top stress. '
            .'Write for the technician. Never write the words JSON, tool, input or what_if in the cause or the checks. '
            .'Use only facts in the JSON and in tool results. Do not invent numbers, parts or causes. Copy numbers exactly as given. '
            .'The tools never change the score. '
            .'Reply with JSON only, no other text: '
            .'{"cause": "one or two plain sentences, under 40 words", '
            .'"checks": ["first physical check, under 15 words", "second physical check, under 15 words"], '
            .'"evidence": ["names of the tools you used"]}';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return InvestigationResult
     */
    private function loop(string $scenario, int $tick, array $input): array
    {
        $box = new ToolBox($scenario, $tick);
        $tools = ToolBox::definitions();
        $messages = [['role' => 'user', 'content' => "Risk result:\n".json_encode($input, JSON_UNESCAPED_SLASHES)]];
        $trace = [];
        $used = [];
        $numbers = self::numbers((string) json_encode($input));
        $tokens = ['input' => 0, 'output' => 0];
        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $whatIfCalled = false;
        $nudged = false;
        $bestFix = null;
        $timeResults = [];
        $projection = null;

        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            $remaining = (int) floor($deadline - microtime(true));
            if ($remaining < 2) {
                return $this->finish(null, 'timeout', $trace, null);
            }

            $reply = $this->ai->converse(
                $this->system(),
                $messages,
                $tools,
                self::MAX_TOKENS,
                // On the last round the model must answer, not call another tool.
                $round === self::MAX_ROUNDS ? 'none' : 'auto',
                $remaining,
            );
            if ($reply === null) {
                return $this->finish(null, $this->ai->failure() ?? 'api_error', $trace, null);
            }
            $tokens['input'] += $reply['input_tokens'];
            $tokens['output'] += $reply['output_tokens'];

            if ($reply['stop_reason'] === 'tool_use') {
                // Pass the assistant turn back exactly as received.
                $messages[] = ['role' => 'assistant', 'content' => $reply['content']];
                $results = [];
                foreach ($reply['content'] as $block) {
                    if (($block['type'] ?? null) !== 'tool_use') {
                        continue;
                    }
                    $name = is_string($block['name'] ?? null) ? $block['name'] : '';
                    $args = is_array($block['input'] ?? null) ? $block['input'] : [];
                    try {
                        $out = $box->run($name, $args);
                        $used[$name] = true;
                        $kept = $out['result'];
                        if ($name === 'time_to_limit') {
                            $timeResults[] = $out['result'];
                        }
                        if ($name === 'get_learned_estimate' && is_array($kept['projection'] ?? null)) {
                            $projection = $kept['projection'];
                            // A projected crossing is a time estimate too, so it gets the same caveat.
                            foreach (['first_reading_at_20_percent', 'first_reading_at_50_percent'] as $field) {
                                $k = $kept['projection'][$field] ?? null;
                                if (is_int($k)) {
                                    $timeResults[] = ['status' => 'rising', 'readings_to_limit' => $k, 'minutes_to_limit' => $k * ToolBox::MINUTES_PER_READING];
                                }
                            }
                        }
                        if ($name === 'what_if') {
                            $whatIfCalled = true;
                            if (($kept['effect'] ?? null) === 'lowers_score'
                                && ($bestFix === null || $kept['score_if_changed'] < $bestFix['score_if_changed'])) {
                                $bestFix = $kept;
                            }
                            // A value that did not lower the score is not a tested fix,
                            // so the answer may not recommend it as a target.
                            if (($kept['effect'] ?? null) !== 'lowers_score') {
                                unset($kept['tried_value']);
                            }
                        }
                        $numbers = array_merge($numbers, self::numbers((string) json_encode($kept)));
                        $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'] ?? '', 'content' => json_encode($out['result'])];
                        $trace[] = ['tool' => $name, 'summary' => $out['summary'], 'ok' => true];
                    } catch (\InvalidArgumentException $e) {
                        $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'] ?? '', 'content' => $e->getMessage(), 'is_error' => true];
                        $trace[] = ['tool' => $name, 'summary' => 'A tool call was refused: '.$e->getMessage(), 'ok' => false];
                    }
                }
                if ($results === []) {
                    $this->lastDetail = 'tool_use reply held no tool call';

                    return $this->finish(null, 'bad_reply', $trace, null);
                }
                $messages[] = ['role' => 'user', 'content' => $results];

                continue;
            }

            // The gate: ask once for a what_if before the answer, while rounds remain
            // for the call and the final answer.
            if (! $whatIfCalled && ! $nudged && $round <= self::MAX_ROUNDS - 2) {
                $nudged = true;
                $messages[] = ['role' => 'assistant', 'content' => $reply['content']];
                $messages[] = ['role' => 'user', 'content' => 'Before you answer, call what_if once on the channel that drives your top check, with a value that moves it away from the failure zone. Then answer again. If no what_if lowers the score, say no tested change is known.'];

                continue;
            }

            $text = AiClient::firstText(['content' => $reply['content']]);
            $parsed = $text === null ? null : $this->parse($text, array_keys($used), $numbers);
            if ($text === null) {
                $this->lastDetail = 'final reply held no text';
            }
            if (! is_array($parsed)) {
                // A reply cut off at the token limit shows up here as no text or half a JSON object.
                $this->lastDetail = trim(($this->lastDetail ?? '').' (stop_reason '.($reply['stop_reason'] ?? 'unknown').')');

                return $this->finish(null, $parsed === null ? 'bad_reply' : 'ungrounded', $trace, null);
            }

            $parsed['cause'] = self::addProjectionNote($parsed['cause'], $projection);
            $parsed['cause'] = self::addCaveat($parsed['cause'], $timeResults);
            $parsed['tested_fix'] = $bestFix === null ? null : self::testedFix($bestFix);

            return $this->finish($parsed, null, $trace, $tokens);
        }

        $this->lastDetail = 'no final answer within '.self::MAX_ROUNDS.' rounds';

        return $this->finish(null, 'bad_reply', $trace, null);
    }

    /**
     * @param  list<string>  $usedTools
     * @param  list<float>  $numbers
     * @return array{cause: string, checks: list<string>, evidence: list<string>}|false|null null for an unusable reply, false for an ungrounded one
     */
    private function parse(string $text, array $usedTools, array $numbers): array|false|null
    {
        $json = AiStep::decodeJson($text);
        if ($json === null) {
            $this->lastDetail = 'reply held no readable JSON object';

            return null;
        }
        $cause = AiStep::cleanString($json['cause'] ?? null, 400);
        $checks = AiStep::cleanList($json['checks'] ?? null, 1, 2, 160);
        $evidence = AiStep::cleanList($json['evidence'] ?? null, 1, 5, 40);
        if ($cause === null || $checks === null || $evidence === null) {
            $rawCause = $json['cause'] ?? null;
            $this->lastDetail = sprintf(
                'unusable field(s): %s%s',
                implode(', ', array_keys(array_filter(['cause' => $cause === null, 'checks' => $checks === null, 'evidence' => $evidence === null]))),
                is_string($rawCause) ? ' (cause length '.mb_strlen($rawCause).', limit 400)' : '',
            );

            return null;
        }

        // The answer must name only tools it called, and name at least one.
        foreach ($evidence as $name) {
            if (! in_array($name, $usedTools, true)) {
                $this->lastDetail = 'evidence names a tool that was not called: '.$name;

                return false;
            }
        }

        // Every number in the words must come from the input or a tool result.
        foreach (self::numbers($cause.' '.implode(' ', $checks)) as $n) {
            if (! self::grounded($n, $numbers)) {
                $this->lastDetail = 'number not found in input or tool results: '.$n;

                return false;
            }
        }

        return ['cause' => $cause, 'checks' => $checks, 'evidence' => $evidence];
    }

    /**
     * Code adds the caveat when the cause quotes a time estimate without it.
     *
     * @param  list<array<string, mixed>>  $timeResults  Results of the time_to_limit calls.
     */
    public static function addCaveat(string $cause, array $timeResults): string
    {
        if (stripos($cause, 'straight') !== false) {
            return $cause;
        }
        foreach ($timeResults as $r) {
            if (($r['status'] ?? null) !== 'rising') {
                continue;
            }
            $minutes = $r['minutes_to_limit'] ?? null;
            $candidates = [
                [$r['readings_to_limit'] ?? null, 'readings?'],
                [$minutes, 'minutes?|mins?'],
                [is_numeric($minutes) ? (float) $minutes / 60 : null, 'hours?|hrs?'],
            ];
            foreach ($candidates as [$n, $unit]) {
                if ($n !== null && self::quotesWithUnit($cause, (float) $n, $unit)) {
                    return $cause.' This is a straight-line estimate on simulated data.';
                }
            }
        }

        return $cause;
    }

    /**
     * Code adds the projection when the cause says the model disagrees with the
     * band but the model projects the same problem soon. Without it the cause
     * reads as if the model sees no problem.
     *
     * @param  array<mixed>|null  $projection  The projection from the learned estimate tool.
     */
    public static function addProjectionNote(string $cause, ?array $projection): string
    {
        if ($projection === null || preg_match('/\bdisagree/i', $cause) !== 1) {
            return $cause;
        }
        foreach (['first_reading_at_20_percent' => 20, 'first_reading_at_50_percent' => 50] as $field => $percent) {
            $readings = $projection[$field] ?? null;
            if (! is_int($readings)) {
                continue;
            }
            if (self::quotesWithUnit($cause, (float) $readings, 'readings?')) {
                return $cause;
            }

            return rtrim($cause).sprintf(' The learned model projects %d%% in about %d readings.', $percent, $readings);
        }

        return $cause;
    }

    /**
     * True when the text writes the number followed by a time unit, such as
     * "4 readings" or "150 minutes". A bare "4%" is not a time estimate.
     */
    private static function quotesWithUnit(string $text, float $n, string $unitPattern): bool
    {
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(?:'.$unitPattern.')\b/i', $text, $m) < 1) {
            return false;
        }
        foreach ($m[1] as $found) {
            if (abs((float) $found - $n) <= max(0.05, 0.005 * abs($n))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tested fix as a sentence code writes from a what_if result. The model
     * chose the value, and the rules scored it on a copy of the reading.
     *
     * @param  array<string, mixed>  $r
     * @return array{channel: string, value: float|int, score_from: int, score_to: int, text: string}
     */
    public static function testedFix(array $r): array
    {
        [$label, $unit] = ToolBox::CHANNEL_LABELS[$r['channel']];
        $value = rtrim(rtrim(number_format((float) $r['tried_value'], 2, '.', ','), '0'), '.');

        $learned = '';
        if (isset($r['learned_chance_percent_now'], $r['learned_chance_percent_if_changed'])) {
            $now = self::percent((float) $r['learned_chance_percent_now']);
            $then = self::percent((float) $r['learned_chance_percent_if_changed']);
            $learned = match (true) {
                $now === $then => sprintf(' The learned estimate of failure stays at %s%%.', $now),
                (float) $r['learned_chance_percent_if_changed'] - (float) $r['learned_chance_percent_now'] > 0 && (float) $r['learned_chance_percent_if_changed'] - (float) $r['learned_chance_percent_now'] < self::MIN_DISAGREE_POINTS => sprintf(' The learned estimate of failure moves from %s%% to %s%%, a change too small to count.', $now, $then),
                (float) $r['learned_chance_percent_if_changed'] > (float) $r['learned_chance_percent_now'] => sprintf(' The learned estimate of failure moves from %s%% to %s%%, so the rules and the model disagree on this change. Inspect before relying on it.', $now, $then),
                default => sprintf(' The learned estimate of failure moves from %s%% to %s%%.', $now, $then),
            };
        }

        return [
            'channel' => $r['channel'],
            'value' => $r['tried_value'],
            'score_from' => $r['current_score'],
            'score_to' => $r['score_if_changed'],
            'text' => sprintf(
                'PRISM scored a copy of the reading with %s set to %s %s. The score falls from %d to %d (%s).%s This is a test only. It changes nothing on the machine.',
                strtolower($label),
                $value,
                $unit,
                $r['current_score'],
                $r['score_if_changed'],
                $r['band_if_changed'],
                $learned,
            ),
        ];
    }

    private static function percent(float $p): string
    {
        return rtrim(rtrim(number_format($p, 1, '.', ','), '0'), '.');
    }

    /** @return list<float> */
    private static function numbers(string $text): array
    {
        // "1,400" is one number, not 1 and 400.
        preg_match_all('/\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?/', $text, $m);

        return array_map(fn (string $n) => (float) str_replace(',', '', $n), $m[0]);
    }

    /** @param  list<float>  $source */
    private static function grounded(float $n, array $source): bool
    {
        $whole = floor($n) == $n;
        foreach ($source as $s) {
            // Allow rounding to a whole number, and minutes written as hours.
            foreach ([$s, $s / 60] as $candidate) {
                $tolerance = max($whole ? 0.5 : 0.05, 0.005 * abs($candidate));
                if (abs($n - $candidate) <= $tolerance) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{cause: string, checks: list<string>, evidence: list<string>, tested_fix?: array{channel: string, value: float|int, score_from: int, score_to: int, text: string}|null}|null  $data
     * @param  list<array{tool: string, summary: string, ok: bool}>  $trace
     * @param  array{input: int, output: int}|null  $tokens
     * @return InvestigationResult
     */
    private function finish(?array $data, ?string $reason, array $trace, ?array $tokens): array
    {
        return [
            'source' => $data === null ? 'fallback' : 'ai',
            'data' => $data,
            'tokens' => $tokens,
            'reason' => $data === null ? $reason : null,
            'trace' => $trace,
        ];
    }

    /**
     * @param  InvestigationResult  $result
     * @return InvestigationResult
     */
    private function log(array $result): array
    {
        Log::info('ai_step', [
            'step' => 'investigate',
            'source' => $result['source'],
            'reason' => $result['reason'],
            'tool_calls' => count($result['trace']),
            'detail' => $this->lastDetail,
            'input_tokens' => $result['tokens']['input'] ?? null,
            'output_tokens' => $result['tokens']['output'] ?? null,
        ]);

        return $result;
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param  array<string, mixed>  $value */
    private function cachePut(string $key, array $value, int $seconds): void
    {
        try {
            Cache::put($key, $value, $seconds);
        } catch (\Throwable) {
            // A broken cache must not break the page.
        }
    }
}
