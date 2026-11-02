<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Fails when a raw SQL call gets anything other than a fixed string as its SQL.
 *
 * Eloquent and the query builder bind every value, so they are safe. The calls
 * listed here take SQL text as written. A variable, a "." join or a "{$x}"
 * inside that text lets user input become SQL. Pass values as bindings
 * instead: DB::select('select * from users where id = ?', [$id]).
 */
class RawSqlGuardTest extends TestCase
{
    /** Called as DB::name(...). These names are also safe Eloquent methods on a model, so only the DB facade counts. */
    private const DB_FACADE_METHODS = [
        'raw', 'select', 'selectone', 'selectresultsets', 'scalar', 'cursor',
        'insert', 'update', 'delete', 'statement', 'affectingstatement', 'unprepared',
    ];

    /** Called with -> or :: on anything. Each one takes SQL text. */
    private const ANY_RECEIVER_METHODS = [
        'whereraw', 'orwhereraw', 'selectraw', 'orderbyraw', 'havingraw', 'orhavingraw',
        'groupbyraw', 'fromraw', 'exec', 'prepare',
    ];

    /** Folders that hold app code. vendor, storage and tests are left out. */
    private const SCANNED_DIRS = ['app', 'routes', 'database', 'config', 'bootstrap'];

    public function test_app_code_passes_only_fixed_strings_to_raw_sql_calls(): void
    {
        $root = dirname(__DIR__, 2);
        $problems = [];
        $files = 0;

        foreach (self::SCANNED_DIRS as $dir) {
            if (! is_dir("$root/$dir")) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/bootstrap/cache/')) {
                    continue;
                }
                $files++;
                $relative = substr($file->getPathname(), strlen($root) + 1);
                foreach (self::findUnsafeCalls((string) file_get_contents($file->getPathname())) as [$line, $call]) {
                    $problems[] = "$relative:$line $call";
                }
            }
        }

        $this->assertGreaterThan(0, $files, 'The guard found no PHP files to scan.');
        $this->assertSame([], $problems, "Raw SQL built from a non-fixed string. Use ? bindings:\n".implode("\n", $problems));
    }

    public function test_the_guard_catches_string_built_sql(): void
    {
        $unsafe = [
            '<?php DB::select("select * from users where id = " . $id);',
            '<?php DB::select("select * from users where id = $id");',
            '<?php DB::statement($sql);',
            '<?php DB::unprepared(<<<SQL'."\n".'delete from users where id = {$id}'."\n".'SQL);',
            '<?php User::query()->whereRaw(\'id = \' . $request->input(\'id\'))->get();',
            '<?php $q->orderByRaw($request->query(\'sort\'));',
            '<?php DB::table(\'users\')->selectRaw(sprintf(\'count(%s)\', $col));',
            '<?php $pdo->exec(\'delete from t where id = \' . $id);',
            '<?php \Illuminate\Support\Facades\DB::raw($expr);',
        ];

        foreach ($unsafe as $code) {
            $this->assertNotSame([], self::findUnsafeCalls($code), "The guard missed: $code");
        }
    }

    public function test_the_guard_allows_fixed_strings_and_bindings(): void
    {
        $safe = [
            '<?php DB::select(\'select 1\');',
            '<?php DB::select(\'select * from users where id = ?\', [$id]);',
            '<?php DB::select("select * from users where email = :email", [\'email\' => $email]);',
            '<?php User::query()->whereRaw(\'lower(email) = ?\', [$email])->first();',
            '<?php $log->update($data); $log->delete(); User::where(\'id\', $id)->update([\'name\' => $n]);',
            '<?php $email = $request->query(\'email\', \'\');',
            '<?php DB::table($table)->where(\'id\', $row->id)->update([\'uuid\' => $u]);',
        ];

        foreach ($safe as $code) {
            $this->assertSame([], self::findUnsafeCalls($code), "The guard flagged safe code: $code");
        }
    }

    /**
     * @return list<array{0: int, 1: string}> line number and call name for each unsafe call
     */
    private static function findUnsafeCalls(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $found = [];
        $count = count($tokens);

        for ($i = 2; $i < $count - 1; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || $tokens[$i + 1] !== '(') {
                continue;
            }
            $name = strtolower($tokens[$i][1]);
            $operator = $tokens[$i - 1];
            $isCall = is_array($operator) && in_array($operator[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
            if (! $isCall) {
                continue;
            }

            $receiver = $tokens[$i - 2];
            $onDbFacade = $operator[0] === T_DOUBLE_COLON && is_array($receiver)
                && in_array($receiver[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && preg_match('/(^|\\\\)DB$/', $receiver[1]) === 1;

            $checked = in_array($name, self::ANY_RECEIVER_METHODS, true)
                || ($onDbFacade && in_array($name, self::DB_FACADE_METHODS, true));
            if (! $checked) {
                continue;
            }

            // Collect the first argument: every token up to the first top-level "," or ")".
            $first = [];
            $depth = 0;
            for ($j = $i + 2; $j < $count; $j++) {
                $t = $tokens[$j];
                if (in_array($t, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif (in_array($t, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($t === ',' && $depth === 0) {
                    break;
                }
                $first[] = $t;
            }

            // Safe: no argument, or one plain string with nothing interpolated.
            $isFixedString = $first === []
                || (count($first) === 1 && is_array($first[0]) && $first[0][0] === T_CONSTANT_ENCAPSED_STRING);
            if (! $isFixedString) {
                $found[] = [$tokens[$i][2], $tokens[$i][1].'()'];
            }
        }

        return $found;
    }
}
