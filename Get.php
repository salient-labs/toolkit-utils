<?php declare(strict_types=1);

namespace Salient\Utility;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use Salient\Contract\Container\SingletonInterface;
use Salient\Contract\Core\Arrayable;
use Salient\Utility\Exception\InvalidArgumentTypeException;
use Salient\Utility\Exception\UncloneableObjectException;
use Closure;
use Countable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionObject;
use Stringable;
use UnitEnum;

/**
 * Get values from other values
 *
 * @api
 */
final class Get extends AbstractUtility
{
    /**
     * Do not throw an exception if an uncloneable object is encountered
     */
    public const COPY_SKIP_UNCLONEABLE = 1;

    /**
     * Assign values to properties by reference
     *
     * Required if an object graph contains nodes with properties passed or
     * assigned by reference.
     */
    public const COPY_BY_REFERENCE = 2;

    /**
     * Take a shallow copy of objects with a __clone method
     */
    public const COPY_TRUST_CLONE = 4;

    /**
     * Copy service containers
     */
    public const COPY_CONTAINERS = 8;

    /**
     * Copy singletons
     */
    public const COPY_SINGLETONS = 16;

    /**
     * Cast a value to boolean, converting boolean strings and preserving null
     *
     * @see Test::isBoolean()
     *
     * @param mixed $value
     * @return ($value is null ? null : bool)
     */
    public static function boolean($value): ?bool
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }

        if (is_string($value) && Regex::match(
            '/^' . Regex::BOOLEAN_STRING . '$/',
            $value,
            $matches,
            \PREG_UNMATCHED_AS_NULL
        )) {
            return $matches['true'] !== null;
        }

        return (bool) $value;
    }

    /**
     * Cast a value to integer, preserving null
     *
     * @param int|float|string|bool|null $value
     * @return ($value is null ? null : int)
     */
    public static function integer($value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Cast a value to the array-key it appears to be, preserving null
     *
     * @param int|string|null $value
     * @return ($value is null ? null : ($value is int ? int : int|string))
     */
    public static function arrayKey($value)
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        // @phpstan-ignore function.alreadyNarrowedType
        if (!is_string($value)) {
            throw new InvalidArgumentTypeException(1, 'value', 'int|string|null', $value);
        }

        if (Regex::match('/^' . Regex::INTEGER_STRING . '$/', $value)) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Convert a callable to a closure
     *
     * @return ($callable is null ? null : Closure)
     */
    public static function closure(?callable $callable): ?Closure
    {
        return $callable === null
            ? null
            : ($callable instanceof Closure
                ? $callable
                : Closure::fromCallable($callable));
    }

    /**
     * Resolve a closure to its return value
     *
     * @template T
     * @template TArg
     *
     * @param T|Closure(TArg...): T $value
     * @param TArg ...$args Passed to `$value` if it is a closure.
     * @return T
     */
    public static function value($value, ...$args)
    {
        if ($value instanceof Closure) {
            return $value(...$args);
        }
        return $value;
    }

    /**
     * Convert "key[=value]" pairs to an associative array
     *
     * @param string[] $values
     * @return mixed[]
     */
    public static function filter(array $values, bool $discardInvalid = false): array
    {
        $valid = Regex::grep('/^[^ .=]++/', $values);
        if (!$discardInvalid && $valid !== $values) {
            $invalid = array_diff($values, $valid);
            throw new InvalidArgumentException(Inflect::format(
                $invalid,
                "Invalid key-value {{#:pair}}: '%s'",
                implode("', '", $invalid),
            ));
        }

        /** @var int|null */
        static $maxInputVars;

        $maxInputVars ??= (int) ini_get('max_input_vars');
        if (count($valid) > $maxInputVars) {
            throw new InvalidArgumentException(sprintf(
                'Key-value pairs exceed max_input_vars (%d)',
                $maxInputVars,
            ));
        }

        $values = Regex::replaceCallback(
            '/^([^=]++)(?:=(.++))?/s',
            fn($matches) =>
                rawurlencode((string) $matches[1])
                . ($matches[2] === null
                    ? ''
                    : '=' . rawurlencode($matches[2])),
            $valid,
            -1,
            $count,
            \PREG_UNMATCHED_AS_NULL,
        );

        $query = [];
        parse_str(implode('&', $values), $query);
        return $query;
    }

    /**
     * Get the first value that is not null, or return the last value
     *
     * @template T
     *
     * @param T|null ...$values
     * @return T|null
     */
    public static function coalesce(...$values)
    {
        $value = null;
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            return $value;
        }
        return $value;
    }

    /**
     * Resolve a value to an array
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param Arrayable<TKey,TValue>|iterable<TKey,TValue> $value
     * @return array<TKey,TValue>
     */
    public static function array($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof Arrayable) {
            return $value->toArray();
        }
        return iterator_to_array($value);
    }

    /**
     * Resolve a value to a list
     *
     * @template TValue
     *
     * @param Arrayable<array-key,TValue>|iterable<TValue> $value
     * @return list<TValue>
     */
    public static function list($value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if ($value instanceof Arrayable) {
            return array_values($value->toArray());
        }
        return iterator_to_array($value, false);
    }

    /**
     * Resolve a value to an item count
     *
     * @param Arrayable<array-key,mixed>|iterable<array-key,mixed>|Countable|int $value
     */
    public static function count($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_array($value) || $value instanceof Countable) {
            return count($value);
        }
        if ($value instanceof Arrayable) {
            return count($value->toArray());
        }
        return iterator_count($value);
    }

    /**
     * Get the unqualified name of a class, optionally removing a suffix
     *
     * Only the first matching `$suffix` is removed, so longer suffixes should
     * be given first.
     */
    public static function basename(string $class, string ...$suffix): string
    {
        /** @var string */
        $class = strrchr('\\' . $class, '\\');
        $class = substr($class, 1);

        if (!$suffix) {
            return $class;
        }

        foreach ($suffix as $suffix) {
            if ($suffix === $class) {
                continue;
            }
            $length = strlen($suffix);
            if (substr($class, -$length) === $suffix) {
                return substr($class, 0, -$length);
            }
        }

        return $class;
    }

    /**
     * Get the namespace of a class
     */
    public static function namespace(string $class): string
    {
        $length = strrpos('\\' . $class, '\\') - 1;

        return $length < 1
            ? ''
            : trim(substr($class, 0, $length), '\\');
    }

    /**
     * Normalise a class name for comparison
     *
     * @template T of object
     *
     * @param class-string<T> $class
     * @return class-string<T>
     */
    public static function fqcn(string $class): string
    {
        /** @var class-string<T> */
        return Str::lower(ltrim($class, '\\'));
    }

    /**
     * Get a UUID in raw binary form
     *
     * If `$uuid` is not given, an \[RFC4122]-compliant UUID is generated.
     *
     * @throws InvalidArgumentException if an invalid UUID is given.
     */
    public static function binaryUuid(?string $uuid = null): string
    {
        return $uuid === null
            ? self::getUuid(true)
            : self::normaliseUuid($uuid, true);
    }

    /**
     * Get a UUID in hexadecimal form
     *
     * If `$uuid` is not given, an \[RFC4122]-compliant UUID is generated.
     *
     * @throws InvalidArgumentException if an invalid UUID is given.
     */
    public static function uuid(?string $uuid = null): string
    {
        return $uuid === null
            ? self::getUuid(false)
            : self::normaliseUuid($uuid, false);
    }

    private static function getUuid(bool $binary): string
    {
        $uuid = [
            random_bytes(4),
            random_bytes(2),
            // Version 4 (most significant 4 bits = 0b0100)
            chr(random_int(0, 0xF) | 0x40) . random_bytes(1),
            // Variant 1 (most significant 2 bits = 0b10)
            chr(random_int(0, 0x3F) | 0x80) . random_bytes(1),
            random_bytes(6),
        ];

        if ($binary) {
            return implode('', $uuid);
        }

        foreach ($uuid as $bin) {
            $hex[] = bin2hex($bin);
        }

        return implode('-', $hex);
    }

    private static function normaliseUuid(string $uuid, bool $binary): string
    {
        $length = strlen($uuid);

        if ($length !== 16) {
            $uuid = str_replace('-', '', $uuid);

            if (!Regex::match('/^[0-9a-f]{32}$/i', $uuid)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid UUID: %s',
                    $uuid,
                ));
            }

            if ($binary) {
                /** @var string */
                return hex2bin($uuid);
            }

            $uuid = Str::lower($uuid);

            return implode('-', [
                substr($uuid, 0, 8),
                substr($uuid, 8, 4),
                substr($uuid, 12, 4),
                substr($uuid, 16, 4),
                substr($uuid, 20, 12),
            ]);
        }

        if ($binary) {
            return $uuid;
        }

        $uuid = [
            substr($uuid, 0, 4),
            substr($uuid, 4, 2),
            substr($uuid, 6, 2),
            substr($uuid, 8, 2),
            substr($uuid, 10, 6),
        ];

        foreach ($uuid as $bin) {
            $hex[] = bin2hex($bin);
        }

        return implode('-', $hex);
    }

    /**
     * Get a sequence of random characters
     */
    public static function randomText(int $length, string $chars = Str::ALPHANUMERIC): string
    {
        if ($chars === '') {
            throw new InvalidArgumentException('Argument #1 ($chars) must be a non-empty string');
        }
        $max = strlen($chars) - 1;
        $text = '';
        for ($i = 0; $i < $length; $i++) {
            $text .= $chars[random_int(0, $max)];
        }
        return $text;
    }

    /**
     * Get the hash of a value in raw binary form
     *
     * @param int|float|string|bool|Stringable|null $value
     */
    public static function binaryHash($value): string
    {
        // xxHash isn't supported until PHP 8.1, so MD5 is the best fit
        return hash('md5', (string) $value, true);
    }

    /**
     * Get the hash of a value in hexadecimal form
     *
     * @param int|float|string|bool|Stringable|null $value
     */
    public static function hash($value): string
    {
        return hash('md5', (string) $value);
    }

    /**
     * Get the type of a variable
     *
     * @param mixed $value
     */
    public static function type($value): string
    {
        if (is_object($value)) {
            return (new ReflectionClass($value))->isAnonymous()
                ? 'class@anonymous'
                : get_class($value);
        }

        if (is_resource($value)) {
            return sprintf('resource (%s)', get_resource_type($value));
        }

        $type = gettype($value);
        return [
            'boolean' => 'bool',
            'integer' => 'int',
            'double' => 'float',
            'NULL' => 'null',
        ][$type] ?? $type;
    }

    /**
     * Get php.ini values like "128M" in bytes
     *
     * From the PHP FAQ: "The available options are K (for Kilobytes), M (for
     * Megabytes) and G (for Gigabytes), and are all case-insensitive. Anything
     * else assumes bytes. 1M equals one Megabyte or 1048576 bytes. 1K equals
     * one Kilobyte or 1024 bytes."
     */
    public static function bytes(string $size): int
    {
        // PHP is very forgiving with the syntax of these values
        $size = rtrim($size);
        $exp = [
            'K' => 1,
            'k' => 1,
            'M' => 2,
            'm' => 2,
            'G' => 3,
            'g' => 3,
        ][$size[-1] ?? ''] ?? 0;
        return (int) $size * 1024 ** $exp;
    }

    /**
     * Convert a value to PHP code
     *
     * Similar to {@see var_export()}, but with more economical output.
     *
     * @param mixed $value
     * @param string[] $classes Strings found in this array are output as
     * `<string>::class` instead of `'<string>'`.
     * @param array<non-empty-string,string> $constants An array that maps
     * strings to constant identifiers, e.g. `[\PHP_EOL => '\PHP_EOL']`.
     */
    public static function code(
        $value,
        string $delimiter = ', ',
        string $arrow = ' => ',
        ?string $escapeCharacters = null,
        string $tab = '    ',
        array $classes = [],
        array $constants = []
    ): string {
        $eol = (string) self::eol($delimiter);
        $multiline = (bool) $eol;
        $escapeRegex = null;
        $search = [];
        $replace = [];
        if ($escapeCharacters !== null && $escapeCharacters !== '') {
            $escapeRegex = Regex::quoteCharacterClass($escapeCharacters, '/');
            foreach (str_split($escapeCharacters) as $character) {
                $search[] = sprintf(
                    '/((?<!\\\\)(?:\\\\\\\\)*)%s/',
                    preg_quote(addcslashes($character, $character), '/'),
                );
                $replace[] = sprintf('$1\x%02x', ord($character));
            }
        }
        $classes = Arr::toIndex($classes);
        $constRegex = [];
        if ($constants) {
            uksort($constants, fn($a, $b) => strlen($b) <=> strlen($a));
            foreach (array_keys($constants) as $string) {
                $constRegex[] = preg_quote($string, '/');
            }
        }
        switch (count($constRegex)) {
            case 0:
                $constRegex = null;
                break;
            case 1:
                $constRegex = '/' . $constRegex[0] . '/';
                break;
            default:
                $constRegex = '/(?:' . implode('|', $constRegex) . ')/';
                break;
        }
        return self::doCode(
            $value,
            $delimiter,
            $arrow,
            $escapeCharacters,
            $escapeRegex,
            $search,
            $replace,
            $tab,
            $classes,
            $constants,
            $constRegex,
            $multiline,
            $eol,
        );
    }

    /**
     * @param mixed $value
     * @param string[] $search
     * @param string[] $replace
     * @param array<string,true> $classes
     * @param array<non-empty-string,string> $constants
     */
    private static function doCode(
        $value,
        string $delimiter,
        string $arrow,
        ?string $escapeCharacters,
        ?string $escapeRegex,
        array $search,
        array $replace,
        string $tab,
        array $classes,
        array $constants,
        ?string $regex,
        bool $multiline,
        string $eol,
        string $indent = ''
    ): string {
        if ($value === null) {
            return 'null';
        }

        if (is_string($value)) {
            if ($classes && isset($classes[$value])) {
                return $value . '::class';
            }

            if ($regex !== null) {
                $parts = [];
                while (Regex::match($regex, $value, $matches, \PREG_OFFSET_CAPTURE)) {
                    if ($matches[0][1] > 0) {
                        $parts[] = substr($value, 0, $matches[0][1]);
                    }
                    $parts[] = $matches[0][0];
                    $value = substr($value, $matches[0][1] + strlen($matches[0][0]));
                }
                if ($parts) {
                    if ($value !== '') {
                        $parts[] = $value;
                    }
                    foreach ($parts as &$part) {
                        $part = $constants[$part]
                            ?? self::doCode($part, $delimiter, $arrow, $escapeCharacters, $escapeRegex, $search, $replace, $tab, $classes, [], null, $multiline, $eol, $indent);
                    }
                    return implode(' . ', $parts);
                }
            }

            if ($multiline) {
                $escape = '';
                $match = '';
            } else {
                $escape = "\n\r";
                $match = '\n\r';
            }

            // Don't escape UTF-8 leading bytes (\xc2 -> \xf4) or continuation
            // bytes (\x80 -> \xbf)
            if (mb_check_encoding($value, 'UTF-8')) {
                $escape .= "\x7f\xc0\xc1\xf5..\xff";
                $match .= '\x7f\xc0\xc1\xf5-\xff';
                $utf8 = true;
            } else {
                $escape .= "\x7f..\xff";
                $match .= '\x7f-\xff';
                $utf8 = false;
            }

            // Escape strings that contain characters in `$escape` or
            // `$escapeCharacters`
            if (Regex::match("/[\\x00-\\x09\\x0b\\x0c\\x0e-\\x1f{$match}{$escapeRegex}]/", $value)) {
                // \0..\t\v\f\x0e..\x1f = \0..\x1f without \n and \r
                $escaped = addcslashes(
                    $value,
                    "\0..\t\v\f\x0e..\x1f\"\$\\" . $escape . $escapeCharacters
                );

                // Convert blank/ignorable code points to "\u{xxxx}" unless they
                // belong to a recognised Unicode sequence
                if ($utf8) {
                    $escaped = Regex::replaceCallback(
                        '/(?![\x00-\x7f])\X/u',
                        fn(array $matches): string =>
                            Regex::match('/^' . Regex::INVISIBLE_CHAR . '$/u', $matches[0])
                                ? sprintf('\u{%04X}', mb_ord($matches[0]))
                                : $matches[0],
                        $escaped,
                    );
                }

                // Replace characters in `$escapeCharacters` with the equivalent
                // hexadecimal escape
                if ($search) {
                    $escaped = Regex::replace($search, $replace, $escaped);
                }

                // Convert octal notation to hex (e.g. "\177" to "\x7f") and
                // correct for differences between C and PHP escape sequences:
                // - recognised by PHP: \0 \e \f \n \r \t \v
                // - applied by addcslashes: \000 \033 \a \b \f \n \r \t \v
                $escaped = Regex::replaceCallback(
                    '/((?<!\\\\)(?:\\\\\\\\)*)\\\\(?:(?<NUL>000(?![0-7]))|(?<octal>[0-7]{3})|(?<cslash>[ab]))/',
                    fn(array $matches): string =>
                        $matches[1]
                        . ($matches['NUL'] !== null
                            ? '\0'
                            : ($matches['octal'] !== null
                                ? (($dec = octdec($matches['octal'])) === 27
                                    ? '\e'
                                    : sprintf('\x%02x', $dec))
                                : sprintf('\x%02x', ['a' => 7, 'b' => 8][$matches['cslash']]))),
                    $escaped,
                    -1,
                    $count,
                    \PREG_UNMATCHED_AS_NULL,
                );

                // Remove unnecessary backslashes
                $escaped = Regex::replace(
                    '/(?<!\\\\)\\\\\\\\(?![nrtvef\\\\$"]|[0-7]|x[0-9a-fA-F]|u\{[0-9a-fA-F]+\}|$)/',
                    '\\',
                    $escaped
                );

                return '"' . $escaped . '"';
            }
        }

        if (!is_array($value)) {
            $result = var_export($value, true);
            if (is_float($value)) {
                return Str::lower($result);
            }
            return $result;
        }

        if (!$value) {
            return '[]';
        }

        $prefix = '[';
        $suffix = ']';
        $glue = $delimiter;

        if ($multiline) {
            $suffix = $delimiter . $indent . $suffix;
            $indent .= $tab;
            $prefix .= $eol . $indent;
            $glue .= $indent;
        }

        $isList = Arr::isList($value);
        if (!$isList) {
            $isMixedList = false;
            $keys = 0;
            foreach (array_keys($value) as $key) {
                if (!is_int($key)) {
                    continue;
                }
                if ($keys++ !== $key) {
                    $isMixedList = false;
                    break;
                }
                $isMixedList = true;
            }
        }
        foreach ($value as $key => $value) {
            $value = self::doCode($value, $delimiter, $arrow, $escapeCharacters, $escapeRegex, $search, $replace, $tab, $classes, $constants, $regex, $multiline, $eol, $indent);
            if ($isList || ($isMixedList && is_int($key))) {
                $values[] = $value;
                continue;
            }
            $key = self::doCode($key, $delimiter, $arrow, $escapeCharacters, $escapeRegex, $search, $replace, $tab, $classes, $constants, $regex, $multiline, $eol, $indent);
            $values[] = $key . $arrow . $value;
        }

        return $prefix . implode($glue, $values) . $suffix;
    }

    /**
     * Get the end-of-line sequence used in a string
     *
     * Recognised line endings are LF (`"\n"`), CRLF (`"\r\n"`) and CR (`"\r"`).
     *
     * @see File::getEol()
     * @see Str::setEol()
     *
     * @return non-empty-string|null `null` if there are no recognised newline
     * characters in `$string`.
     */
    public static function eol(string $string): ?string
    {
        $lfPos = strpos($string, "\n");

        if ($lfPos === false) {
            return strpos($string, "\r") === false
                ? null
                : "\r";
        }

        if ($lfPos && $string[$lfPos - 1] === "\r") {
            return "\r\n";
        }

        return "\n";
    }

    /**
     * Get a deep copy of a value
     *
     * @template T
     *
     * @param T $value
     * @param class-string[]|(Closure(object): (object|bool)) $skip A list of
     * classes to skip, or a closure that returns:
     * - `true` if the object should be skipped
     * - `false` if the object should be copied normally, or
     * - a copy of the object
     * @param int-mask-of<Get::COPY_*> $flags
     * @return T
     */
    public static function copy(
        $value,
        $skip = [],
        int $flags = Get::COPY_SKIP_UNCLONEABLE | Get::COPY_BY_REFERENCE
    ) {
        return self::doCopy($value, $skip, $flags);
    }

    /**
     * @template T
     *
     * @param T $var
     * @param class-string[]|(Closure(object): (object|bool)) $skip
     * @param int-mask-of<Get::COPY_*> $flags
     * @param array<int,object> $map
     * @return T
     */
    private static function doCopy(
        $var,
        $skip,
        int $flags,
        array &$map = []
    ) {
        if (is_resource($var)) {
            return $var;
        }

        if (is_array($var)) {
            foreach ($var as $key => $value) {
                $array[$key] = self::doCopy($value, $skip, $flags, $map);
            }
            // @phpstan-ignore return.type
            return $array ?? [];
        }

        if (!is_object($var) || $var instanceof UnitEnum) {
            return $var;
        }

        $id = spl_object_id($var);
        if (isset($map[$id])) {
            // @phpstan-ignore return.type
            return $map[$id];
        }

        if ((
            !($flags & self::COPY_CONTAINERS)
            && $var instanceof PsrContainerInterface
        ) || (
            !($flags & self::COPY_SINGLETONS)
            && $var instanceof SingletonInterface
        )) {
            $map[$id] = $var;
            return $var;
        }

        if ($skip instanceof Closure) {
            if (($result = $skip($var)) !== false) {
                if ($result === true) {
                    $map[$id] = $var;
                    return $var;
                }
                if (
                    // @phpstan-ignore function.alreadyNarrowedType
                    !is_object($result)
                    || get_class($result) !== get_class($var)
                ) {
                    throw new LogicException(sprintf(
                        '$skip returned %s (%s|bool expected)',
                        self::type($result),
                        get_class($var),
                    ));
                }
                $map[$id] = $result;
                $id = spl_object_id($result);
                $map[$id] = $result;
                // @phpstan-ignore return.type
                return $result;
            }
        } else {
            foreach ($skip as $class) {
                if (is_a($var, $class)) {
                    $map[$id] = $var;
                    return $var;
                }
            }
        }

        $_var = new ReflectionObject($var);

        if (!$_var->isCloneable()) {
            if ($flags & self::COPY_SKIP_UNCLONEABLE) {
                $map[$id] = $var;
                return $var;
            }

            throw new UncloneableObjectException(
                sprintf('%s cannot be copied', $_var->getName())
            );
        }

        $clone = clone $var;
        $map[$id] = $clone;
        $id = spl_object_id($clone);
        $map[$id] = $clone;

        if (
            $flags & self::COPY_TRUST_CLONE
            && $_var->hasMethod('__clone')
        ) {
            return $clone;
        }

        if (
            $clone instanceof DateTimeInterface
            || $clone instanceof DateTimeZone
        ) {
            return $clone;
        }

        $byRef = (bool) ($flags & self::COPY_BY_REFERENCE)
            && !$_var->isInternal();
        foreach (Reflect::getAllProperties($_var) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $property->setAccessible(true);

            if (!$property->isInitialized($clone)) {
                continue;
            }

            $name = $property->getName();
            $value = $property->getValue($clone);
            $value = self::doCopy($value, $skip, $flags, $map);

            if (
                !$byRef
                || ($declaring = $property->getDeclaringClass())->isInternal()
            ) {
                $property->setValue($clone, $value);
                continue;
            }

            (function () use ($name, $value): void {
                // @phpstan-ignore variable.undefined
                $this->$name = &$value;
            })->bindTo($clone, $declaring->getName())();
        }

        return $clone;
    }
}
