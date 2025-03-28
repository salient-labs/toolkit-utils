<?php declare(strict_types=1);

namespace Salient\Utility;

/**
 * Get data from the call stack
 *
 * @api
 */
final class Debug extends AbstractUtility
{
    /**
     * Get a description of the (caller's) caller from debug_backtrace()
     *
     * Returns an associative array with zero or more of the following values.
     * Separators are added as needed to allow concatenation to a caller string.
     *
     * - `namespace`
     * - `class`
     * - `file`
     * - `function`
     * - `line`
     *
     * For an earlier frame in the call stack, set `$depth` to `1` or higher.
     *
     * @return array{namespace?:string,class?:string,file?:string,0?:string,function?:string,1?:string,line?:int}
     */
    public static function getCaller(int $depth = 0): array
    {
        // 0. called us (function = getCaller)
        // 1. called them (function = ourCaller)
        // 2. used the name of their caller (function = callsOurCaller)
        //
        // Use namespace, class and function from 2 if possible, otherwise file
        // and line from 1
        $frames = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, $depth + 3);
        $file = $frames[$depth + 1]['file'] ?? null;
        $line = $frames[$depth + 1]['line'] ?? null;
        $beforeLine = $line !== null ? ':' : null;

        if (isset($frames[$depth + 2]['function'])) {
            $frame = $frames[$depth + 2];
            // - PHP 8.4: `{closure:Salient\Tests\Utility\Debug\GetCallerClass::getCallback():31}`
            // - Earlier: `Salient\Tests\Utility\Debug\{closure}`
            if (Str::startsWith($frame['function'], '{closure:')) {
                $closure = implode(':', array_slice(explode(':', $frame['function']), 1, -1));
            }
            if (isset($frame['class'])) {
                $namespace = Get::namespace($frame['class']);
                $class = Get::basename($frame['class']);
            } else {
                $namespace = Get::namespace($closure ?? $frame['function']);
                $class = '';
            }
            // `function` and `class` are both namespaced for closures in
            // namespaced classes
            $function = isset($closure)
                ? '{closure}'
                : Get::basename($frame['function']);
            if ($namespace !== '') {
                $namespace .= '\\';
            }
            if ($class !== '' || $namespace !== '') {
                $file = null;
            }
            return Arr::whereNotEmpty([
                'namespace' => $namespace,
                'class' => $class,
                'file' => $file,
                $frame['type'] ?? ($file !== null ? '::' : null),
                'function' => $function,
                $beforeLine,
                'line' => $line,
            ]);
        }

        if (isset($frames[$depth + 1])) {
            return Arr::whereNotEmpty([
                'file' => $file,
                $beforeLine,
                'line' => $line,
            ]);
        }

        return [];
    }
}
